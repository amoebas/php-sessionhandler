<?php
/**
 * @package ClusteredSessionHandler
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
	 * @var mysqli
	 */
	protected $dbLink = null;

	/**
	 *
	 * @var string - serialized from php
	 */
	private $initSessionData;

	private $host;

	private $password;

	private $user;

	/**
	 * Creates and initializes the sessionHandler
	 *
	 * @param string $dbhost
	 * @param string $dbuser
	 * @param string $dbpassword
	 * @return ClusteredSessionHandler
	 */
	public static function factory( $dbhost, $dbuser, $dbpassword ) {
		$sessionHandler = new ClusteredSessionHandler( $dbhost, $dbuser, $dbpassword );
		session_set_save_handler( array( &$sessionHandler, "open" ), array( &$sessionHandler, "close" ), array( &$sessionHandler, "read" ), array( &$sessionHandler, "write" ), array( &$sessionHandler, "destroy" ), array( &$sessionHandler, "gc" ) );
		return $sessionHandler;
	}

	/**
	 * Connect to the database
	 *
	 * @param string $host
	 * @param string $user
	 * @param string $password
	 * @return mysqli
	 */
	private function db() {
		if( $this->dbLink instanceof mysqli ) {
			return $this->dbLink;
		}
		$this->dbLink = @new mysqli( $this->host, $this->user, $this->password, 'sessions' );

		if( mysqli_connect_errno() ) {
			self::syslog('Can\'t connect to MySQL Server. Errorcode: '.mysqli_connect_error());
			$this->dbLink = null;
			return false;
		}
		return $this->dbLink;
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
	public function __construct($host, $user, $password ) {
		$this->host = $host;
		$this->user = $user;
		$this->password = $password;
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
		#self::$memcache = null;
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
		if( $data !== false ) {
			return $data;
		}
		$stmt = $this->db()->prepare( "SELECT `session_data` FROM `sessions`.`tblsessions` WHERE `session_id`= ?" );
		$stmt->bind_param( 's', $sessionID );
		$stmt->execute();
		$stmt->bind_result( $data );
		$stmt->fetch();
		$stmt->close();
		
		if( $data ) {
			$this->memcache_set( $sessionID, $data, false, intval( ini_get( "session.gc_maxlifetime" ) ) );
		}
		return $data;
	}

	/**
	 * Write session with sessionId with data
	 * This is called upon script termination or when session_write_close() is called, which ever is first.
	 *
	 * @param string $sessionID
	 * @param string $data
	 * @return boolean
	 */
	public function write( $sessionID, $data ) {
		// Only save data if it has changed
		if( $this->initSessionData === $data ) {
			return $result;
		}
		$this->memcache_set( $sessionID, $data, false, intval( ini_get( "session.gc_maxlifetime" ) ) );
		$stmt = $this->db()->prepare( "REPLACE INTO `sessions`.`tblsessions` (`session_id`,`session_expiration`,`session_data`) VALUES(?,?,?)" );
		$sessionExpire = (intval( ini_get( "session.gc_maxlifetime" ) ) + time());
		$stmt->bind_param( 'sis', $sessionID, $sessionExpire, $data );
		$stmt->execute();
		$written = (bool) $stmt->affected_rows;
		$stmt->close();
		return $written;
	}

	/**
	 * Destroy session with sessionid
	 *
	 * @param string $sessionID
	 * @return boolean
	 */
	public function destroy( $sessionID ) {
		$this->memcache_delete( $sessionID );
		$sessionID = mysql_real_escape_string( $sessionID );
		$stmt = $this->db()->prepare( "DELETE FROM `sessions`.`tblsessions` WHERE `session_id`= ?" );
		$stmt->bind_param('s',$sessionID);
		$stmt->execute();
		$stmt->close();
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
		
		$stmt = $this->db()->prepare( "SELECT `session_id` FROM `sessions`.`tblsessions` WHERE `session_expiration` < ?" );
		$timeExpire = time() - $maxlifetime;
		$stmt->bind_param( 'i', $timeExpire);
		$stmt->execute();
		$stmt->bind_result( $sessionID );
		$oldSessions = array();
		while( $stmt->fetch() ) {
			$oldSessions[] = $sessionID;
		}
		foreach( $oldSessions as $sessionID ) {
			$this->destroy( $sessionID );
		}
		return true;
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
		openlog('session', LOG_ODELAY, LOG_LOCAL0 );
		syslog( LOG_CRIT, __FILE__.' '.$message );
		closelog();
	}
}
#ClusteredSessionHandler::connect_to_memcached( '127.0.0.1', '11211' );
$takeone = ClusteredSessionHandler::factory('localhost', 'root', '' );
