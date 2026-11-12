<?php
/**
 * WooCommerce logger wrapper with secret redaction.
 *
 * @package DalliDelivery
 */

namespace DalliDelivery\Support;

use DalliDelivery\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Writes to WooCommerce → Status → Logs with the source "dalli-delivery".
 * Debug output is written only when the "debug" setting is on; errors always.
 */
class Logger {

	public const SOURCE = 'dalli-delivery';

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Settings.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Remove tokens from a log message.
	 *
	 * @param string $message Message.
	 * @param string $token   Known token value (optional).
	 * @return string
	 */
	public static function redact( string $message, string $token = '' ): string {
		$message = (string) preg_replace( '/(token\s*=\s*)(["\'])(.*?)\2/i', '$1$2[redacted]$2', $message );
		if ( strlen( $token ) >= 4 ) {
			$message = str_replace( $token, '[redacted]', $message );
		}
		return $message;
	}

	/**
	 * Debug message (only when debugging is enabled).
	 *
	 * @param string $message Message.
	 * @return void
	 */
	public function debug( string $message ): void {
		if ( $this->settings->is_enabled( 'debug' ) ) {
			$this->write( 'debug', $message );
		}
	}

	/**
	 * Informational message (only when debugging is enabled).
	 *
	 * @param string $message Message.
	 * @return void
	 */
	public function info( string $message ): void {
		if ( $this->settings->is_enabled( 'debug' ) ) {
			$this->write( 'info', $message );
		}
	}

	/**
	 * Error message (always written).
	 *
	 * @param string $message Message.
	 * @return void
	 */
	public function error( string $message ): void {
		$this->write( 'error', $message );
	}

	/**
	 * Write a log record.
	 *
	 * @param string $level   PSR-3 level.
	 * @param string $message Message.
	 * @return void
	 */
	private function write( string $level, string $message ): void {
		if ( ! function_exists( 'wc_get_logger' ) ) {
			return;
		}
		$message = self::redact( $message, $this->settings->token() );
		wc_get_logger()->log( $level, $message, array( 'source' => self::SOURCE ) );
	}
}
