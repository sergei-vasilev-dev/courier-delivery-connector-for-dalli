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
			__( 'Dalli tools', 'kurvio-courier-connector-for-dalli' ),
			__( 'Dalli', 'kurvio-courier-connector-for-dalli' ),
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
			wp_die( esc_html__( 'You are not allowed to do this.', 'kurvio-courier-connector-for-dalli' ), '', array( 'response' => 403 ) );
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Dalli delivery tools', 'kurvio-courier-connector-for-dalli' ); ?></h1>
			<p>
				<a href="<?php echo esc_url( SettingsPage::url() ); ?>"><?php esc_html_e( 'Open settings', 'kurvio-courier-connector-for-dalli' ); ?></a>
			</p>

			<h2><?php esc_html_e( 'Transfer act', 'kurvio-courier-connector-for-dalli' ); ?></h2>
			<p><?php esc_html_e( 'Download the acceptance and transfer act (PDF) for all orders sent to Dalli today. Orders sent on other days are ignored by Dalli.', 'kurvio-courier-connector-for-dalli' ); ?></p>
			<p>
				<a class="button button-primary" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=' . OrderActions::ACT_HOOK ), OrderActions::ACT_HOOK ) ); ?>">
					<?php esc_html_e( 'Download today\'s act', 'kurvio-courier-connector-for-dalli' ); ?>
				</a>
			</p>

			<h2><?php esc_html_e( 'Orders that could not be sent', 'kurvio-courier-connector-for-dalli' ); ?></h2>
			<?php $this->render_failed_orders(); ?>

			<h2><?php esc_html_e( 'Diagnostics', 'kurvio-courier-connector-for-dalli' ); ?></h2>
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
			echo '<p>' . esc_html__( 'None. Everything went through.', 'kurvio-courier-connector-for-dalli' ) . '</p>';
			return;
		}
		echo '<table class="widefat striped" style="max-width:900px;"><thead><tr><th>' . esc_html__( 'Order', 'kurvio-courier-connector-for-dalli' ) . '</th><th>' . esc_html__( 'Error', 'kurvio-courier-connector-for-dalli' ) . '</th></tr></thead><tbody>';
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
		$yes = __( 'Yes', 'kurvio-courier-connector-for-dalli' );
		$no  = __( 'No', 'kurvio-courier-connector-for-dalli' );

		$next = '';
		if ( function_exists( 'as_next_scheduled_action' ) ) {
			$timestamp = as_next_scheduled_action( StatusSync::ACTION_RUN, array(), OrderSync::GROUP );
			if ( is_int( $timestamp ) && $timestamp > 0 ) {
				$next = wp_date( 'Y-m-d H:i:s', $timestamp );
			}
		}

		$host = wp_parse_url( $settings->api_url(), PHP_URL_HOST );

		return array(
			__( 'Plugin version', 'kurvio-courier-connector-for-dalli' ) => DALLI_DELIVERY_VERSION,
			__( 'API endpoint host', 'kurvio-courier-connector-for-dalli' ) => is_string( $host ) ? $host : '',
			__( 'Branch', 'kurvio-courier-connector-for-dalli' ) => $settings->branch(),
			__( 'Mode', 'kurvio-courier-connector-for-dalli' ) => (string) $settings->get( 'mode' ),
			__( 'Token configured', 'kurvio-courier-connector-for-dalli' ) => $settings->has_token() ? $yes : $no,
			__( 'Token from wp-config.php', 'kurvio-courier-connector-for-dalli' ) => Settings::token_is_constant( $settings->is_test_mode() ) ? $yes : $no,
			__( 'Order creation trigger', 'kurvio-courier-connector-for-dalli' ) => (string) $settings->get( 'order_trigger' ),
			__( 'Status sync enabled', 'kurvio-courier-connector-for-dalli' ) => $settings->is_enabled( 'sync_enabled' ) ? $yes : $no,
			__( 'Next status sync', 'kurvio-courier-connector-for-dalli' ) => '' !== $next ? $next : __( 'Not scheduled', 'kurvio-courier-connector-for-dalli' ),
			__( 'Debug log', 'kurvio-courier-connector-for-dalli' ) => $settings->is_enabled( 'debug' ) ? $yes : $no,
		);
	}
}
