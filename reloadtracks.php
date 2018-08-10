<html><head></head><body>
<?php
include 'common.php';

function file_is_supported_format($file){
	$supported_formats = array('.mp3', '.m4a', '.ogg', '.opus', '.flac', '.ape', '.wv', '.wav', '.dts');
	foreach($supported_formats as $ext){
		if(str_ends_with($file, $ext)){
			return true;
		}
	}
	return false;
}

function read_file_info($file){
	$mediainfo_output = shell_exec('mediainfo --Output="General;%Performer%\n%Album%\n%Track/Position%\n%Title%" ' . escapeshellarg($file) . ' 2>&1');
	$mediainfo_output_split = preg_split('/\n/', $mediainfo_output);
	$mediainfo_array = array('artist'=>$mediainfo_output_split[0], 'album'=>$mediainfo_output_split[1], 'trackno'=>$mediainfo_output_split[2], 'title'=>$mediainfo_output_split[3]);
	if($mediainfo_array['artist'] === "") $mediainfo_array['artist'] = NULL;
	if($mediainfo_array['album'] === ""){
		$mediainfo_array['album'] = basename(dirname($file));
	}
	if($mediainfo_array['trackno'] === "" || $mediainfo_array['trackno'] === NULL) $mediainfo_array['trackno'] = -1;
	if($mediainfo_array['title'] === "" || $mediainfo_array['title'] === NULL){
		$title = basename($file);
		$title = preg_replace('/(\\.mp3|\\.m4a|\\.ogg|\\.opus|\\.flac|\\.ape|\\.wv|\\.wav|\\.dts)/', '', $title);
		$mediainfo_array['title'] = $title;
	}
	// fix underscores. they mess up the fulltext search
	$mediainfo_array['artist'] = preg_replace('/_/', ' ', $mediainfo_array['artist']);
	$mediainfo_array['album'] = preg_replace('/_/', ' ', $mediainfo_array['album']);
	$mediainfo_array['title'] = preg_replace('/_/', ' ', $mediainfo_array['title']);

	$mediainfo_array['filepath'] = $file;
	return $mediainfo_array;
}

function insert_track_row($insert_stmt, $artist, $album, $trackno, $title, $filepath, $start, $end){
	$insert_stmt->bind_param('ssissdd', $artist, $album, $trackno, $title, $filepath, $start, $end);
	$result = $insert_stmt->execute();
	if(!$result){
		echo "row insert failed" . $filepath . " track " . $trackno . "<br>";
	}
}

function insert_track_row_from_file_info($insert_stmt, $file_info){ //for single track
	insert_track_row($insert_stmt, $file_info['artist'], $file_info['album'], $file_info['trackno'], $file_info['title'], $file_info['filepath'], null, null);
}


function parse_cue($insert_stmt, $chkdupcue_stmt, $file){
	$cuefp = fopen($file, 'r') or die('fopen failed on ' . $file);
	$dir = dirname($file);
	$cueInfo = array();
	$touchedFiles = array();
	$currentTrackInfo = array();
	$lastTrackInfo = null;
	$albumPerformer = '';
	$albumTitle = preg_replace('/\\.cue/', '', basename($file)); //default to cuesheet name
	$lastFile = null;
	$tracksStarted = false;
	$trackForCurrentWav = 0;
	$firstTrack = true;
	$indexTime = 0.0;
	while(($line = fgets($cuefp, 4096)) !== false){
		$line = trim($line);
		$matches = array();
		if(preg_match('/PERFORMER.*"([^"]*)"/i', $line, $matches)){
			if($tracksStarted){
				$currentTrackInfo['PERFORMER'] = $matches[1];
			}else{
				$albumPerformer = $matches[1];
			}
		}else if(preg_match('/TITLE.*"([^"]*)"/i', $line, $matches)){
			if($tracksStarted){
				$currentTrackInfo['TITLE'] = $matches[1];
			}else{
				$albumTitle = $matches[1];
			}
		}else if(preg_match('/FILE.*"([^"]*)"/i', $line, $matches)){
			$lastFile = $dir.'/'.$matches[1];
			$touchedFiles[] = $lastFile;
			$trackForCurrentWav = 0;
		}else if(preg_match('/INDEX 01.*(\d\d):(\d\d):(\d\d)/i', $line, $matches)){
			$indexTime = intval($matches[1])*60 + intval($matches[2]) + intval($matches[3])/75;
			$currentTrackInfo['FILE'] = $lastFile;
			if($trackForCurrentWav > 1){
				$lastTrackInfo['end'] = $indexTime;
				$currentTrackInfo['start'] = $indexTime;
			}else{
				$lastTrackInfo['end'] = null;
				// don't skip pregap of first track in file
				$currentTrackInfo['start'] = null;
			}
			if(!$firstTrack){
				$cueInfo[] = $lastTrackInfo;
				//var_dump($lastTrackInfo);echo '<br>';
			}
			$firstTrack = false;
		}else if(preg_match('/TRACK (\d\d) AUDIO/i', $line, $matches)){
			if(!$firstTrack){
				if(!array_key_exists('PERFORMER', $currentTrackInfo)){
					$currentTrackInfo['PERFORMER'] = $albumPerformer;
				}
				if(!array_key_exists('TITLE', $currentTrackInfo)){
					$currentTrackInfo['TITLE'] = $currentTrackInfo['TRACK'];
				}
			}
			$lastTrackInfo = $currentTrackInfo;
			$currentTrackInfo = array();
			$trackForCurrentWav += 1;
			$currentTrackInfo['TRACK'] = $matches[1];
			$tracksStarted = true;
		}else{
			//echo $line.' UNKNOWN<br>';
		}
	}
	if(!array_key_exists('PERFORMER', $currentTrackInfo)){
		$currentTrackInfo['PERFORMER'] = $albumPerformer;
	}
	if(!array_key_exists('TITLE', $currentTrackInfo)){
		$currentTrackInfo['TITLE'] = $currentTrackInfo['TRACK'];
	}
	$currentTrackInfo['end'] = null;
	$cueInfo[] = $currentTrackInfo;

	// cue file parsing ended. 
	// fix underscores. they mess up the fulltext search
	$albumTitle = preg_replace('/_/', ' ', $albumTitle);
	foreach($cueInfo as $trackInfo){
		$trackInfo['PERFORMER'] = preg_replace('/_/', ' ', $trackInfo['PERFORMER']);
		$trackInfo['TITLE'] = preg_replace('/_/', ' ', $trackInfo['TITLE']);
	}

	// insert all rows now

	foreach($cueInfo as $trackInfo){
		if(file_exists($trackInfo['FILE'])){
			$chkdupcue_stmt->bind_param('si', $trackInfo['FILE'], $trackInfo['TRACK']); 
			$chkdupcue_stmt->bind_result($id, $artist, $album, $trackno, $title);
			$chkdupcue_stmt->execute();
			if($chkdupcue_stmt->fetch()){
				$chkdupcue_stmt->fetch(); //empty the queue
				//echo 'dup ' . $file . ' ' . $trackInfo['TRACK'] . "<br>";
			}else{
			
				insert_track_row($insert_stmt, $trackInfo['PERFORMER'], $albumTitle, $trackInfo['TRACK'], $trackInfo['TITLE'], $trackInfo['FILE'], $trackInfo['start'], $trackInfo['end']);
				echo 'new ' . $file . ' ' . $trackInfo['TRACK'] . "<br>";
			}
		}else{
			echo 'warn ' . $file . ' ' . $trackInfo['TRACK'] . ' file invalid' . "<br>";
		}
	}

	return $touchedFiles;
}

function find_and_index_files($insert_stmt, $chkdup_stmt, $chkdupcue_stmt, $dir){
	if($dh = opendir($dir)){
		$files = array();
		while(false !== ($file = readdir($dh))){
			if(($file !== '.') && ($file !== '..')){
				if(!is_dir($dir.$file)){
					$files[] = $file;
				}else{
					find_and_index_files($insert_stmt, $chkdup_stmt, $chkdupcue_stmt, $dir.$file.'/');
				}
			}
		}
		$cue_touched_files = array();
		foreach($files as $file){ //do cue sheets first
			if(str_ends_with($file, '.cue')){
				$new_cue_touched_files = parse_cue($insert_stmt, $chkdupcue_stmt, $dir.$file);
				foreach($new_cue_touched_files as $touched_file){
					$cue_touched_files[] = $touched_file;
				}
			}
		}
		foreach($files as $file){
			$filepath = $dir.$file;
			if(file_is_supported_format($filepath)){
				if(!in_array($filepath, $cue_touched_files)){
					$chkdup_stmt->bind_param('s', $filepath);
					$chkdup_stmt->bind_result($id, $artist, $album, $trackno, $title);
					$chkdup_stmt->execute();
					if($chkdup_stmt->fetch()){
						$chkdup_stmt->fetch(); //empty the queue
						//echo "dup " . $filepath . "<br>";
					}else{
						$file_info = read_file_info($dir.$file);
						echo "new " . $file_info['filepath'] . "<br>";
						insert_track_row_from_file_info($insert_stmt, $file_info);
					}
				}else{
					//echo "skip " . $filepath . "<br>";
				}
			}
		}
	}
}

function remove_dead($dbcon){
	$dead_ids = '0';
	$all_rows = $dbcon->query("SELECT id,filepath FROM tracks");
	if(!$all_rows) die("db query failed: (" . $dbcon->errno . ") " . $dbcon->error);

	while($row = $all_rows->fetch_array()){
		if(!file_exists($row['filepath'])){
			$dead_ids = $dead_ids . ',' . $row['id'];
			echo 'dead ' . $row['filepath'] . ' (' . $row['id'] . ')<br>';
		}
	}
	$dbcon->query('DELETE FROM tracks WHERE id IN (' . $dead_ids . ')');
}

$dbcon = new mysqli("localhost", MYSQL_ACCOUNT, MYSQL_PASSWORD, DB_NAME);
if($dbcon->connect_errno) die("db connection failed: " . $dbcon->connect_error);

$dbcon->set_charset("utf8");

if (!($insert_stmt = $dbcon->prepare("INSERT INTO tracks(artist, album, trackno, title, filepath, start, end) VALUES (?, ?, ?, ?, ?, ?, ?)"))) {
	die("Prepare failed: (" . $dbcon->errno . ") " . $dbcon->error);
}
if (!($chkdup_stmt = $dbcon->prepare("SELECT `id`, `artist`, `album`, `trackno`, `title` FROM tracks WHERE filepath = (?)"))) {
	die("Prepare failed: (" . $dbcon->errno . ") " . $dbcon->error);
}
if (!($chkdupcue_stmt = $dbcon->prepare("SELECT `id`, `artist`, `album`, `trackno`, `title` FROM tracks WHERE filepath = (?) and trackno = (?)"))) {
	die("Prepare failed: (" . $dbcon->errno . ") " . $dbcon->error);
}

remove_dead($dbcon);
find_and_index_files($insert_stmt, $chkdup_stmt, $chkdupcue_stmt, MUSIC_FOLDER);

$dbcon->close();
?>
</body></html>
