<?php
/**
 * @package ClusteredSessionHandler
 * @copyright Webguidepartner AB
 * @version $Id$
 *
 * This class replaces the default php session handler with one that is more
 * suitable for clustered environment.
 * 
 * It utilizes the database to save the session data and may also use memcache
 * as a write-throu cache layer to speed up fetching of data
 *
 * Usage:
 * Save this file as /etc/php.session.php
 *
 * auto_prepend_file = /etc/php.session.php
 *
 * See bottom of the file
 *
 *
 * Database create statement:
 *
CREATE DATABASE IF NOT EXISTS `sessions`;

DROP TABLE IF EXISTS `sessions`.`tblsessions`;
CREATE TABLE IF NOT EXISTS `sessions`.`tblsessions` (
`session_id` VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
`session_expiration` INT(10) UNSIGNED NOT NULL,
`session_data` TEXT COLLATE utf8_unicode_ci NOT NULL,
`session_save_path` TEXT COLLATE utf8_unicode_ci NOT NULL,
PRIMARY KEY (`session_id`),
KEY `session_expiration` (`session_expiration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

GRANT ALL PRIVILEGES ON sessions.* TO "%"@"%";
*/
class ClusteredSessionHandler {

	/**
	 *
	 * @var Memcache
	 */
	public static $memcache = null;

	/**
	 *
	 * @var string - serialized from php
	 */
	private $initSessionData;

	/**
	 * Creates and initializes the sessionHandler
	 *
	 * @param string $dbhost
	 * @param string $dbuser
	 * @param string $dbpassword
	 * @return ClusteredSessionHandler
	 */
	public static function factory( $dbhost, $dbuser, $dbpassword ) {
		self::connect_to_database( $dbhost, $dbuser, $dbpassword );
		$sessionHandler = new ClusteredSessionHandler();
		session_set_save_handler( array( &$sessionHandler, "open" ), array( &$sessionHandler, "close" ), array( &$sessionHandler, "read" ), array( &$sessionHandler, "write" ), array( &$sessionHandler, "destroy" ), array( &$sessionHandler, "gc" ) );
		return $sessionHandler;
	}

	/**
	 * Connect to the database
	 *
	 * @param string $host
	 * @param string $user
	 * @param string $password
	 */
	private static function connect_to_database( $host, $user, $password ) {
		$res = mysql_connect( $host, $user, $password );
	}

	/**
	 * Connect and use memcache as a fast cachelayer - optional
	 *
	 * @param string $host
	 * @param string $portnumber
	 */
	public static function connect_to_memcached( $host, $portnumber ) {
		$memcache = new Memcache();
		if( @$memcache->connect( $host, $portnumber ) ) {
			self::$memcache = $memcache;
		} else {
			self::syslog( 'Can\'t connect to memcached server at '.$host.':'.$portnumber );
		}
	}

	/**
	 *
	 * @param string $memcacheHost
	 * @param string $memcachePort
	 * @return boolean
	 */
	public function __construct() {
		register_shutdown_function( "session_write_close" );
		$this->initSessionData = null;
		return true;
	}

	/**
	 * Open a session
	 *
	 * @param string $savePath - not used yet
	 * @param string $sessionName
	 * @return boolean
	 */
	public function open( $savePath, $sessionName ) {
		$sessionID = session_id();
		if( $sessionID !== "" ) {
			$this->initSessionData = $this->read( $sessionID );
		}
		return true;
	}

	/**
	 * Close the session handler
	 *
	 * @return boolean
	 */
	public function close() {
		if( isset( self::$memcache ) ) {
			self::$memcache = null;
		}
		$this->initSessionData = null;
		return true;
	}

	/**
	 * Get data from session with session ID
	 *
	 * @param string $sessionID
	 * @return mixed
	 */
	public function read( $sessionID ) {
		$data = $this->memcache_get( $sessionID );
		if( $data === false ) {
			$sessionIDEscaped = mysql_real_escape_string( $sessionID );
			$result = mysql_query( "SELECT `session_data` FROM `sessions`.`tblsessions` WHERE `session_id`='$sessionIDEscaped'" );
			if( is_resource( $result ) && ( mysql_num_rows( $result ) !== 0 ) ) {
				$data = mysql_result( $result, 0, "session_data" );
			}
			$this->memcache_set( $sessionID, $data, false, intval( ini_get( "session.gc_maxlifetime" ) ) );
		}
		return $data;
	}

	/**
	 * Write session with sessionId with data
	 *
	 * @param string $sessionID
	 * @param string $data
	 * @return boolean
	 */
	public function write( $sessionID, $data ) {
		
		# This is called upon script termination or when session_write_close() is called, which ever is first.
		$result = $this->memcache_set( $sessionID, $data, false, intval( ini_get( "session.gc_maxlifetime" ) ) );
		if( $this->initSessionData !== $data ) {
			$sessionID = mysql_real_escape_string( $sessionID );
			$sessionExpirationTS = (intval( ini_get( "session.gc_maxlifetime" ) ) + time());
			$sessionData = mysql_real_escape_string( $data );
			$r = $this->sql_query( "REPLACE INTO `sessions`.`tblsessions` (`session_id`,`session_expiration`,`session_data`) VALUES('$sessionID',$sessionExpirationTS,'$sessionData')" );
			$result = is_resource( $r );
		}
		return $result;
	}

	/**
	 * Destory session with sessionid
	 *
	 * @param string $sessionID
	 * @return boolean
	 */
	public function destroy( $sessionID ) {
		$this->memcache_delete( $sessionID );
		$sessionID = mysql_real_escape_string( $sessionID );
		$this->sql_query( "DELETE FROM `sessions`.`tblsessions` WHERE `session_id`='$sessionID'" );

		return true;
	}

	/**
	 * Garbage collection
	 *
	 * From php.ini
	 * ; The probability is calculated by using gc_probability/gc_divisor,
	 * ; e.g. 1/100 means there is a 1% chance that the GC process starts
	 * ; on each request.
	 * session.gc_probability = 1
	 * session.gc_divisor     = 1000
	 *
	 * @param int $maxlifetime
	 * @return boolean
	 */
	public function gc( $maxlifetime ) {
		$r = $this->sql_query( "SELECT `session_id` FROM `sessions`.`tblsessions` WHERE `session_expiration`<" . (time() - $maxlifetime) );
		if( is_resource( $r ) && ($rows = mysql_num_rows( $r ) !== 0) ) {
			for( $i = 0; $i < $rows; $i++ ) {
				$this->destroy( mysql_result( $r, $i, "session_id" ) );
			}
		}
		return true;
	}

	/**
	 * Runs a sql query and reports to syslog if errors happens
	 *
	 * @param string $sql
	 * @return resource For SELECT, SHOW, DESCRIBE, EXPLAIN and other statements returning resultset,
	 */
	private function sql_query( $sql ) {
		$resource = @mysql_query( $sql );
		if( mysql_errno() ) {
			self::syslog( mysql_error() );
		}
		return $resource;
	}

	/**
	 *
	 * @param string $sessionID
	 * @return mixed
	 */
	private function memcache_get( $sessionID ) {
		if( !self::$memcache ) {
			return false;
		}
		return self::$memcache->get( $sessionID );
	}

	/**
	 *
	 * @param sting $sessionID
	 * @param string $data
	 * @param boolean $compressed
	 * @param int $lifetime
	 * @return mixed
	 */
	private function memcache_set( $sessionID, $data, $compressed, $lifetime ) {
		if( !self::$memcache ) {
			return false;
		}
		self::$memcache->set( $sessionID, $data, $compressed, $lifetime  );
	}

	/**
	 * Delete session with sessionId from Memcached
	 *
	 * @param string $sessionID
	 * @return mixed
	 */
	private function memcache_delete( $sessionID ) {
		if( !self::$memcache ) {
			return false;
		}
		self::$memcache->delete( $sessionID );
	}

	/**
	 * Reports to syslog
	 *
	 * @param string $message
	 */
	private static function syslog( $message ) {
		openlog('session', LOG_ODELAY,LOG_LOCAL0 );
		syslog( LOG_CRIT, __FILE__.' '.$message );
		closelog();
	}
}
#ClusteredSessionHandler::connect_to_memcached( '127.0.0.1', '11211' );
$sessionHandler = ClusteredSessionHandler::factory('localhost', 'root', '' );
