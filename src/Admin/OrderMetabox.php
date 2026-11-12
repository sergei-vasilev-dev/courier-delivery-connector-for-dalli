<?php
/**
 * Order screen: Dalli meta box and the orders list column.
 *
 * @package DalliDelivery
 */

namespace DalliDelivery\Admin;

use DalliDelivery\Domain\Catalog;
use DalliDelivery\Orders\OrderData;
use Automattic\WooCommerce\Utilities\OrderUtil;

defined( 'ABSPATH' ) || exit;

/**
 * Works with both legacy post storage and HPOS.
 */
class OrderMetabox {

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ), 10, 2 );

		$hpos = class_exists( OrderUtil::class ) && OrderUtil::custom_orders_table_usage_is_enabled();
		if ( $hpos ) {
			add_filter( 'manage_woocommerce_page_wc-orders_columns', array( $this, 'add_column' ) );
			add_action( 'manage_woocommerce_page_wc-orders_custom_column', array( $this, 'render_column' ), 10, 2 );
		} else {
			add_filter( 'manage_edit-shop_order_columns', array( $this, 'add_column' ) );
			add_action( 'manage_shop_order_posts_custom_column', array( $this, 'render_column_legacy' ), 10, 2 );
		}
	}

	/**
	 * Add the meta box on the order screen.
	 *
	 * @param string $post_type Screen ID or post type.
	 * @param mixed  $post_or_order Post or order.
	 * @return void
	 */
	public function add_meta_box( $post_type, $post_or_order = null ): void {
		$screen = function_exists( 'wc_get_page_screen_id' ) ? wc_get_page_screen_id( 'shop-order' ) : 'shop_order';
		if ( $post_type !== $screen ) {
			return;
		}
		$order = $this->resolve_order( $post_or_order );
		if ( ! $order || ! OrderData::is_dalli_order( $order ) ) {
			return;
		}
		add_meta_box( 'dalli-delivery-order', __( 'Dalli delivery', 'dalli-delivery-for-woocommerce' ), array( $this, 'render_meta_box' ), $screen, 'side', 'high' );
	}

	/**
	 * Order from a post or order object.
	 *
	 * @param mixed $post_or_order Post or order.
	 * @return \WC_Order|null
	 */
	private function resolve_order( $post_or_order ): ?\WC_Order {
		if ( $post_or_order instanceof \WC_Order ) {
			return $post_or_order;
		}
		if ( $post_or_order instanceof \WP_Post ) {
			$order = wc_get_order( $post_or_order->ID );
			return $order instanceof \WC_Order ? $order : null;
		}
		return null;
	}

	/**
	 * Render the meta box.
	 *
	 * @param mixed $post_or_order Post or order.
	 * @return void
	 */
	public function render_meta_box( $post_or_order ): void {
		$order = $this->resolve_order( $post_or_order );
		if ( ! $order ) {
			return;
		}

		$rows = OrderData::summary( $order );
		echo '<table class="widefat striped" style="border:0;box-shadow:none;"><tbody>';
		foreach ( $rows as $label => $value ) {
			printf( '<tr><th scope="row" style="width:40%%;">%1$s</th><td>%2$s</td></tr>', esc_html( $label ), esc_html( $value ) );
		}
		$time = (string) $order->get_meta( OrderData::META_STATUS_TIME );
		if ( '' !== $time ) {
			printf( '<tr><th scope="row">%1$s</th><td>%2$s</td></tr>', esc_html__( 'Status updated', 'dalli-delivery-for-woocommerce' ), esc_html( $time ) );
		}
		$courier = $order->get_meta( OrderData::META_COURIER );
		if ( is_array( $courier ) && ! empty( $courier['name'] ) ) {
			printf(
				'<tr><th scope="row">%1$s</th><td>%2$s</td></tr>',
				esc_html__( 'Courier', 'dalli-delivery-for-woocommerce' ),
				esc_html( trim( $courier['name'] . ' ' . ( $courier['phone'] ?? '' ) ) )
			);
		}
		echo '</tbody></table>';

		$error = (string) $order->get_meta( OrderData::META_ERROR );
		if ( '' !== $error && ! OrderData::is_sent( $order ) ) {
			printf( '<p style="color:#b32d2e;"><strong>%1$s</strong> %2$s</p>', esc_html__( 'Last error:', 'dalli-delivery-for-woocommerce' ), esc_html( $error ) );
		}

		$history = OrderData::history( $order );
		if ( $history ) {
			echo '<details style="margin:.75em 0;"><summary>' . esc_html__( 'Status history', 'dalli-delivery-for-woocommerce' ) . '</summary><ol style="margin:.5em 0 0 1.25em;">';
			foreach ( $history as $entry ) {
				printf(
					'<li>%1$s — %2$s</li>',
					esc_html( (string) $entry['event_time'] ),
					esc_html( Catalog::status_label( (string) $entry['status'] ) )
				);
			}
			echo '</ol></details>';
		}

		echo '<p style="display:flex;flex-wrap:wrap;gap:6px;margin-top:1em;">';
		if ( ! OrderData::is_sent( $order ) ) {
			printf( '<a class="button button-primary" href="%1$s">%2$s</a>', esc_url( OrderActions::action_url( $order, 'send' ) ), esc_html__( 'Send to Dalli', 'dalli-delivery-for-woocommerce' ) );
		} else {
			printf( '<a class="button" href="%1$s">%2$s</a>', esc_url( OrderActions::action_url( $order, 'refresh' ) ), esc_html__( 'Refresh status', 'dalli-delivery-for-woocommerce' ) );
			printf( '<a class="button" href="%1$s">%2$s</a>', esc_url( OrderActions::action_url( $order, 'sticker' ) ), esc_html__( 'Download sticker', 'dalli-delivery-for-woocommerce' ) );
			if ( OrderData::can_cancel( $order ) ) {
				printf(
					'<a class="button" href="%1$s" onclick="return confirm(%3$s);">%2$s</a>',
					esc_url( OrderActions::action_url( $order, 'cancel' ) ),
					esc_html__( 'Cancel in Dalli', 'dalli-delivery-for-woocommerce' ),
					esc_attr( (string) wp_json_encode( __( 'Cancel this delivery in Dalli?', 'dalli-delivery-for-woocommerce' ) ) )
				);
			}
		}
		echo '</p>';
		if ( OrderData::is_sent( $order ) ) {
			echo '<p class="description">' . esc_html__( 'Dalli only returns stickers for orders sent today.', 'dalli-delivery-for-woocommerce' ) . '</p>';
		}
	}

	/**
	 * Add a column to the orders list.
	 *
	 * @param array<string,string> $columns Columns.
	 * @return array<string,string>
	 */
	public function add_column( $columns ) {
		$new = array();
		foreach ( $columns as $key => $label ) {
			$new[ $key ] = $label;
			if ( 'order_status' === $key ) {
				$new['dalli_delivery'] = __( 'Dalli', 'dalli-delivery-for-woocommerce' );
			}
		}
		if ( ! isset( $new['dalli_delivery'] ) ) {
			$new['dalli_delivery'] = __( 'Dalli', 'dalli-delivery-for-woocommerce' );
		}
		return $new;
	}

	/**
	 * Render the column (HPOS).
	 *
	 * @param string    $column Column key.
	 * @param \WC_Order $order  Order.
	 * @return void
	 */
	public function render_column( $column, $order ): void {
		if ( 'dalli_delivery' !== $column || ! $order instanceof \WC_Order ) {
			return;
		}
		$this->print_column( $order );
	}

	/**
	 * Render the column (legacy posts).
	 *
	 * @param string $column  Column key.
	 * @param int    $post_id Order ID.
	 * @return void
	 */
	public function render_column_legacy( $column, $post_id ): void {
		if ( 'dalli_delivery' !== $column ) {
			return;
		}
		$order = wc_get_order( (int) $post_id );
		if ( $order instanceof \WC_Order ) {
			$this->print_column( $order );
		}
	}

	/**
	 * Column content.
	 *
	 * @param \WC_Order $order Order.
	 * @return void
	 */
	private function print_column( \WC_Order $order ): void {
		if ( ! OrderData::is_dalli_order( $order ) ) {
			echo '&ndash;';
			return;
		}
		$barcode = OrderData::barcode( $order );
		if ( '' === $barcode ) {
			echo '<span style="opacity:.7;">' . esc_html__( 'Not sent', 'dalli-delivery-for-woocommerce' ) . '</span>';
			return;
		}
		$status = OrderData::status( $order );
		echo esc_html( $barcode );
		if ( '' !== $status ) {
			echo '<br /><small>' . esc_html( Catalog::status_label( $status ) ) . '</small>';
		}
	}
}
