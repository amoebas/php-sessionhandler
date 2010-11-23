<?php
function get_raw_user_IP() {
	if( isset( $_SERVER ) ) {
		if( isset( $_SERVER[ 'HTTP_X_FORWARDED_FOR' ] ) ) {
			return $_SERVER[ 'HTTP_X_FORWARDED_FOR' ];
		}
		if( isset( $_SERVER[ 'HTTP_CLIENT_IP' ] ) ) {
			return $_SERVER[ 'HTTP_CLIENT_IP' ];
		}

		return (array_key_exists('REMOTE_ADDR', $_SERVER ) ) ? $_SERVER['REMOTE_ADDR']:'';
	}

	if( getenv( 'HTTP_X_FORWARDED_FOR' ) ) {
		return getenv( 'HTTP_X_FORWARDED_FOR' );
	}
	if( getenv( 'HTTP_CLIENT_IP' ) ) {
		return getenv( 'HTTP_CLIENT_IP' );
	}
	return getenv( 'REMOTE_ADDR' );
}

session_start();

if( isset( $_GET['clear' ] ) ) {
	session_destroy();
	echo 'Session has been killed.<br /><a href="?">Return</a>';
	exit();
} 

if( !isset( $_SESSION['count'] ) ) {
	$_SESSION['count'] = 1;
}
echo 'Hello ip '.get_raw_user_IP().' with sessionId: <pre>'.  session_id() . '</pre> you seen this page <strong>'.$_SESSION['count'].'</strong> times'.PHP_EOL;
$_SESSION['count']++;

?>
<br /><a href="?clear">Clear session</a>

