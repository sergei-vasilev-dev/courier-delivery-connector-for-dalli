<?php
/**
 * WooCommerce → Dalli tools: transfer act, orders needing attention, diagnostics.
 *
 * @package DalliDelivery
 */

namespace DalliDelivery\Admin;

use DalliDelivery\Orders\OrderData;
use DalliDelivery\Orders\OrderSync;
use DalliDelivery\Orders\StatusSync;
use DalliDelivery\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Small admin page for tasks that are not tied to one order.
 */
class ToolsPage {

	public const SLUG = 'dalli-delivery-tools';

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
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
	}

	/**
	 * Page URL.
	 *
	 * @return string
	 */
	public static function url(): string {
		return admin_url( 'admin.php?page=' . self::SLUG );
	}

	/**
	 * Add the submenu.
	 *
	 * @return void
	 */
	public function add_menu(): void {
		add_submenu_page(
			'woocommerce',
			__( 'Dalli tools', 'dalli-delivery-for-woocommerce' ),
			__( 'Dalli', 'dalli-delivery-for-woocommerce' ),
			'manage_woocommerce',
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Render the page.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'dalli-delivery-for-woocommerce' ), '', array( 'response' => 403 ) );
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Dalli delivery tools', 'dalli-delivery-for-woocommerce' ); ?></h1>
			<p>
				<a href="<?php echo esc_url( SettingsPage::url() ); ?>"><?php esc_html_e( 'Open settings', 'dalli-delivery-for-woocommerce' ); ?></a>
			</p>

			<h2><?php esc_html_e( 'Transfer act', 'dalli-delivery-for-woocommerce' ); ?></h2>
			<p><?php esc_html_e( 'Download the acceptance and transfer act (PDF) for all orders sent to Dalli today. Orders sent on other days are ignored by Dalli.', 'dalli-delivery-for-woocommerce' ); ?></p>
			<p>
				<a class="button button-primary" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=' . OrderActions::ACT_HOOK ), OrderActions::ACT_HOOK ) ); ?>">
					<?php esc_html_e( 'Download today\'s act', 'dalli-delivery-for-woocommerce' ); ?>
				</a>
			</p>

			<h2><?php esc_html_e( 'Orders that could not be sent', 'dalli-delivery-for-woocommerce' ); ?></h2>
			<?php $this->render_failed_orders(); ?>

			<h2><?php esc_html_e( 'Diagnostics', 'dalli-delivery-for-woocommerce' ); ?></h2>
			<table class="widefat striped" style="max-width:720px;">
				<tbody>
					<?php foreach ( self::diagnostics( $this->settings ) as $label => $value ) : ?>
						<tr>
							<th scope="row" style="width:35%;"><?php echo esc_html( $label ); ?></th>
							<td><?php echo esc_html( $value ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Orders with a recorded send error.
	 *
	 * @return void
	 */
	private function render_failed_orders(): void {
		$orders = wc_get_orders(
			array(
				'limit'      => 20,
				'orderby'    => 'date',
				'order'      => 'DESC',
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- small admin-only query.
					'relation' => 'AND',
					array(
						'key'     => OrderData::META_ERROR,
						'compare' => 'EXISTS',
					),
					array(
						'key'     => OrderData::META_BARCODE,
						'compare' => 'NOT EXISTS',
					),
				),
			)
		);
		if ( ! $orders ) {
			echo '<p>' . esc_html__( 'None. Everything went through.', 'dalli-delivery-for-woocommerce' ) . '</p>';
			return;
		}
		echo '<table class="widefat striped" style="max-width:900px;"><thead><tr><th>' . esc_html__( 'Order', 'dalli-delivery-for-woocommerce' ) . '</th><th>' . esc_html__( 'Error', 'dalli-delivery-for-woocommerce' ) . '</th></tr></thead><tbody>';
		foreach ( $orders as $order ) {
			if ( ! $order instanceof \WC_Order ) {
				continue;
			}
			printf(
				'<tr><td><a href="%1$s">#%2$s</a></td><td>%3$s</td></tr>',
				esc_url( $order->get_edit_order_url() ),
				esc_html( $order->get_order_number() ),
				esc_html( (string) $order->get_meta( OrderData::META_ERROR ) )
			);
		}
		echo '</tbody></table>';
	}

	/**
	 * Diagnostic values (also used by the WooCommerce system status report).
	 *
	 * @param Settings $settings Settings.
	 * @return array<string,string>
	 */
	public static function diagnostics( Settings $settings ): array {
		$yes = __( 'Yes', 'dalli-delivery-for-woocommerce' );
		$no  = __( 'No', 'dalli-delivery-for-woocommerce' );

		$next = '';
		if ( function_exists( 'as_next_scheduled_action' ) ) {
			$timestamp = as_next_scheduled_action( StatusSync::ACTION_RUN, array(), OrderSync::GROUP );
			if ( is_int( $timestamp ) && $timestamp > 0 ) {
				$next = wp_date( 'Y-m-d H:i:s', $timestamp );
			}
		}

		$host = wp_parse_url( $settings->api_url(), PHP_URL_HOST );

		return array(
			__( 'Plugin version', 'dalli-delivery-for-woocommerce' ) => DALLI_DELIVERY_VERSION,
			__( 'API endpoint host', 'dalli-delivery-for-woocommerce' ) => is_string( $host ) ? $host : '',
			__( 'Branch', 'dalli-delivery-for-woocommerce' ) => $settings->branch(),
			__( 'Mode', 'dalli-delivery-for-woocommerce' ) => (string) $settings->get( 'mode' ),
			__( 'Token configured', 'dalli-delivery-for-woocommerce' ) => $settings->has_token() ? $yes : $no,
			__( 'Token from wp-config.php', 'dalli-delivery-for-woocommerce' ) => Settings::token_is_constant( $settings->is_test_mode() ) ? $yes : $no,
			__( 'Order creation trigger', 'dalli-delivery-for-woocommerce' ) => (string) $settings->get( 'order_trigger' ),
			__( 'Status sync enabled', 'dalli-delivery-for-woocommerce' ) => $settings->is_enabled( 'sync_enabled' ) ? $yes : $no,
			__( 'Next status sync', 'dalli-delivery-for-woocommerce' ) => '' !== $next ? $next : __( 'Not scheduled', 'dalli-delivery-for-woocommerce' ),
			__( 'Debug log', 'dalli-delivery-for-woocommerce' ) => $settings->is_enabled( 'debug' ) ? $yes : $no,
		);
	}
}
