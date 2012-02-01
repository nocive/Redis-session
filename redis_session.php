<?php

abstract class RSessionBase
{
	protected static $_classmap = array( 
		'main' => 'RSessionMain', 
		'config' => 'RSessionConfig', 
		'redis' => 'RSessionRedis' 
	);
}

class RSessionConfig extends RSessionBase
{
	public static $defaults = array( 
		'debug' => 0, 
		'logfile' => false, 
		// executed on every start() call
		'session_config' => false, 
		'session_name' => false, 
		'session_start' => false, 
		'redis_host' => '127.0.0.1', 
		'redis_port' => '6379', 
		'redis_database' => 0, 
		// possible values: object | populate, or leave empty
		'session_array_compat' => false 
	);
	
	public $settings;


	public function __construct( $settings = array() )
	{
		$this->settings = ! empty( $settings ) ? array_merge( self::$defaults, $settings ) : self::$defaults;
	}


	public function get( $var )
	{
		return array_key_exists( $var, $this->settings ) ? $this->settings[$var] : null;
	}


	public function set( $var, $value = null )
	{
		if (is_array( $var )) {
			$this->settings = array_merge( $this->settings, $var );
		} else {
			$this->settings[$var] = $value;
		}
	}
}

class RSessionRedis extends RSessionBase
{
	public $client;
	public $config;
	
	protected static $_locks = array();
	
	const LOCK_RETRY_SLEEP = 0.5;
	const LOCK_DEFAULT_TIMEOUT = 20;
	const LOCK_DEFAULT_MAX_ATTEMPTS = 100;


	public function __construct( $config )
	{
		if (! is_object( $config )) {
			throw new InvalidArgumentException( '$config is not a valid config object' );
		}
		$this->config = $config;
		
		$settings = array( 
			'host' => $this->config->get( 'redis_host' ), 
			'port' => $this->config->get( 'redis_port' ), 
			'database' => $this->config->get( 'redis_database' ) 
		);
		
		if (! class_exists( 'Predis_Client' )) {
			throw new RuntimeException( 'Predis client library not found' );
		}
		$this->client = new Predis_Client( $settings );
	}


	public function __destruct()
	{
		$this->_releaseAll();
	}


	public function acquire( $key, $timeout = self::LOCK_DEFAULT_TIMEOUT, $maxAttempts = self::LOCK_DEFAULT_MAX_ATTEMPTS )
	{
		$expire = (time() + $timeout + 1) . '-' . getmypid();
		$attempts = 0;
		
		do {
			if ($this->setnx( $key, $expire )) {
				self::$_locks[$key] = 1;
				return true;
			}
			
			$lockValue = $this->get( $key );
			list ( $testExpire, ) = explode( '-', $lockValue );
			if ($testExpire < time()) {
				if ($this->getset( $key, $expire ) === $lockValue) {
					self::$_locks[$key] = 1;
					return true;
				}
			}
			usleep( self::LOCK_RETRY_SLEEP * 1000000 );
		} while ( ++ $attempts < $maxAttempts );
		
		return false;
	}


	public function release( $key )
	{
		if (($lockValue = $this->get( $key )) === null) {
			unset( self::$_locks[$key] );
			return true;
		}
		
		list ( $lockTimeout, $lockHolder ) = explode( '-', $lockValue );
		if ((int) $lockTimeout > time() && (int) $lockHolder === getmypid()) {
			$this->del( $key );
			unset( self::$_locks[$key] );
			return true;
		}
		return false;
	}


	protected function _releaseAll()
	{
		// release all unreleased locks
		foreach ( array_keys( self::$_locks ) as $lkey ) {
			$this->release( $lkey );
		}
	}


	public function locked( $key, $deep = true )
	{
		return ! $deep ? array_key_exists( $key, self::$_locks ) : array_key_exists( $key, self::$_locks ) || $this->exists( $key );
	}


	public function __call( $method, $args )
	{
		//$this->log( "-- REDIS cmd: $method, args: " . print_r( $args, true ) . "\n" );
		return call_user_func_array( array( 
			$this->client, 
			$method 
		), $args );
	}
}

class RSessionMain extends RSessionBase
{
	public $config;
	public $redis;
	
	public $id;
	public $name;
	
	public static $keyTemplates = array( 
		'session' => 'sess:%s:%s', 
		'lock' => 'lock:%s' 
	);
	
	protected $_data = array();
	
	const ARRAY_PATH_DELIMITER = '.';
	const HASH_TOUCH_KEY = '__#tx';
	
	const SESSION_ARRAY_COMPAT_OBJECT = 'object';
	const SESSION_ARRAY_COMPAT_POPULATE = 'populate'; // poorly tested


	public function __construct( $settings = array() )
	{
		if ($settings instanceof self::$_classmap['config']) {
			$this->config = $settings;
		} else {
			$this->config = new self::$_classmap['config']( $settings );
		}
		$this->redis = new self::$_classmap['redis']( $this->config );
		
		if ($this->config->get( 'session_start' )) {
			$this->start();
		}
	}


	public function start()
	{
		if ($this->started()) {
			return true;
		}
		
		$config = $this->config->get( 'session_config' );
		if (! empty( $config ) && is_file( $config )) {
			require $config;
		}
		
		$testSessName = $this->config->get( 'session_name' );
		if (! empty( $testSessName )) {
			session_name( $testSessName );
		}
		
		if (headers_sent()) {
			if ($this->config->get( 'session_array_compat' ) === self::SESSION_ARRAY_COMPAT_POPULATE) {
				$_SESSION = array();
			}
			return false;
		} elseif (! isset( $_SESSION )) {
			session_cache_limiter( 'must-revalidate' );
			session_start();
			header( 'P3P: CP="NOI ADM DEV PSAi COM NAV OUR OTRo STP IND DEM"' );
		} else {
			session_start();
		}
		
		if ($this->started()) {
			if ($this->config->get( 'session_array_compat' ) === self::SESSION_ARRAY_COMPAT_OBJECT) {
				$_SESSION = $this;
			}
			
			$sessId = session_id();
			if (! empty( $sessId )) {
				$this->id = $sessId;
				$this->name = session_name();
				$this->_init();
				
				if ($this->config->get( 'session_array_compat' ) === self::SESSION_ARRAY_COMPAT_POPULATE) {
					$_SESSION = & $this->_data;
				}
			}
		}
		
		return $this->started();
	}


	public function started()
	{
		return isset( $_SESSION ) && session_id();
	}


	public function rkey( $type )
	{
		$args = func_get_args();
		$type = array_shift( $args );
		
		/*var_dump( $this->name );
		var_dump( $this->id );
		debug_print_backtrace();
		echo "<br><br>";*/
		if (empty( $this->name ) || empty( $this->id )) {
			throw new Exception( 'Empty session name or session id' );
		}
		
		if (! array_key_exists( $type, self::$keyTemplates )) {
			throw new InvalidArgumentException( "Key template '$type' doesn't exist" );
		}
		
		$name = function_exists( 'hash' ) ? hash( 'crc32', $this->name ) : $this->name;
		
		switch ($type) {
		case 'lock':
			return sprintf( self::$keyTemplates[$type], md5( implode( '-', array( 
				$name, 
				$this->id, 
				$this->_arrayPathBasename( $args[0] ) 
			) ) ) );
		case 'session':
			return sprintf( self::$keyTemplates[$type], $name, $this->id );
		default:
			return vsprintf( self::$keyTemplates[$type], $args );
		}
	}


	public function lock( $key, $fn = null )
	{
		if (empty( $key )) {
			throw new InvalidArgumentException( 'Key cannot be empty' );
		}
		
		$this->start();
		
		$lkey = $this->rkey( 'lock', $key );
		if ($fn === null) {
			return $this->redis->acquire( $lkey );
		}
		
		if ($this->redis->acquire( $lkey )) {
			$fn();
			return $this->redis->release( $lkey );
		}
		return false;
	}


	public function unlock( $key )
	{
		if (empty( $key )) {
			throw new InvalidArgumentException( 'Key cannot be empty' );
		}
		
		$this->start();
		
		$lkey = $this->rkey( 'lock', $key );
		return $this->redis->release( $lkey );
	}


	public function locked( $key )
	{
		if (empty( $key )) {
			throw new InvalidArgumentException( 'Key cannot be empty' );
		}
		
		$this->start();
		
		$lkey = $this->rkey( 'lock', $key );
		return $this->redis->locked( $lkey );
	}


	public function write( $key, $value, $honourLocks = true )
	{
		if (empty( $key )) {
			throw new InvalidArgumentException( 'Key cannot be empty' );
		}
		
		$this->start();
		
		$field = $this->_arrayPathBasename( $key );
		$lkey = $this->rkey( 'lock', $field );
		
		// if no lock honouring or locked locally, just write
		if (! $honourLocks || $this->redis->locked( $lkey, $deep = false )) {
			$this->_data = $this->_arrayPathInsert( $this->_data, $key, $value );
			$this->_write( $field, $this->_data[$field] );
			return true;
		} else {
			if ($this->redis->acquire( $lkey )) {
				$this->_data = $this->_arrayPathInsert( $this->_data, $key, $value );
				$this->_write( $field, $this->_data[$field] );
				
				return $this->redis->release( $lkey );
			}
		}
		
		return false;
	}


	public function read( $key = null, $cached = false, $cacheUpdate = true )
	{
		$this->start();
		
		if ($cached) {
			return $this->_arrayPathExtract( $this->_data, $key );
		} else {
			if ($key === null) {
				$data = $this->_read();
				if ($cacheUpdate) {
					$this->_data = $data;
				}
				return $data;
			} else {
				$field = $this->_arrayPathBasename( $key );
				$data = $this->_read( $field );
				
				if ($data !== null) {
					if ($cacheUpdate) {
						// update our cached array with the live data requested
						$this->_data[$field] = $data;
					}
					
					$data = array( 
						$field => $data 
					);
					$data = $this->_arrayPathExtract( $data, $key );
				}
				
				return $data;
			}
		}
	}


	public function check( $key, $cached = false )
	{
		if (empty( $key )) {
			throw new InvalidArgumentException( 'Key cannot be empty' );
		}
		
		return $this->read( $key, $cached ) !== null;
	}


	public function delete( $key, $honourLocks = true )
	{
		if (empty( $key )) {
			throw new InvalidArgumentException( 'Key cannot be empty' );
		}
		
		$this->start();
		
		$field = $this->_arrayPathBasename( $key );
		$lkey = $this->rkey( 'lock', $field );
		
		if (! $honourLocks || $this->redis->locked( $lkey, $deep = false )) {
			$this->_data = $this->_arrayPathDelete( $this->_data, $key );
			$this->_write( $field, $this->_data[$field] );
			return true;
		} else {
			if ($this->redis->acquire( $lkey )) {
				$this->_data = $this->_arrayPathDelete( $this->_data, $key );
				if (! array_key_exists( $field, $this->_data )) {
					$this->_delete( $field );
				} else {
					$this->_write( $field, $this->_data[$field] );
				}
				return $this->redis->release( $lkey );
			}
		}
		return false;
	}


	public function destroy()
	{
		if ($this->started()) {
			$this->redis->del( $this->_sessionKey() );
			
			$this->renew( true );
			$this->__construct();
		}
	}


	public function id( $id = null )
	{
		if ($id) {
			$this->id = $id;
			session_id( $this->id );
		}
		
		return $this->id;
	}


	public function renew( $clear = false )
	{
		return $this->_regenerateId( $clear );
	}


	protected function _regenerateId( $clear = false )
	{
		if (! $this->started()) {
			return false;
		}
		
		$oldSessId = session_id();
		$sessName = session_name();
		if (! empty( $oldSessionId ) || isset( $_COOKIE[$sessName] )) {
			$params = session_get_cookie_params();
			if (isset( $params['httponly'] )) {
				setcookie( $sessName, '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly'] );
			} else {
				setcookie( $sessName, '', time() - 42000, $params['path'], $params['domain'], $params['secure'] );
			}
		}
		session_regenerate_id( true );
		$this->id = session_id();
		
		if ($clear) {
			$this->_init();
		} else {
			$skey = $this->rkey( 'session' );
			if ($this->redis->hsetnx( $skey, self::HASH_TOUCH_KEY, 1 )) {
				$pipe = $this->redis->pipeline();
				$pipe->expire( $skey, ini_get( 'session.gc_maxlifetime' ) );
				foreach ( $this->_data as $k => $v ) {
					$this->_write( $k, $v, $pipe );
				}
				$pipe->execute();
			}
		}
		return true;
	}


	protected function _init()
	{
		$skey = $this->rkey( 'session' );
		
		if ($this->redis->hsetnx( $skey, self::HASH_TOUCH_KEY, 1 )) {
			// new session
			$this->redis->expire( $skey, ini_get( 'session.gc_maxlifetime' ) );
			$this->_data = array();
		} else {
			$data = $this->redis->hgetall( $skey );
			unset( $data[self::HASH_TOUCH_KEY] );
			$this->_data = array_map( array( 
				& $this, 
				'_unpack' 
			), $data );
		}
	}


	protected function _read( $field = null )
	{
		$skey = $this->rkey( 'session' );
		
		if ($field === null) {
			$data = $this->redis->hgetall( $skey );
			unset( $data[self::HASH_TOUCH_KEY] );
			return array_map( array( 
				& $this, 
				'_unpack' 
			), $data );
		} else {
			if (($data = $this->redis->hget( $skey, $field )) === null) {
				return null;
			}
			
			return $this->_unpack( $data );
		}
	}


	protected function _write( $field, $value, $redis = null )
	{
		// hset return value tells if the field already existed or not, no meaning on this context
		$skey = $this->rkey( 'session' );
		$redis = $redis !== null ? $redis : $this->redis;
		$redis->hset( $skey, $field, $this->_pack( $value ) );
		return true;
	}


	protected function _delete( $field )
	{
		$skey = $this->rkey( 'session' );
		return $this->redis->hdel( $skey, $field ) === 1;
	}


	protected function _pack( $value )
	{
		return serialize( $value );
	}


	protected function _unpack( $value )
	{
		return unserialize( $value );
	}


	public function install()
	{
		register_shutdown_function( 'session_write_close' );
		
		session_set_save_handler( array( 
			__CLASS__, 
			'sess_open' 
		), array( 
			__CLASS__, 
			'sess_close' 
		), array( 
			__CLASS__, 
			'sess_read' 
		), array( 
			__CLASS__, 
			'sess_write' 
		), array( 
			__CLASS__, 
			'sess_destroy' 
		), array( 
			__CLASS__, 
			'sess_gc' 
		) );
		
		if ($this->config->get( 'session_array_compat' ) === self::SESSION_ARRAY_COMPAT_OBJECT) {
			$_SESSION = $this;
		}
	}


	/*******************************
	 * Session handler callbacks
	 *******************************/
	public function sess_open( $save_path, $session_name )
	{
		return true;
	}


	public function sess_close()
	{
		return true;
	}


	public function sess_read( $id )
	{
		return '';
	}


	public function sess_write( $id, $sess_data )
	{
		return true;
	}


	public function sess_destroy( $id )
	{
		return true;
	}


	public function sess_gc( $maxlifetime )
	{
		return true;
	}


	/*******************************
	 * Array path functions
	 *******************************/
	protected function _arrayPathBasename( $path )
	{
		if (empty( $path )) {
			throw new InvalidArgumentException( "Invalid path specified '$path'" );
		}
		$parts = explode( self::ARRAY_PATH_DELIMITER, $path );
		return $parts[0];
	}


	protected function _arrayPathExtract( array $list, $path, $default = null )
	{
		if (! is_array( $list )) {
			return $default;
		}
		
		$path = trim( $path, self::ARRAY_PATH_DELIMITER );
		$value = & $list;
		
		if (! empty( $path )) {
			$parts = explode( self::ARRAY_PATH_DELIMITER, $path );
			
			foreach ( $parts as $part ) {
				if (isset( $value[$part] )) {
					$value = $value[$part];
				} else {
					return $default;
				}
			}
		}
		
		return $value;
	}


	protected function _arrayPathInsert( array $list, $path, $value = null )
	{
		if (! is_array( $path )) {
			$path = explode( '.', $path );
		}
		$_list = & $list;
		
		foreach ( $path as $i => $key ) {
			if (is_numeric( $key ) && intval( $key ) > 0 || $key === '0') {
				$key = intval( $key );
			}
			if ($i === count( $path ) - 1) {
				$_list[$key] = $value;
			} else {
				if (! isset( $_list[$key] )) {
					$_list[$key] = array();
				}
				$_list = & $_list[$key];
			}
		}
		return $list;
	}


	protected function _arrayPathDelete( array $list, $path )
	{
		if (empty( $path )) {
			return $list;
		}
		if (! is_array( $path )) {
			$path = explode( self::ARRAY_PATH_DELIMITER, $path );
		}
		$_list = & $list;
		
		foreach ( $path as $i => $key ) {
			if (is_numeric( $key ) && intval( $key ) > 0 || $key === '0') {
				$key = intval( $key );
			}
			if ($i === count( $path ) - 1) {
				unset( $_list[$key] );
			} else {
				if (! isset( $_list[$key] )) {
					return $list;
				}
				$_list = & $_list[$key];
			}
		}
		return $list;
	}


	protected function _arrayPathCheck( array $list, $path )
	{
		if (empty( $path )) {
			return $list;
		}
		if (! is_array( $path )) {
			$path = explode( self::ARRAY_PATH_DELIMITER, $path );
		}
		
		foreach ( $path as $i => $key ) {
			if (is_numeric( $key ) && intval( $key ) > 0 || $key === '0') {
				$key = intval( $key );
			}
			if ($i === count( $path ) - 1) {
				return (is_array( $list ) && array_key_exists( $key, $list ));
			}
			
			if (! is_array( $list ) || ! array_key_exists( $key, $list )) {
				return false;
			}
			$list = & $list[$key];
		}
		return true;
	}
}

class RSession extends RSessionBase
{
	protected static $_instance;


	final private function __construct()
	{
		throw new Exception( 'An instance of ' . get_called_class() . ' cannot be instanciated' );
	}


	final private function __clone()
	{
		throw new Exception( 'An instance of ' . get_called_class() . ' cannot be cloned' );
	}


	public function getInstance()
	{
		return isset( static::$_instance ) ? static::$_instance : static::$_instance = new static::$_classmap['main']();
	}


	public function setInstance( $instance )
	{
		if (! $instance instanceof static::$_classmap['main']) {
			throw new InvalidArgumentException( 'Invalid instance type' );
		}
		
		static::$_instance = $instance;
	}


	public static function __callStatic( $method, $args )
	{
		if (method_exists( get_called_class(), $method )) {
			return forward_static_call_array( $method, $args );
		}
		
		return call_user_func_array( array( 
			static::getInstance(), 
			$method 
		), $args );
	}


	public static function config()
	{
		$args = func_get_args();
		$config = static::getInstance()->config;
		return call_user_func_array( array( 
			$config, 
			'set' 
		), $args );
	}


	public static function install()
	{
		static $installed = false;
		
		if (! $installed) {
			/*register_shutdown_function( 'session_write_close' );
			
			session_set_save_handler( array( 
				__CLASS__, 
				'sess_open' 
			), array( 
				__CLASS__, 
				'sess_close' 
			), array( 
				__CLASS__, 
				'sess_read' 
			), array( 
				__CLASS__, 
				'sess_write' 
			), array( 
				__CLASS__, 
				'sess_destroy' 
			), array( 
				__CLASS__, 
				'sess_gc' 
			) );
			
			if (self::config()->get( 'session_array_compat' ) === self::SESSION_ARRAY_COMPAT_OBJECT) {
				$_SESSION = self::instance();
			}
			$installed = true;*/
			static::getInstance()->install();
			$installed = true;
		}
	}
}

?>