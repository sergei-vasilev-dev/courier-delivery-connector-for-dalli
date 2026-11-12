<?php
/**
 * WooCommerce → Status report section.
 *
 * @package DalliDelivery
 */

namespace DalliDelivery\Admin;

use DalliDelivery\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Adds a table with non-sensitive diagnostics to the WooCommerce system status report.
 */
class SystemStatus {

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
		add_action( 'woocommerce_system_status_report', array( $this, 'render' ) );
	}

	/**
	 * Print the table.
	 *
	 * @return void
	 */
	public function render(): void {
		?>
		<table class="wc_status_table widefat" cellspacing="0">
			<thead>
				<tr>
					<th colspan="3" data-export-label="Courier Delivery Connector for Dalli"><h2><?php esc_html_e( 'Courier Delivery Connector for Dalli', 'courier-delivery-connector-for-dalli' ); ?></h2></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( ToolsPage::diagnostics( $this->settings ) as $label => $value ) : ?>
					<tr>
						<td data-export-label="<?php echo esc_attr( $label ); ?>"><?php echo esc_html( $label ); ?>:</td>
						<td class="help">&nbsp;</td>
						<td><?php echo esc_html( $value ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}
}
