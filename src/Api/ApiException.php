<?php
/**
 * Exception raised by the API layer.
 *
 * @package DalliDelivery
 */

namespace DalliDelivery\Api;

defined( 'ABSPATH' ) || exit;

/**
 * Describes a failed API interaction. Messages never contain the token.
 */
class ApiException extends \RuntimeException {

	public const TYPE_TRANSPORT = 'transport';
	public const TYPE_HTTP      = 'http';
	public const TYPE_AUTH      = 'auth';
	public const TYPE_MALFORMED = 'malformed';
	public const TYPE_API       = 'api';
	public const TYPE_CONFIG    = 'config';

	/**
	 * Failure category.
	 *
	 * @var string
	 */
	private $type;

	/**
	 * Error code returned by the API (when any).
	 *
	 * @var string
	 */
	private $api_code;

	/**
	 * Constructor.
	 *
	 * @param string $type     One of the TYPE_ constants.
	 * @param string $message  Message.
	 * @param string $api_code API error code.
	 */
	public function __construct( string $type, string $message, string $api_code = '' ) {
		parent::__construct( $message );
		$this->type     = $type;
		$this->api_code = $api_code;
	}

	/**
	 * Plain-text message for display. Messages are HTML-escaped when the exception is thrown
	 * (so that throwing is safe for any output path); decode them here and escape again on output.
	 *
	 * @return string
	 */
	public function display_message(): string {
		return wp_specialchars_decode( $this->getMessage(), ENT_QUOTES );
	}

	/**
	 * Failure category.
	 *
	 * @return string
	 */
	public function get_type(): string {
		return $this->type;
	}

	/**
	 * API error code.
	 *
	 * @return string
	 */
	public function get_api_code(): string {
		return $this->api_code;
	}

	/**
	 * Whether repeating the same request later may succeed.
	 *
	 * @return bool
	 */
	public function is_retryable(): bool {
		if ( self::TYPE_TRANSPORT === $this->type ) {
			return true;
		}
		// HTTP errors: only server-side failures and throttling are worth repeating.
		return self::TYPE_HTTP === $this->type && ( (int) $this->api_code >= 500 || 429 === (int) $this->api_code );
	}

	/**
	 * Factory: missing configuration.
	 *
	 * @param string $message Message.
	 * @return self
	 */
	public static function config( string $message ): self {
		return new self( self::TYPE_CONFIG, $message );
	}

	/**
	 * Factory: the response could not be understood.
	 *
	 * @param string $message Message.
	 * @return self
	 */
	public static function malformed( string $message ): self {
		return new self( self::TYPE_MALFORMED, $message );
	}

	/**
	 * Factory: authentication failed.
	 *
	 * @param string $message Message.
	 * @param string $code    API error code.
	 * @return self
	 */
	public static function auth( string $message, string $code ): self {
		return new self( self::TYPE_AUTH, $message, $code );
	}

	/**
	 * Factory: the API reported an error.
	 *
	 * @param string $message Message.
	 * @param string $code    API error code.
	 * @return self
	 */
	public static function api( string $message, string $code ): self {
		return new self( self::TYPE_API, $message, $code );
	}
}
