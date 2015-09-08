<?php
include 'common.php';

if(!isset($_GET["a"]) && !isset($_GET["q"])){
	exit;
}

$dbcon = new mysqli("localhost", MYSQL_ACCOUNT, MYSQL_PASSWORD, DB_NAME);
if($dbcon->connect_errno) die("db connection failed: " . $dbcon->connect_error);

$dbcon->set_charset("utf8");

if(isset($_GET["a"])){ //get all
	if (!($stmt = $dbcon->prepare("SELECT `id`, `artist`, `album`, `trackno`, `title` FROM tracks ORDER BY `tracks`.`artist` ASC, `tracks`.`album` ASC, `tracks`.`trackno` ASC, `tracks`.`title` ASC"))) {
		die("Prepare failed: (" . $dbcon->errno . ") " . $dbcon->error);
	}
}else{
	if (!($stmt = $dbcon->prepare("SELECT `id`, `artist`, `album`, `trackno`, `title` FROM tracks WHERE MATCH(artist, album, title) AGAINST(? IN BOOLEAN MODE) ORDER BY `tracks`.`artist` ASC, `tracks`.`album` ASC, `tracks`.`trackno` ASC, `tracks`.`title` ASC"))) {
		die("Prepare failed: (" . $dbcon->errno . ") " . $dbcon->error);
	}
	$stmt->bind_param('s', $_GET["q"]);
}

$stmt->execute();
$stmt->bind_result($id, $artist, $album, $trackno, $title);

$tracks_data = array();
while($stmt->fetch()){
	$tracks_data[] = array("id"=>$id, "artist"=>$artist, "album"=>$album, "trackno"=>$trackno, "title"=>$title);
}
echo json_encode($tracks_data);

$dbcon->close();

?>

