<?php
/**
 * Minimal PSR-4 autoloader (no Composer dependency at runtime).
 *
 * @package DalliDelivery
 */

namespace DalliDelivery;

defined( 'ABSPATH' ) || exit;

/**
 * Maps the DalliDelivery\ namespace onto the src/ directory.
 */
final class Autoloader {

	private const PREFIX = 'DalliDelivery\\';

	/**
	 * Base directory (with trailing slash).
	 *
	 * @var string
	 */
	private static $base_dir = '';

	/**
	 * Register the autoloader.
	 *
	 * @param string $base_dir Absolute path to the src/ directory, with trailing slash.
	 * @return void
	 */
	public static function register( string $base_dir ): void {
		self::$base_dir = $base_dir;
		spl_autoload_register( array( __CLASS__, 'load' ) );
	}

	/**
	 * Load a class file.
	 *
	 * @param string $class_name Fully qualified class name.
	 * @return void
	 */
	public static function load( string $class_name ): void {
		if ( 0 !== strpos( $class_name, self::PREFIX ) ) {
			return;
		}

		$relative = substr( $class_name, strlen( self::PREFIX ) );
		$file     = self::$base_dir . str_replace( '\\', '/', $relative ) . '.php';

		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
}
