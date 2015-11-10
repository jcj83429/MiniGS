<?php
include 'common.php';

const BUFFER_SIZE = 2097152;

if(!isset($_GET["id"])){
	echo "invalid";
	exit;
}

if(isset($_GET["format"])){
	switch($_GET["format"]){
		case "mp3":
			$fmt_ext = '.mp3';
			$suffix = ".320k.mp3";
			$mime_type = "audio/mpeg";
			$quality_params = " -b:a 320k -vn ";
			break;
		case "ogg":
			$fmt_ext = '.ogg';
			$suffix = ".q8.ogg";
			$mime_type = "audio/ogg";
			$quality_params = " -aq 8 -vn ";
			break;
		case "opus":
			$fmt_ext = '.opus';
			$suffix = ".256k.opus";
			$mime_type = "audio/ogg";
			$quality_params = " -b:a 256k -vn ";
			break;
		default:
			die("invalid");
	}
}else{
	$fmt_ext = '.mp3';
	$suffix = ".320k.mp3";
	$mime_type = "audio/mpeg";
	$quality_params = " -b:a 320k ";
}


if (!file_exists(COMPRESSED_CACHE)) {
    mkdir(COMPRESSED_CACHE, 0777, true);
}

$dbcon = new mysqli("localhost", MYSQL_ACCOUNT, MYSQL_PASSWORD, DB_NAME);
if($dbcon->connect_errno) die("db connection failed: " . $dbcon->connect_error);

$dbcon->set_charset("utf8");

if (!($stmt = $dbcon->prepare("SELECT filepath,start,end FROM tracks WHERE id=(?)"))) {
	die("Prepare failed: (" . $dbcon->errno . ") " . $dbcon->error);
}

$stmt->bind_param('i', $_GET["id"]);
$stmt->execute();
$stmt->bind_result($filepath,$start,$end);

if($stmt->fetch()){
	if(!str_ends_with($filepath, $fmt_ext)){
		// handle cue sheet image cutting
		$cut_params = '';
		if($start !== null){
			$outfile = COMPRESSED_CACHE . substr(md5(dirname($filepath)), 0, 8) . '_' . basename($filepath) . '.ss' . intval($start) . $suffix;
			$cut_params = ' -ss ' . $start;
		}else{
			$outfile = COMPRESSED_CACHE . substr(md5(dirname($filepath)), 0, 8) . '_' . basename($filepath) . $suffix;
		}
		if($end !== null){
			$cut_params = $cut_params . ' -to ' . $end;
		}

		// no high res or surround audio
		$mediainfo_output = shell_exec('mediainfo --Output="Audio;%SamplingRate%\n%Channels%" ' . escapeshellarg($filepath) . ' 2>&1');
		$mediainfo_output_split = preg_split('/\n/', $mediainfo_output);
		$sample_rate = $mediainfo_output_split[0];
		$channel_count = $mediainfo_output_split[1];
		if($sample_rate > 48000){
			$quality_params = $quality_params . " -ar 48000 ";
		}
		if($channel_count > 2){
			$quality_params = $quality_params . " -ac 2 ";
		}

		// encode
		$lockfile = $outfile . '.compressing';

		if(!file_exists($outfile)){
			if(!isset($_GET["prepare"])){
				shell_exec('touch ' . escapeshellarg($lockfile) . ' && ffmpeg -i ' . escapeshellarg($filepath) . $cut_params . $quality_params . escapeshellarg($outfile) . ' ; rm ' . escapeshellarg($lockfile));
			}else{
				pclose(popen('touch ' . escapeshellarg($lockfile) . ' && ffmpeg -i ' . escapeshellarg($filepath) . $cut_params . $quality_params . escapeshellarg($outfile) . ' ; rm ' . escapeshellarg($lockfile) . ' &', 'r'));
			}
		}else if(!isset($_GET["prepare"])){
			// encoding started in another request. block until encoding done
			while(file_exists($lockfile)){
				sleep(1);
			}
		}
		$filepath = $outfile;
	}

	if(!isset($_GET["prepare"])){
		$filesize = filesize($filepath);
		$range_start = 0;
		$range_end = $filesize - 1;
		$range_length = $filesize;
		if (isset($_SERVER['HTTP_RANGE'])) {
			preg_match('/bytes=(\d+)-(\d+)?/', $_SERVER['HTTP_RANGE'], $matches);
			$range_start = intval($matches[1]);
			$range_end = (array_key_exists(2, $matches) ? intval($matches[2]) : $filesize - 1);
			$range_length = $range_end + 1 - $range_start;
			header('HTTP/1.1 206 Partial Content');
			header("Content-Range: bytes $range_start-$range_end/$filesize");
		}

		header('Content-type: ' . $mime_type);
		header('Content-length: ' . $range_length);
		header('Content-disposition: inline; filename="' . str_replace('"', '\\"', str_replace('\\', '\\\\', basename($filepath))) . '"');
		header('Accept-Ranges: bytes');
		header('Expires: '.gmdate('D, d M Y H:i:s \G\M\T', time() + (10 * 24 * 60 * 60))); //cache for 10 days

		$fp = fopen($filepath, 'r');
		fseek($fp, $range_start);
		while ($range_length >= BUFFER_SIZE){
			print(fread($fp, BUFFER_SIZE));
			$range_length -= BUFFER_SIZE;
		}
		if ($range_length) print(fread($fp, $range_length));
		fclose($fp);
	}else{
		header('Expires: '.gmdate('D, d M Y H:i:s \G\M\T', time() + (1 * 24 * 60 * 60))); //cache for 1 days
	}
}

$dbcon->close();

?>
