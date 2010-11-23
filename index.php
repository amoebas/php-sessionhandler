<?php
/**
 * This is just a small page to test the ClusteredSessionHandler
 * 
 */
?>
<html><head><title>Session tester</title></head><body>
<div>
	<a href="?">Session</a> | 
	<a href="?clear">Crunch session</a> |
	<a href="?nosession">No session</a> |
	<a href="?garbagecollection">Garbage Collection</a>
</div>
<?php

if( isset( $_GET[ 'nosession' ] ) ) {
	echo '<p>Hello, I have no idea what a session is right now, this is what i think';
	echo ' your sessionId is: <strong>'.session_id().'</strong></p>';
} elseif( isset( $_GET[ 'garbagecollection'] ) ) {
	
	session_start();
	$ClusteredSessionHandler->gc(1);
	echo 'Garbage collection has been run, old session are now gone.';
} elseif( isset( $_GET['clear' ] ) ) {
	session_start();
	session_destroy();
	echo '<p>Session has been killed.</p>';
} else {
	session_start();
	if( !isset( $_SESSION['count'] ) ) {
		$_SESSION['count'] = 0;
	}
	$_SESSION['count']++;
	echo '<p>Hello, sessionId: <strong>'.  session_id() . '</strong> you\'ve seen this page'.
	' <strong>'.$_SESSION['count'].'</strong> times</p>';
}
?>
</body>
</html>