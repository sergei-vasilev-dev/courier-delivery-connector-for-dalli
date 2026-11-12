<?php
/**
 * WooCommerce → Settings → Shipping → Dalli.
 *
 * @package DalliDelivery
 */

namespace DalliDelivery\Admin;

use DalliDelivery\Api\ApiException;
use DalliDelivery\Api\DalliApi;
use DalliDelivery\Domain\Catalog;
use DalliDelivery\Plugin;
use DalliDelivery\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the "Dalli" section of the Shipping settings tab using the WooCommerce settings API.
 */
class SettingsPage {

	public const SECTION = 'dalli';

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * API (for the connection test).
	 *
	 * @var DalliApi
	 */
	private $api;

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Settings.
	 * @param DalliApi $api      API.
	 */
	public function __construct( Settings $settings, DalliApi $api ) {
		$this->settings = $settings;
		$this->api      = $api;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'woocommerce_get_sections_shipping', array( $this, 'add_section' ) );
		add_filter( 'woocommerce_get_settings_shipping', array( $this, 'get_settings' ), 10, 2 );
		add_action( 'woocommerce_admin_field_dalli_secret', array( $this, 'render_secret_field' ) );
		add_action( 'woocommerce_admin_field_dalli_test_connection', array( $this, 'render_test_connection' ) );
		add_filter( 'woocommerce_admin_settings_sanitize_option_' . Settings::PREFIX . 'token', array( $this, 'keep_secret' ), 10, 3 );
		add_filter( 'woocommerce_admin_settings_sanitize_option_' . Settings::PREFIX . 'test_token', array( $this, 'keep_secret' ), 10, 3 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'wp_ajax_dalli_delivery_test_connection', array( $this, 'ajax_test_connection' ) );
		add_action( 'admin_notices', array( $this, 'maybe_show_notices' ) );
	}

	/**
	 * Add the section link.
	 *
	 * @param array<string,string> $sections Sections.
	 * @return array<string,string>
	 */
	public function add_section( $sections ) {
		$sections[ self::SECTION ] = __( 'Dalli', 'courier-delivery-connector-for-dalli' );
		return $sections;
	}

	/**
	 * Settings URL.
	 *
	 * @return string
	 */
	public static function url(): string {
		return admin_url( 'admin.php?page=wc-settings&tab=shipping&section=' . self::SECTION );
	}

	/**
	 * Provide the fields for the section.
	 *
	 * @param array<int,array<string,mixed>> $settings        Existing settings.
	 * @param string                         $current_section Section.
	 * @return array<int,array<string,mixed>>
	 */
	public function get_settings( $settings, $current_section = '' ) {
		if ( self::SECTION !== $current_section ) {
			return $settings;
		}
		return $this->fields();
	}

	/**
	 * Build one field definition.
	 *
	 * @param string              $key  Setting key.
	 * @param array<string,mixed> $args Field arguments.
	 * @return array<string,mixed>
	 */
	private function field( string $key, array $args ): array {
		$defaults = Settings::defaults();
		return array_merge(
			array(
				'id'      => Settings::PREFIX . $key,
				'default' => $defaults[ $key ] ?? '',
			),
			$args
		);
	}

	/**
	 * All fields.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function fields(): array {
		$gateways = array();
		if ( function_exists( 'WC' ) && WC()->payment_gateways() ) {
			foreach ( WC()->payment_gateways()->payment_gateways() as $gateway ) {
				$gateways[ $gateway->id ] = wp_strip_all_tags( $gateway->get_method_title() );
			}
		}

		$order_statuses = array();
		foreach ( wc_get_order_statuses() as $slug => $label ) {
			$status_key                    = 0 === strpos( $slug, 'wc-' ) ? substr( $slug, 3 ) : $slug;
			$order_statuses[ $status_key ] = $label;
		}

		$fields = array();

		$fields[] = array(
			'title' => __( 'Dalli connection', 'courier-delivery-connector-for-dalli' ),
			'type'  => 'title',
			'desc'  => __( 'Unofficial integration. Not affiliated with or endorsed by Dalli. Get your token in the Dalli personal account (Account section).', 'courier-delivery-connector-for-dalli' ),
			'id'    => 'dalli_delivery_connection',
		);
		$fields[] = $this->field(
			'branch',
			array(
				'title'    => __( 'Branch', 'courier-delivery-connector-for-dalli' ),
				'type'     => 'select',
				'class'    => 'wc-enhanced-select',
				'options'  => array(
					Settings::BRANCH_MSK => __( 'Moscow (api.dalli-service.com)', 'courier-delivery-connector-for-dalli' ),
					Settings::BRANCH_SPB => __( 'St. Petersburg (spbapi.dalli-service.com)', 'courier-delivery-connector-for-dalli' ),
				),
				'desc'     => __( 'The branch you have the contract with.', 'courier-delivery-connector-for-dalli' ),
				'desc_tip' => true,
			)
		);
		$fields[] = $this->field(
			'mode',
			array(
				'title'   => __( 'Mode', 'courier-delivery-connector-for-dalli' ),
				'type'    => 'select',
				'class'   => 'wc-enhanced-select',
				'options' => array(
					Settings::MODE_LIVE => __( 'Live', 'courier-delivery-connector-for-dalli' ),
					Settings::MODE_TEST => __( 'Test platform (shared, never use real customer data)', 'courier-delivery-connector-for-dalli' ),
				),
			)
		);
		$fields[] = $this->field(
			'token',
			array(
				'title' => __( 'API token', 'courier-delivery-connector-for-dalli' ),
				'type'  => 'dalli_secret',
				'desc'  => __( 'You can also define DALLI_TOKEN in wp-config.php.', 'courier-delivery-connector-for-dalli' ),
				'const' => 'DALLI_TOKEN',
			)
		);
		$fields[] = $this->field(
			'test_token',
			array(
				'title' => __( 'Test API token', 'courier-delivery-connector-for-dalli' ),
				'type'  => 'dalli_secret',
				'desc'  => __( 'Token of the shared Dalli test platform. You can also define DALLI_TEST_TOKEN in wp-config.php.', 'courier-delivery-connector-for-dalli' ),
				'const' => 'DALLI_TEST_TOKEN',
			)
		);
		$fields[] = $this->field(
			'sender_code',
			array(
				'title'    => __( 'Sender code', 'courier-delivery-connector-for-dalli' ),
				'type'     => 'text',
				'desc'     => __( 'Pickup address code from your Dalli manager. Required for rates from regional branches; leave empty otherwise.', 'courier-delivery-connector-for-dalli' ),
				'desc_tip' => true,
			)
		);
		$fields[] = array(
			'title' => __( 'Connection test', 'courier-delivery-connector-for-dalli' ),
			'type'  => 'dalli_test_connection',
			'id'    => 'dalli_delivery_test_connection',
		);
		$fields[] = array(
			'type' => 'sectionend',
			'id'   => 'dalli_delivery_connection',
		);

		$fields[] = array(
			'title' => __( 'Parcel defaults', 'courier-delivery-connector-for-dalli' ),
			'type'  => 'title',
			'desc'  => __( 'Used when a product has no weight or dimensions. Product weight and dimensions use the native WooCommerce fields.', 'courier-delivery-connector-for-dalli' ),
			'id'    => 'dalli_delivery_parcel',
		);
		foreach ( array(
			'default_weight' => __( 'Default product weight, kg', 'courier-delivery-connector-for-dalli' ),
			'default_length' => __( 'Default product length, cm', 'courier-delivery-connector-for-dalli' ),
			'default_width'  => __( 'Default product width, cm', 'courier-delivery-connector-for-dalli' ),
			'default_height' => __( 'Default product height, cm', 'courier-delivery-connector-for-dalli' ),
			'box_weight'     => __( 'Packaging weight, kg', 'courier-delivery-connector-for-dalli' ),
			'box_length'     => __( 'Packaging box length, cm', 'courier-delivery-connector-for-dalli' ),
			'box_width'      => __( 'Packaging box width, cm', 'courier-delivery-connector-for-dalli' ),
			'box_height'     => __( 'Packaging box height, cm', 'courier-delivery-connector-for-dalli' ),
		) as $key => $title ) {
			$fields[] = $this->field(
				$key,
				array(
					'title'             => $title,
					'type'              => 'number',
					'custom_attributes' => array(
						'min'  => '0',
						'step' => 'any',
					),
					'css'               => 'width:100px;',
				)
			);
		}
		$fields[] = array(
			'type' => 'sectionend',
			'id'   => 'dalli_delivery_parcel',
		);

		$fields[] = array(
			'title' => __( 'Prices and declared value', 'courier-delivery-connector-for-dalli' ),
			'type'  => 'title',
			'desc'  => __( 'Markup, free shipping and fixed price are configured per shipping zone, in the "Dalli delivery" method settings.', 'courier-delivery-connector-for-dalli' ),
			'id'    => 'dalli_delivery_pricing',
		);
		$fields[] = $this->field(
			'declared_value',
			array(
				'title'   => __( 'Declared value', 'courier-delivery-connector-for-dalli' ),
				'type'    => 'select',
				'class'   => 'wc-enhanced-select',
				'options' => array(
					'items'   => __( 'Full value of the items', 'courier-delivery-connector-for-dalli' ),
					'percent' => __( 'A percentage of the items value', 'courier-delivery-connector-for-dalli' ),
				),
			)
		);
		$fields[] = $this->field(
			'declared_percent',
			array(
				'title'             => __( 'Declared value, %', 'courier-delivery-connector-for-dalli' ),
				'type'              => 'number',
				'custom_attributes' => array(
					'min'  => '0',
					'max'  => '100',
					'step' => '1',
				),
				'css'               => 'width:100px;',
			)
		);
		$fields[] = $this->field(
			'rounding_step',
			array(
				'title'   => __( 'Round delivery price to', 'courier-delivery-connector-for-dalli' ),
				'type'    => 'select',
				'class'   => 'wc-enhanced-select',
				'options' => array(
					'0'   => __( 'Kopecks (no rounding)', 'courier-delivery-connector-for-dalli' ),
					'1'   => __( '1 rouble', 'courier-delivery-connector-for-dalli' ),
					'5'   => __( '5 roubles', 'courier-delivery-connector-for-dalli' ),
					'10'  => __( '10 roubles', 'courier-delivery-connector-for-dalli' ),
					'50'  => __( '50 roubles', 'courier-delivery-connector-for-dalli' ),
					'100' => __( '100 roubles', 'courier-delivery-connector-for-dalli' ),
				),
			)
		);
		$fields[] = $this->field(
			'rounding_direction',
			array(
				'title'   => __( 'Rounding direction', 'courier-delivery-connector-for-dalli' ),
				'type'    => 'select',
				'class'   => 'wc-enhanced-select',
				'options' => array(
					'nearest' => __( 'To the nearest value', 'courier-delivery-connector-for-dalli' ),
					'up'      => __( 'Up', 'courier-delivery-connector-for-dalli' ),
				),
			)
		);
		$fields[] = array(
			'type' => 'sectionend',
			'id'   => 'dalli_delivery_pricing',
		);

		$fields[] = array(
			'title' => __( 'Payment methods', 'courier-delivery-connector-for-dalli' ),
			'type'  => 'title',
			'desc'  => __( 'Tell Dalli how the customer pays. Any gateway that is not listed below is treated as prepaid (the courier collects nothing).', 'courier-delivery-connector-for-dalli' ),
			'id'    => 'dalli_delivery_payment',
		);
		$fields[] = $this->field(
			'cod_gateways',
			array(
				'title'   => __( 'Cash on receipt', 'courier-delivery-connector-for-dalli' ),
				'type'    => 'multiselect',
				'class'   => 'wc-enhanced-select',
				'options' => $gateways,
			)
		);
		$fields[] = $this->field(
			'card_gateways',
			array(
				'title'    => __( 'Card on receipt', 'courier-delivery-connector-for-dalli' ),
				'type'     => 'multiselect',
				'class'    => 'wc-enhanced-select',
				'options'  => $gateways,
				'desc'     => __( 'Only Dalli couriers accept cards; for partners the order is sent as cash on receipt.', 'courier-delivery-connector-for-dalli' ),
				'desc_tip' => true,
			)
		);
		$fields[] = array(
			'type' => 'sectionend',
			'id'   => 'dalli_delivery_payment',
		);

		$fields[] = array(
			'title' => __( 'Orders', 'courier-delivery-connector-for-dalli' ),
			'type'  => 'title',
			'id'    => 'dalli_delivery_orders',
		);
		$fields[] = $this->field(
			'order_trigger',
			array(
				'title'   => __( 'Create the order in Dalli', 'courier-delivery-connector-for-dalli' ),
				'type'    => 'select',
				'class'   => 'wc-enhanced-select',
				'options' => array(
					'processing' => __( 'When the order becomes "Processing" (paid)', 'courier-delivery-connector-for-dalli' ),
					'completed'  => __( 'When the order becomes "Completed"', 'courier-delivery-connector-for-dalli' ),
					'manual'     => __( 'Manually, with a button on the order screen', 'courier-delivery-connector-for-dalli' ),
				),
			)
		);
		$fields[] = $this->field(
			'order_prefix',
			array(
				'title'    => __( 'Order number prefix', 'courier-delivery-connector-for-dalli' ),
				'type'     => 'text',
				'desc'     => __( 'Added to the WooCommerce order number. Order numbers must be unique across your Dalli account; use a prefix when several shops share it.', 'courier-delivery-connector-for-dalli' ),
				'desc_tip' => true,
			)
		);
		$fields[] = $this->field(
			'item_vat',
			array(
				'title'   => __( 'VAT rate of items', 'courier-delivery-connector-for-dalli' ),
				'type'    => 'select',
				'class'   => 'wc-enhanced-select',
				'options' => array(
					'22' => '22%',
					'20' => '20%',
					'10' => '10%',
					'7'  => '7%',
					'5'  => '5%',
					'0'  => __( 'No VAT', 'courier-delivery-connector-for-dalli' ),
				),
			)
		);
		$fields[] = $this->field(
			'accept_partially',
			array(
				'title'   => __( 'Partial redemption', 'courier-delivery-connector-for-dalli' ),
				'type'    => 'select',
				'class'   => 'wc-enhanced-select',
				'options' => array(
					''    => __( 'Use the account default', 'courier-delivery-connector-for-dalli' ),
					'YES' => __( 'Allowed', 'courier-delivery-connector-for-dalli' ),
					'NO'  => __( 'Not allowed', 'courier-delivery-connector-for-dalli' ),
				),
			)
		);
		$fields[] = $this->field(
			'default_time_min',
			array(
				'title' => __( 'Default delivery time from', 'courier-delivery-connector-for-dalli' ),
				'type'  => 'time',
				'css'   => 'width:100px;',
			)
		);
		$fields[] = $this->field(
			'default_time_max',
			array(
				'title' => __( 'Default delivery time to', 'courier-delivery-connector-for-dalli' ),
				'type'  => 'time',
				'css'   => 'width:100px;',
			)
		);
		$fields[] = $this->field(
			'choose_interval',
			array(
				'title' => __( 'Delivery interval', 'courier-delivery-connector-for-dalli' ),
				'type'  => 'checkbox',
				'desc'  => __( 'Let customers choose the delivery date and time for Dalli courier delivery', 'courier-delivery-connector-for-dalli' ),
			)
		);
		$fields[] = $this->field(
			'sticker_format',
			array(
				'title'   => __( 'Sticker (label) format', 'courier-delivery-connector-for-dalli' ),
				'type'    => 'select',
				'class'   => 'wc-enhanced-select',
				'options' => array(
					'1' => __( 'Zebra', 'courier-delivery-connector-for-dalli' ),
					'2' => __( 'A4 with frame', 'courier-delivery-connector-for-dalli' ),
					'3' => __( 'A4 without frame', 'courier-delivery-connector-for-dalli' ),
					'4' => __( 'A6', 'courier-delivery-connector-for-dalli' ),
					'5' => __( '100 x 50 mm', 'courier-delivery-connector-for-dalli' ),
					'6' => __( '105 x 148 mm', 'courier-delivery-connector-for-dalli' ),
					'7' => __( '75 x 120 mm', 'courier-delivery-connector-for-dalli' ),
				),
			)
		);
		$fields[] = array(
			'type' => 'sectionend',
			'id'   => 'dalli_delivery_orders',
		);

		$fields[] = array(
			'title' => __( 'Tracking', 'courier-delivery-connector-for-dalli' ),
			'type'  => 'title',
			'id'    => 'dalli_delivery_tracking',
		);
		$fields[] = $this->field(
			'sync_enabled',
			array(
				'title' => __( 'Status synchronisation', 'courier-delivery-connector-for-dalli' ),
				'type'  => 'checkbox',
				'desc'  => __( 'Refresh delivery statuses in the background', 'courier-delivery-connector-for-dalli' ),
			)
		);
		$fields[] = $this->field(
			'sync_interval',
			array(
				'title'   => __( 'Synchronisation interval', 'courier-delivery-connector-for-dalli' ),
				'type'    => 'select',
				'class'   => 'wc-enhanced-select',
				'options' => array(
					'5'  => __( 'Every 5 minutes', 'courier-delivery-connector-for-dalli' ),
					'15' => __( 'Every 15 minutes', 'courier-delivery-connector-for-dalli' ),
					'30' => __( 'Every 30 minutes', 'courier-delivery-connector-for-dalli' ),
					'60' => __( 'Every hour', 'courier-delivery-connector-for-dalli' ),
				),
			)
		);
		$fields[] = $this->field(
			'sync_mode',
			array(
				'title'    => __( 'Synchronisation method', 'courier-delivery-connector-for-dalli' ),
				'type'     => 'select',
				'class'    => 'wc-enhanced-select',
				'options'  => array(
					'per_order' => __( 'Query every open order (safe default)', 'courier-delivery-connector-for-dalli' ),
					'changes'   => __( 'Account-wide "changed statuses" feed (fewer requests)', 'courier-delivery-connector-for-dalli' ),
				),
				'desc'     => __( 'The changes feed is shared by everything that uses your Dalli account: if another system reads it, each of you will miss updates. Use it only when this shop is the sole consumer.', 'courier-delivery-connector-for-dalli' ),
				'desc_tip' => true,
			)
		);
		$fields[] = $this->field(
			'tracking_url',
			array(
				'title'       => __( 'Tracking link', 'courier-delivery-connector-for-dalli' ),
				'type'        => 'text',
				'placeholder' => 'https://example.com/track/{barcode}',
				'desc'        => __( 'Optional public tracking page. Use {barcode} where the tracking number goes. Shown to customers when set.', 'courier-delivery-connector-for-dalli' ),
				'desc_tip'    => true,
			)
		);
		$fields[] = array(
			'type' => 'sectionend',
			'id'   => 'dalli_delivery_tracking',
		);

		$fields[] = array(
			'title' => __( 'Order status mapping', 'courier-delivery-connector-for-dalli' ),
			'type'  => 'title',
			'desc'  => __( 'Optionally change the WooCommerce order status when the delivery status changes. Leave "Do not change" to keep control of order statuses. Moving to "Completed" sends the customer e-mail.', 'courier-delivery-connector-for-dalli' ),
			'id'    => 'dalli_delivery_status_map_section',
		);
		foreach ( Catalog::statuses() as $code => $label ) {
			$fields[] = array(
				'title'   => $label . ' (' . $code . ')',
				'id'      => Settings::PREFIX . 'status_map[' . $code . ']',
				'type'    => 'select',
				'class'   => 'wc-enhanced-select',
				'default' => '',
				'options' => array( '' => __( 'Do not change', 'courier-delivery-connector-for-dalli' ) ) + $order_statuses,
			);
		}
		$fields[] = array(
			'type' => 'sectionend',
			'id'   => 'dalli_delivery_status_map_section',
		);

		$fields[] = array(
			'title' => __( 'Logging', 'courier-delivery-connector-for-dalli' ),
			'type'  => 'title',
			'id'    => 'dalli_delivery_logging',
		);
		$fields[] = $this->field(
			'debug',
			array(
				'title' => __( 'Debug log', 'courier-delivery-connector-for-dalli' ),
				'type'  => 'checkbox',
				'desc'  => sprintf(
					/* translators: %s: log source name */
					__( 'Log API requests and responses (source %s, see WooCommerce → Status → Logs). Logs contain customer data; the token is always redacted.', 'courier-delivery-connector-for-dalli' ),
					'dalli-delivery'
				),
			)
		);
		$fields[] = array(
			'type' => 'sectionend',
			'id'   => 'dalli_delivery_logging',
		);

		return $fields;
	}

	/**
	 * Render a masked secret field. The stored value is never printed.
	 *
	 * @param array<string,mixed> $value Field definition.
	 * @return void
	 */
	public function render_secret_field( $value ): void {
		$id          = (string) $value['id'];
		$key         = substr( $id, strlen( Settings::PREFIX ) );
		$is_test     = 'test_token' === $key;
		$stored      = (string) get_option( $id, '' );
		$const       = (string) ( $value['const'] ?? '' );
		$is_const    = Settings::token_is_constant( $is_test );
		$placeholder = '' !== $stored ? Settings::mask( $stored ) : '';
		?>
		<tr valign="top">
			<th scope="row" class="titledesc">
				<label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( (string) $value['title'] ); ?></label>
			</th>
			<td class="forminp forminp-password">
				<?php if ( $is_const ) : ?>
					<input type="text" class="regular-text" value="<?php echo esc_attr( Settings::mask( 'defined-in-wp-config' ) ); ?>" disabled="disabled" />
					<p class="description">
						<?php
						/* translators: %s: PHP constant name */
						echo esc_html( sprintf( __( 'Defined by the %s constant in wp-config.php.', 'courier-delivery-connector-for-dalli' ), $const ) );
						?>
					</p>
				<?php else : ?>
					<input
						name="<?php echo esc_attr( $id ); ?>"
						id="<?php echo esc_attr( $id ); ?>"
						type="password"
						class="regular-text"
						autocomplete="new-password"
						spellcheck="false"
						value=""
						placeholder="<?php echo esc_attr( '' !== $placeholder ? $placeholder : __( 'Not set', 'courier-delivery-connector-for-dalli' ) ); ?>"
					/>
					<?php if ( '' !== $stored ) : ?>
						<label>
							<input type="checkbox" name="<?php echo esc_attr( $id . '_clear' ); ?>" value="1" />
							<?php esc_html_e( 'Remove the saved token', 'courier-delivery-connector-for-dalli' ); ?>
						</label>
					<?php endif; ?>
					<p class="description">
						<?php echo esc_html( (string) ( $value['desc'] ?? '' ) ); ?>
						<?php if ( '' !== $stored ) : ?>
							<?php esc_html_e( 'Leave empty to keep the saved token.', 'courier-delivery-connector-for-dalli' ); ?>
						<?php endif; ?>
					</p>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	/**
	 * Keep the stored secret when the field was left empty; clear it when the checkbox is ticked.
	 *
	 * @param mixed               $value     Sanitised value.
	 * @param array<string,mixed> $option    Field definition.
	 * @param mixed               $raw_value Raw value.
	 * @return string
	 */
	public function keep_secret( $value, $option, $raw_value ) {
		unset( $raw_value );
		$id = (string) ( $option['id'] ?? '' );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verifies the settings nonce before saving.
		if ( isset( $_POST[ $id . '_clear' ] ) ) {
			return '';
		}
		$value = trim( (string) $value );
		return '' === $value ? (string) get_option( $id, '' ) : $value;
	}

	/**
	 * Render the connection test row.
	 *
	 * @return void
	 */
	public function render_test_connection(): void {
		?>
		<tr valign="top">
			<th scope="row" class="titledesc"><?php esc_html_e( 'Connection test', 'courier-delivery-connector-for-dalli' ); ?></th>
			<td class="forminp">
				<button type="button" class="button" id="dalli-delivery-test-connection"><?php esc_html_e( 'Test connection', 'courier-delivery-connector-for-dalli' ); ?></button>
				<span id="dalli-delivery-test-result" role="status" aria-live="polite" style="margin-left:10px;"></span>
				<p class="description"><?php esc_html_e( 'Calls the harmless "services" method with the saved settings. Save your changes first.', 'courier-delivery-connector-for-dalli' ); ?></p>
			</td>
		</tr>
		<?php
	}

	/**
	 * Enqueue the settings script on our section only.
	 *
	 * @param string $hook Admin page hook.
	 * @return void
	 */
	public function enqueue( $hook ): void {
		unset( $hook );
		if ( ! $this->is_our_screen() ) {
			return;
		}
		wp_enqueue_script( 'dalli-delivery-admin-settings', DALLI_DELIVERY_URL . 'assets/js/admin-settings.js', array(), Plugin::asset_version( 'assets/js/admin-settings.js' ), true );
		wp_localize_script(
			'dalli-delivery-admin-settings',
			'dalliDeliveryAdmin',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'dalli_delivery_admin' ),
				'working' => __( 'Checking…', 'courier-delivery-connector-for-dalli' ),
				'failed'  => __( 'The request failed.', 'courier-delivery-connector-for-dalli' ),
			)
		);
	}

	/**
	 * Whether the current admin screen is our settings section.
	 *
	 * @return bool
	 */
	private function is_our_screen(): bool {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only screen detection.
		return isset( $_GET['page'], $_GET['tab'], $_GET['section'] )
			&& 'wc-settings' === sanitize_key( wp_unslash( $_GET['page'] ) )
			&& 'shipping' === sanitize_key( wp_unslash( $_GET['tab'] ) )
			&& self::SECTION === sanitize_key( wp_unslash( $_GET['section'] ) );
		// phpcs:enable
	}

	/**
	 * Admin notices: missing token and test mode.
	 *
	 * @return void
	 */
	public function maybe_show_notices(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) || ! $this->is_our_screen() ) {
			return;
		}
		if ( $this->settings->is_test_mode() ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'Dalli test mode is on. The test platform is shared by all users: never send real customer data to it.', 'courier-delivery-connector-for-dalli' ) . '</p></div>';
		}
		if ( ! $this->settings->has_token() ) {
			echo '<div class="notice notice-info"><p>' . esc_html__( 'Enter your Dalli API token to enable delivery rates.', 'courier-delivery-connector-for-dalli' ) . '</p></div>';
		}
	}

	/**
	 * AJAX: test the connection.
	 *
	 * @return void
	 */
	public function ajax_test_connection(): void {
		check_ajax_referer( 'dalli_delivery_admin', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'courier-delivery-connector-for-dalli' ) ), 403 );
		}
		try {
			$services = $this->api->services();
		} catch ( ApiException $e ) {
			wp_send_json_error( array( 'message' => $e->display_message() ) );
		}
		wp_send_json_success(
			array(
				'message' => sprintf(
					/* translators: %d: number of delivery types available in the contract */
					_n( 'Connected. %d delivery type is available in your contract.', 'Connected. %d delivery types are available in your contract.', count( $services ), 'courier-delivery-connector-for-dalli' ),
					count( $services )
				),
			)
		);
	}
}
