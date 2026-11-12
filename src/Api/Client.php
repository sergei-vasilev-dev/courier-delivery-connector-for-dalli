<?php
/**
 * HTTP transport for the Dalli API.
 *
 * @package DalliDelivery
 */

namespace DalliDelivery\Api;

use DalliDelivery\Settings;
use DalliDelivery\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Sends XML documents with wp_remote_post(), with timeouts and retry/backoff.
 * Every Dalli method is an HTTP POST to the same endpoint; the XML root element selects the method.
 */
class Client {

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	private $logger;

	/**
	 * Sleep callback (milliseconds), replaceable in tests.
	 *
	 * @var callable
	 */
	private $sleeper;

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Settings.
	 * @param Logger   $logger   Logger.
	 */
	public function __construct( Settings $settings, Logger $logger ) {
		$this->settings = $settings;
		$this->logger   = $logger;
		$this->sleeper  = static function ( int $milliseconds ): void {
			usleep( $milliseconds * 1000 );
		};
	}

	/**
	 * Replace the sleep function (tests).
	 *
	 * @param callable $sleeper Receives milliseconds.
	 * @return void
	 */
	public function set_sleeper( callable $sleeper ): void {
		$this->sleeper = $sleeper;
	}

	/**
	 * Active token, or an exception when none is configured.
	 *
	 * @return string
	 * @throws ApiException When the token is missing.
	 */
	public function token(): string {
		$token = $this->settings->token();
		if ( '' === $token ) {
			throw ApiException::config( esc_html__( 'The Dalli API token is not configured.', 'courier-delivery-connector-for-dalli' ) );
		}
		return $token;
	}

	/**
	 * POST an XML document.
	 *
	 * @param string              $xml  Request body.
	 * @param array<string,mixed> $args Options: timeout (seconds), retries (extra attempts), log_response (bool).
	 * @return string Raw response body.
	 * @throws ApiException On transport/HTTP failures.
	 */
	public function send( string $xml, array $args = array() ): string {
		$timeout      = (int) ( $args['timeout'] ?? 20 );
		$retries      = max( 0, (int) ( $args['retries'] ?? 2 ) );
		$log_response = ! array_key_exists( 'log_response', $args ) || $args['log_response'];
		$url          = $this->settings->api_url();

		$this->logger->debug( 'Request: ' . $xml );

		$attempt   = 0;
		$exception = new ApiException( ApiException::TYPE_TRANSPORT, 'The Dalli API request failed.' );
		do {
			if ( $attempt > 0 ) {
				call_user_func( $this->sleeper, 300 * ( 2 ** ( $attempt - 1 ) ) );
			}
			++$attempt;

			$response = wp_remote_post(
				$url,
				array(
					'timeout'     => $timeout,
					'redirection' => 0,
					'headers'     => array(
						'Content-Type' => 'text/xml; charset=UTF-8',
						'Accept'       => 'text/xml, application/xml, application/pdf',
						'User-Agent'   => 'DalliDeliveryForWooCommerce/' . ( defined( 'DALLI_DELIVERY_VERSION' ) ? DALLI_DELIVERY_VERSION : '1' ),
					),
					'body'        => $xml,
				)
			);

			if ( is_wp_error( $response ) ) {
				$exception = new ApiException( ApiException::TYPE_TRANSPORT, 'Could not reach the Dalli API: ' . $response->get_error_message() );
				$this->logger->error( $exception->getMessage() );
				continue;
			}

			$status = (int) wp_remote_retrieve_response_code( $response );
			$body   = (string) wp_remote_retrieve_body( $response );

			if ( $status >= 500 || 429 === $status ) {
				$exception = new ApiException( ApiException::TYPE_HTTP, sprintf( 'The Dalli API answered with HTTP %d.', $status ), (string) $status );
				$this->logger->error( $exception->getMessage() );
				continue;
			}
			if ( $status < 200 || $status >= 300 ) {
				$exception = new ApiException( ApiException::TYPE_HTTP, sprintf( 'The Dalli API answered with HTTP %d.', $status ), (string) $status );
				$this->logger->error( $exception->getMessage() );
				throw $exception;
			}

			if ( $log_response ) {
				$this->logger->debug( 'Response: ' . substr( $body, 0, 4000 ) );
			}
			return $body;
		} while ( $attempt <= $retries );

		throw $exception;
	}
}
