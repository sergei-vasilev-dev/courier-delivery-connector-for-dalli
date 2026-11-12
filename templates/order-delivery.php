<?php
/**
 * Delivery details on the order page (thank-you page and My Account → View order).
 *
 * This template can be overridden by copying it to yourtheme/woocommerce/dalli-delivery/order-delivery.php.
 *
 * @package DalliDelivery
 * @var \WC_Order           $order        Order.
 * @var array<string,string> $rows        Label => value.
 * @var string               $tracking_url Tracking URL (may be empty).
 * @var array<int,array>     $history      Status history entries (label, time).
 */

defined( 'ABSPATH' ) || exit;
?>
<section class="woocommerce-dalli-delivery">
	<h2 class="woocommerce-column__title"><?php esc_html_e( 'Delivery', 'dalli-delivery-for-woocommerce' ); ?></h2>
	<table class="woocommerce-table shop_table dalli-delivery-details">
		<tbody>
			<?php foreach ( $rows as $label => $value ) : ?>
				<tr>
					<th scope="row"><?php echo esc_html( $label ); ?></th>
					<td><?php echo esc_html( $value ); ?></td>
				</tr>
			<?php endforeach; ?>
			<?php if ( ! empty( $tracking_url ) ) : ?>
				<tr>
					<th scope="row"><?php esc_html_e( 'Tracking', 'dalli-delivery-for-woocommerce' ); ?></th>
					<td><a href="<?php echo esc_url( $tracking_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Track the delivery', 'dalli-delivery-for-woocommerce' ); ?></a></td>
				</tr>
			<?php endif; ?>
		</tbody>
	</table>
	<?php if ( ! empty( $history ) ) : ?>
		<h3><?php esc_html_e( 'Delivery history', 'dalli-delivery-for-woocommerce' ); ?></h3>
		<ol class="dalli-delivery-history">
			<?php foreach ( $history as $entry ) : ?>
				<li><?php echo esc_html( trim( $entry['time'] . ' — ' . $entry['label'], ' —' ) ); ?></li>
			<?php endforeach; ?>
		</ol>
	<?php endif; ?>
</section>
