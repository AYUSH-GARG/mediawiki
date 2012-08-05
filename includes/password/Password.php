<?php
/**
 * Class implementing password hashing and comparison for MediaWiki
 *
 * @file
 */

class PasswordStatusException extends Exception {

	protected $status;

	public function __construct( $status ) {
		$this->status = $status;
		parent::__construct( $status->getWikiText() );
	}

	public function getStatus() {
		return $this->status;
	}

}

class Password {

	/**
	 * Map of registered PasswordTypes
	 */
	protected static $types = array();

	/**
	 * The preferred PasswordType
	 */
	protected static $preferredType;

	/**
	 * Initialize the class
	 * - Register password types
	 * - Pick the preferred type
	 * - Run a hook for extensions to register new password types
	 */
	protected static function init() {
		if ( isset( self::$preferredType ) ) {
			return;
		}
		self::registerType( 'A', 'Password_TypeA' );
		self::registerType( 'B', 'Password_TypeB' );
		self::registerType( 'PBKHM', 'Password_TypePBKHM' );

		// If wgPasswordSalt is set the preferred type is t he best implementation we have now, otherwise it's type A.
		global $wgPasswordSalt;
		if( $wgPasswordSalt ) {
			$preferredType = 'PBKHM';
		} else {
			$preferredType = 'A';
		}

		// Run a hook that'll let extensions register types and changes the preferred type
		wfRunHooks( 'PasswordClassInit', array( &$preferredType ) );

		self::$preferredType = $preferredType;
	}

	/**
	 * Register a password class type
	 *
	 * @param $type The name of the type. Core uses names like 'A', 'B', ...
	 *              extensions should use more specific names.
	 * @param $className The class implementing this password type. The class
	 *                   must implement the PasswordType interface.
	 */
	public static function registerType( $type, $className ) {
		self::$types[$type] = $className;
	}

	/**
	 * Return a new instance of a password class type
	 *
	 * @param $type string The password type to return. If left out will return the preferred type.
	 * @return mixed A PasswordType implementing class, or null.
	 */
	public static function getType( $type = null ) {
		self::init();
		if ( is_null( $type ) ) {
			$type = self::$preferredType;
		}
		if ( isset( self::$types[$type] ) ) {
			$className = self::$types[$type];
			$cryptType = new $className( $type );
			if ( $cryptType instanceof PasswordType ) {
				return $cryptType;
			}
			wfWarn( __METHOD__ . ": Password crypt type $type class $className does not implement PasswordType." );
			return null;
		}
		wfWarn( __METHOD__ . ": Password crypt type $type does not exist." );
		return null;
	}

	/**
	 * Create a hashed password we can store in the database given a user's plaintext password.
	 *
	 * @param $password The plaintext password
	 * @return string The raw hashed password output along with parameters and a type.
	 */
	public static function crypt( $password ) {
		$cryptType = self::getType();
		return ':' . $cryptType->getName() . ':' . $cryptType->crypt( $password );
	}

	/**
	 * Parse the hashed form of a password stored in the database
	 * Used by compare() and isPreferredFormat() to avoid repeating common
	 * parsing code.
	 *
	 * @param $data string The raw hashed password data with all params and types stuck on the front.
	 * @return Status or an array containing a PasswordType class and the remaining portion of $data
	 */
	protected static function parseHash( $data ) {
		$params = explode( ':', $data, 3 );

		// Shift off the blank (When ":A:..." is split the first : should mean the first element is '')
		$blank = array_shift( $params );
		if ( $blank !== '' ) {
			// If the first piece is not '' then this is invalid
			// Note that old style passwords (oldCrypt) are handled by User internally since they require
			// data which we do not have.
			return Status::newFatal( 'password-crypt-invalid' );
		}
		$type = array_shift( $params );
		if ( !$type ) {
			// A type was not specified
			return Status::newFatal( 'password-crypt-invalid' );
		}

		$cryptType = self::getType( $type );
		if ( !$cryptType ) {
			// Crypt type does not exist
			return Status::newFatal( 'password-crypt-notype' );
		}

		return array( $cryptType, $params[0] );
	}

	/**
	 * Compare the hashed db contents of a password with a plaintext password to see if the
	 * password is correct.
	 *
	 * @param $data string The raw hashed password data with all params and types stuck on the front.
	 * @param $password The plaintext password
	 * @return Status A Status object;
	 *         - Good with a value of true for a password match
	 *         - Good with a value of false for a bad password
	 *         - Fatal if the password data was badly formed or there was some issue with
	 *           comparing the passwords which is not the user's fault.
	 */
	public static function compare( $data, $password ) {
		$status = self::parseHash( $data );
		if ( $status instanceof Status ) {
			return $status;
		}
		list( $cryptType, $remainingData ) = $status;
		try {
			return $cryptType->compare( $remainingData, $password );
		} catch( PasswordStatusException $e ) {
			return $e->getStatus();
		}
	}

	/**
	 * Check and see if the hashed data of a password is in preferred format.
	 * This may return false when the password type is not the same as the specified preferred type
	 * or when the password type implementation says that some of the parameters are different than
	 * what is preferred.
	 *
	 * When this method returns false the User's password may be 'upgraded' by calling
	 * crypt() again to generate a new hash for the password.
	 *
	 * @param $data string The raw hashed password data with all params and types stuck on the front.
	 * @return bool
	 */
	public static function isPreferredFormat( $data ) {
		$status = self::parseHash( $data );
		if ( $status instanceof Status ) {
			// If parseHash had issues then this is naturally not preferred
			return false;
		}
		list( $cryptType, $remainingData ) = $status;
		
		if ( $cryptType->getName() !== self::$preferredType ) {
			// If cryptType's name does not match the preferred type it's not preferred
			return false;
		}

		try {
			if ( $cryptType->isPreferredFormat( $remainingData ) === false ) {
				// If cryptType's isPreferredFormat returns false it's not preferred
				return false;
			}
		} catch( PasswordStatusException $e ) {
			// If there was an issue with the data, it's not preferred
			return false;
		}

		// If everything looked fine, then it's preferred
		return true;
	}

}
