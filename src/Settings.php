<?php
/**
 * Plugin settings accessor.
 *
 * @package DalliDelivery
 */

namespace DalliDelivery;

defined( 'ABSPATH' ) || exit;

/**
 * Reads plugin options (stored one option per setting, prefixed with dalli_delivery_).
 *
 * The API token can be defined in wp-config.php:
 *   define( 'DALLI_TOKEN', '...' );       // live token
 *   define( 'DALLI_TEST_TOKEN', '...' );  // test platform token
 */
class Settings {

	public const PREFIX = 'dalli_delivery_';

	public const BRANCH_MSK = 'msk';
	public const BRANCH_SPB = 'spb';

	public const MODE_LIVE = 'live';
	public const MODE_TEST = 'test';

	/**
	 * Default values for every setting.
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults(): array {
		return array(
			'branch'             => self::BRANCH_MSK,
			'mode'               => self::MODE_LIVE,
			'token'              => '',
			'test_token'         => '',
			'sender_code'        => '',
			'default_weight'     => '0.5',
			'default_length'     => '10',
			'default_width'      => '10',
			'default_height'     => '10',
			'box_weight'         => '0',
			'box_length'         => '0',
			'box_width'          => '0',
			'box_height'         => '0',
			'declared_value'     => 'items',
			'declared_percent'   => '100',
			'cod_gateways'       => array( 'cod' ),
			'card_gateways'      => array(),
			'rounding_step'      => '0',
			'rounding_direction' => 'nearest',
			'order_trigger'      => 'processing',
			'order_prefix'       => '',
			'item_vat'           => '22',
			'accept_partially'   => '',
			'default_time_min'   => '10:00',
			'default_time_max'   => '22:00',
			'choose_interval'    => 'yes',
			'sticker_format'     => '4',
			'sync_enabled'       => 'yes',
			'sync_interval'      => '15',
			'sync_mode'          => 'per_order',
			'status_map'         => array(),
			'tracking_url'       => '',
			'debug'              => 'no',
		);
	}

	/**
	 * Get a setting.
	 *
	 * @param string $key Setting key (without prefix).
	 * @return mixed
	 */
	public function get( string $key ) {
		$defaults = self::defaults();
		$default  = $defaults[ $key ] ?? '';
		$value    = get_option( self::PREFIX . $key, $default );
		if ( is_array( $default ) && ! is_array( $value ) ) {
			return $default;
		}
		return $value;
	}

	/**
	 * Get a setting as float.
	 *
	 * @param string $key Key.
	 * @return float
	 */
	public function get_float( string $key ): float {
		return (float) str_replace( ',', '.', (string) $this->get( $key ) );
	}

	/**
	 * Get a yes/no setting as bool.
	 *
	 * @param string $key Key.
	 * @return bool
	 */
	public function is_enabled( string $key ): bool {
		return 'yes' === $this->get( $key );
	}

	/**
	 * Whether the test platform mode is on.
	 *
	 * @return bool
	 */
	public function is_test_mode(): bool {
		return self::MODE_TEST === $this->get( 'mode' );
	}

	/**
	 * Current branch.
	 *
	 * @return string msk|spb
	 */
	public function branch(): string {
		return self::BRANCH_SPB === $this->get( 'branch' ) ? self::BRANCH_SPB : self::BRANCH_MSK;
	}

	/**
	 * API base URL for the chosen branch. Overridable with the DALLI_API_URL constant or the
	 * `dalli_delivery_api_url` filter (used by the automated tests with a mock server).
	 *
	 * @return string
	 */
	public function api_url(): string {
		$url = self::BRANCH_SPB === $this->branch()
			? 'https://spbapi.dalli-service.com/v1/'
			: 'https://api.dalli-service.com/v1/';

		if ( defined( 'DALLI_API_URL' ) && is_string( DALLI_API_URL ) && '' !== DALLI_API_URL ) {
			$url = DALLI_API_URL;
		}

		/**
		 * Filters the API endpoint.
		 *
		 * @since 1.0.0
		 *
		 * @param string $url Endpoint URL.
		 */
		return (string) apply_filters( 'dalli_delivery_api_url', $url );
	}

	/**
	 * The active token: constant first, then the stored option.
	 *
	 * @return string
	 */
	public function token(): string {
		if ( $this->is_test_mode() ) {
			if ( defined( 'DALLI_TEST_TOKEN' ) && is_string( DALLI_TEST_TOKEN ) && '' !== DALLI_TEST_TOKEN ) {
				return trim( DALLI_TEST_TOKEN );
			}
			return trim( (string) $this->get( 'test_token' ) );
		}
		if ( defined( 'DALLI_TOKEN' ) && is_string( DALLI_TOKEN ) && '' !== DALLI_TOKEN ) {
			return trim( DALLI_TOKEN );
		}
		return trim( (string) $this->get( 'token' ) );
	}

	/**
	 * Whether the active token is provided by a wp-config.php constant.
	 *
	 * @param bool $test Check the test token constant.
	 * @return bool
	 */
	public static function token_is_constant( bool $test = false ): bool {
		if ( $test ) {
			return defined( 'DALLI_TEST_TOKEN' ) && is_string( DALLI_TEST_TOKEN ) && '' !== DALLI_TEST_TOKEN;
		}
		return defined( 'DALLI_TOKEN' ) && is_string( DALLI_TOKEN ) && '' !== DALLI_TOKEN;
	}

	/**
	 * Whether an API token is configured.
	 *
	 * @return bool
	 */
	public function has_token(): bool {
		return '' !== $this->token();
	}

	/**
	 * Mask a secret for display: only the last 4 characters are visible.
	 *
	 * @param string $secret Secret.
	 * @return string
	 */
	public static function mask( string $secret ): string {
		if ( '' === $secret ) {
			return '';
		}
		$visible = strlen( $secret ) > 8 ? substr( $secret, -4 ) : '';
		return str_repeat( '•', 12 ) . $visible;
	}

	/**
	 * Cache TTL for rates in seconds.
	 *
	 * @return int
	 */
	public function rates_ttl(): int {
		/**
		 * Filters how long calculated delivery costs are cached.
		 *
		 * @since 1.0.0
		 *
		 * @param int $ttl Seconds.
		 */
		return max( 0, (int) apply_filters( 'dalli_delivery_rates_ttl', 15 * MINUTE_IN_SECONDS ) );
	}
}
