<?php
/**
 * Base test case.
 *
 * @package DalliDelivery
 */

namespace DalliDelivery\Tests;

use Brain\Monkey;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase as PHPUnitTestCase;

/**
 * Sets up Brain Monkey with the WordPress functions every unit needs.
 */
abstract class TestCase extends PHPUnitTestCase {

	use MockeryPHPUnitIntegration;

	/**
	 * Set up.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Monkey\Functions\stubTranslationFunctions();
		Monkey\Functions\stubEscapeFunctions();
		Monkey\Functions\stubs(
			array(
				'wp_json_encode'         => static function ( $data ) {
					return json_encode( $data ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
				},
				'wp_strip_all_tags'      => static function ( $text ) {
					return trim( strip_tags( (string) $text ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags
				},
				'sanitize_key'           => static function ( $key ) {
					return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $key ) );
				},
				'_n'                     => static function ( $single, $plural, $number ) {
					return 1 === (int) $number ? $single : $plural;
				},
				'wp_specialchars_decode' => static function ( $text ) {
					return htmlspecialchars_decode( (string) $text, ENT_QUOTES );
				},
				'is_wp_error'            => static function ( $thing ) {
					return $thing instanceof \WP_Error;
				},
			)
		);
	}

	/**
	 * Tear down.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Load an XML fixture.
	 *
	 * @param string $name File name inside tests/fixtures.
	 * @return string
	 */
	protected function fixture( string $name ): string {
		return (string) file_get_contents( __DIR__ . '/fixtures/' . $name ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	}
}
