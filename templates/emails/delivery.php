<?php
/**
 * Delivery details in HTML e-mails.
 *
 * This template can be overridden by copying it to yourtheme/woocommerce/dalli-delivery/emails/delivery.php.
 *
 * @package DalliDelivery
 * @var \WC_Order           $order        Order.
 * @var array<string,string> $rows        Label => value.
 * @var string               $tracking_url Tracking URL (may be empty).
 */

defined( 'ABSPATH' ) || exit;
?>
<h2><?php esc_html_e( 'Delivery', 'dalli-delivery-for-woocommerce' ); ?></h2>
<ul>
	<?php foreach ( $rows as $dalli_delivery_label => $dalli_delivery_value ) : ?>
		<li><strong><?php echo esc_html( $dalli_delivery_label ); ?>:</strong> <?php echo esc_html( $dalli_delivery_value ); ?></li>
	<?php endforeach; ?>
	<?php if ( ! empty( $tracking_url ) ) : ?>
		<li><a href="<?php echo esc_url( $tracking_url ); ?>"><?php esc_html_e( 'Track the delivery', 'dalli-delivery-for-woocommerce' ); ?></a></li>
	<?php endif; ?>
</ul>
