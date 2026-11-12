<?php
/**
 * Tests for the masked token field handling.
 *
 * @package DalliDelivery
 */

namespace DalliDelivery\Tests\Unit;

use Brain\Monkey\Functions;
use DalliDelivery\Admin\SettingsPage;
use DalliDelivery\Api\DalliApi;
use DalliDelivery\Settings;
use DalliDelivery\Tests\TestCase;

class SettingsPageTest extends TestCase {

	protected function tearDown(): void {
		unset( $_POST['dalli_delivery_token_clear'] );
		parent::tearDown();
	}

	private function page(): SettingsPage {
		return new SettingsPage( new Settings(), \Mockery::mock( DalliApi::class ) );
	}

	public function test_empty_submission_keeps_the_saved_token(): void {
		Functions\when( 'get_option' )->justReturn( 'SAVED_TOKEN_VALUE' );
		$this->assertSame( 'SAVED_TOKEN_VALUE', $this->page()->keep_secret( '', array( 'id' => 'dalli_delivery_token' ), '' ) );
	}

	public function test_a_new_value_replaces_the_token(): void {
		Functions\when( 'get_option' )->justReturn( 'SAVED_TOKEN_VALUE' );
		$this->assertSame( 'NEW_TOKEN', $this->page()->keep_secret( '  NEW_TOKEN ', array( 'id' => 'dalli_delivery_token' ), '' ) );
	}

	public function test_remove_checkbox_clears_the_token(): void {
		Functions\when( 'get_option' )->justReturn( 'SAVED_TOKEN_VALUE' );
		$_POST['dalli_delivery_token_clear'] = '1';
		$this->assertSame( '', $this->page()->keep_secret( '', array( 'id' => 'dalli_delivery_token' ), '' ) );
	}

	public function test_fields_never_contain_the_stored_token(): void {
		Functions\when( 'get_option' )->justReturn( 'SAVED_TOKEN_VALUE' );
		Functions\when( 'wc_get_order_statuses' )->justReturn( array( 'wc-processing' => 'Processing' ) );
		$fields = $this->page()->fields();
		$this->assertStringNotContainsString( 'SAVED_TOKEN_VALUE', (string) wp_json_encode( $fields ) );
		$types = array_column( $fields, 'type' );
		$this->assertContains( 'dalli_secret', $types, 'The token uses the masked custom field type.' );
		$this->assertNotContains( 'password', $types );
	}
}
