<?php
/**
 * Tests for the HTTP client: retries, error mapping, secrecy of the token.
 *
 * @package DalliDelivery
 */

namespace DalliDelivery\Tests\Unit;

use Brain\Monkey\Functions;
use DalliDelivery\Api\ApiException;
use DalliDelivery\Api\Client;
use DalliDelivery\Settings;
use DalliDelivery\Support\Logger;
use DalliDelivery\Tests\TestCase;

class ClientTest extends TestCase {

	/**
	 * Fake options.
	 *
	 * @var array<string,mixed>
	 */
	private $options = array();

	/**
	 * Recorded sleeps.
	 *
	 * @var int[]
	 */
	private $sleeps = array();

	protected function setUp(): void {
		parent::setUp();
		$this->options = array( 'dalli_delivery_token' => 'FAKE_TOKEN_1234567890' );
		$this->sleeps  = array();
		$options       = &$this->options;
		Functions\when( 'get_option' )->alias(
			static function ( $key, $default = false ) use ( &$options ) {
				return array_key_exists( $key, $options ) ? $options[ $key ] : $default;
			}
		);
		Functions\when( 'wp_remote_retrieve_response_code' )->alias(
			static function ( $response ) {
				return $response['response']['code'];
			}
		);
		Functions\when( 'wp_remote_retrieve_body' )->alias(
			static function ( $response ) {
				return $response['body'];
			}
		);
		Functions\when( 'wc_get_logger' )->justReturn( \Mockery::mock()->shouldIgnoreMissing() );
	}

	private function client(): Client {
		$settings = new Settings();
		$client   = new Client( $settings, new Logger( $settings ) );
		$client->set_sleeper(
			function ( int $ms ): void {
				$this->sleeps[] = $ms;
			}
		);
		return $client;
	}

	private static function response( int $code, string $body ): array {
		return array(
			'response' => array( 'code' => $code ),
			'body'     => $body,
		);
	}

	public function test_posts_xml_to_the_branch_endpoint(): void {
		Functions\expect( 'wp_remote_post' )
			->once()
			->andReturnUsing(
				function ( $url, $args ) {
					$this->assertSame( 'https://api.dalli-service.com/v1/', $url );
					$this->assertSame( '<x/>', $args['body'] );
					$this->assertSame( 'text/xml; charset=UTF-8', $args['headers']['Content-Type'] );
					$this->assertSame( 0, $args['redirection'] );
					$this->assertStringNotContainsString( 'FAKE_TOKEN', $args['headers']['User-Agent'] );
					return self::response( 200, '<ok/>' );
				}
			);
		$this->assertSame( '<ok/>', $this->client()->send( '<x/>' ) );
	}

	public function test_spb_branch_uses_the_spb_endpoint(): void {
		$this->options['dalli_delivery_branch'] = 'spb';
		Functions\expect( 'wp_remote_post' )
			->once()
			->andReturnUsing(
				function ( $url ) {
					$this->assertSame( 'https://spbapi.dalli-service.com/v1/', $url );
					return self::response( 200, '<ok/>' );
				}
			);
		$this->client()->send( '<x/>' );
	}

	public function test_retries_server_errors_with_backoff_then_succeeds(): void {
		Functions\expect( 'wp_remote_post' )
			->times( 3 )
			->andReturn( self::response( 503, '' ), self::response( 502, '' ), self::response( 200, '<ok/>' ) );
		$this->assertSame( '<ok/>', $this->client()->send( '<x/>', array( 'retries' => 2 ) ) );
		$this->assertSame( array( 300, 600 ), $this->sleeps );
	}

	public function test_gives_up_after_the_retries_are_used(): void {
		Functions\expect( 'wp_remote_post' )->times( 2 )->andReturn( self::response( 500, '' ) );
		try {
			$this->client()->send( '<x/>', array( 'retries' => 1 ) );
			$this->fail( 'Expected an exception.' );
		} catch ( ApiException $e ) {
			$this->assertSame( ApiException::TYPE_HTTP, $e->get_type() );
			$this->assertTrue( $e->is_retryable() );
		}
	}

	public function test_transport_errors_are_retryable_and_never_leak_the_token(): void {
		Functions\expect( 'wp_remote_post' )->times( 2 )->andReturn( new \WP_Error( 'http_request_failed', 'cURL error 28: timeout' ) );
		try {
			$this->client()->send( '<x token="FAKE_TOKEN_1234567890"/>', array( 'retries' => 1 ) );
			$this->fail( 'Expected an exception.' );
		} catch ( ApiException $e ) {
			$this->assertSame( ApiException::TYPE_TRANSPORT, $e->get_type() );
			$this->assertTrue( $e->is_retryable() );
			$this->assertStringNotContainsString( 'FAKE_TOKEN', $e->getMessage() );
		}
	}

	public function test_client_errors_are_not_retried(): void {
		Functions\expect( 'wp_remote_post' )->once()->andReturn( self::response( 404, 'nope' ) );
		try {
			$this->client()->send( '<x/>', array( 'retries' => 3 ) );
			$this->fail( 'Expected an exception.' );
		} catch ( ApiException $e ) {
			$this->assertSame( ApiException::TYPE_HTTP, $e->get_type() );
			$this->assertFalse( $e->is_retryable() );
		}
		$this->assertSame( array(), $this->sleeps );
	}

	public function test_missing_token_raises_a_config_error(): void {
		$this->options['dalli_delivery_token'] = '';
		$this->expectException( ApiException::class );
		$this->client()->token();
	}

	public function test_test_mode_uses_the_test_token(): void {
		$this->options['dalli_delivery_mode']       = 'test';
		$this->options['dalli_delivery_test_token'] = ' TEST_ONLY_TOKEN ';
		$this->assertSame( 'TEST_ONLY_TOKEN', $this->client()->token() );
	}

	public function test_debug_logging_redacts_the_token(): void {
		$this->options['dalli_delivery_debug'] = 'yes';
		$logged                                = array();
		$logger                                = \Mockery::mock();
		$logger->shouldReceive( 'log' )->andReturnUsing(
			static function ( $level, $message ) use ( &$logged ) {
				$logged[] = $message;
			}
		);
		Functions\when( 'wc_get_logger' )->justReturn( $logger );
		Functions\expect( 'wp_remote_post' )->once()->andReturn( self::response( 200, '<ok token="FAKE_TOKEN_1234567890"/>' ) );

		$this->client()->send( '<services><auth token="FAKE_TOKEN_1234567890"/></services>' );

		$this->assertNotEmpty( $logged );
		foreach ( $logged as $message ) {
			$this->assertStringNotContainsString( 'FAKE_TOKEN_1234567890', $message );
		}
	}
}
