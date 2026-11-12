<?php
/**
 * Periodic delivery status synchronisation (Action Scheduler).
 *
 * @package DalliDelivery
 */

namespace DalliDelivery\Orders;

use DalliDelivery\Api\ApiException;
use DalliDelivery\Api\DalliApi;
use DalliDelivery\Domain\Catalog;
use DalliDelivery\Domain\StatusInfo;
use DalliDelivery\Settings;
use DalliDelivery\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * A recurring Action Scheduler job fans out one async job per open order ("per order" mode),
 * or reads the account-wide "changes" feed and acknowledges it ("changes" mode).
 */
class StatusSync {

	public const ACTION_RUN = 'dalli_delivery_sync_statuses';

	/**
	 * API.
	 *
	 * @var DalliApi
	 */
	private $api;

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	private $logger;

	/**
	 * Constructor.
	 *
	 * @param DalliApi $api      API.
	 * @param Settings $settings Settings.
	 * @param Logger   $logger   Logger.
	 */
	public function __construct( DalliApi $api, Settings $settings, Logger $logger ) {
		$this->api      = $api;
		$this->settings = $settings;
		$this->logger   = $logger;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'action_scheduler_init', array( $this, 'ensure_schedule' ) );
		add_action( self::ACTION_RUN, array( $this, 'run' ) );
		add_action( OrderSync::ACTION_SYNC_ORDER, array( $this, 'handle_sync_order' ) );
		add_action( 'update_option_' . Settings::PREFIX . 'sync_interval', array( $this, 'reschedule' ) );
		add_action( 'update_option_' . Settings::PREFIX . 'sync_enabled', array( $this, 'reschedule' ) );
	}

	/**
	 * Interval in seconds (minimum 5 minutes).
	 *
	 * @return int
	 */
	public function interval(): int {
		return max( 5, (int) $this->settings->get( 'sync_interval' ) ) * MINUTE_IN_SECONDS;
	}

	/**
	 * Make sure the recurring action exists (or does not, when disabled).
	 *
	 * @return void
	 */
	public function ensure_schedule(): void {
		if ( ! function_exists( 'as_has_scheduled_action' ) || ! function_exists( 'as_schedule_recurring_action' ) ) {
			return;
		}
		$enabled   = $this->settings->is_enabled( 'sync_enabled' ) && $this->settings->has_token();
		$scheduled = as_has_scheduled_action( self::ACTION_RUN, array(), OrderSync::GROUP );
		if ( $enabled && ! $scheduled ) {
			as_schedule_recurring_action( time() + MINUTE_IN_SECONDS, $this->interval(), self::ACTION_RUN, array(), OrderSync::GROUP );
		} elseif ( ! $enabled && $scheduled ) {
			as_unschedule_all_actions( self::ACTION_RUN, array(), OrderSync::GROUP );
		}
	}

	/**
	 * Re-create the recurring action after the settings changed.
	 *
	 * @return void
	 */
	public function reschedule(): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::ACTION_RUN, array(), OrderSync::GROUP );
		}
		$this->ensure_schedule();
	}

	/**
	 * Recurring job body.
	 *
	 * @return void
	 */
	public function run(): void {
		if ( ! $this->settings->has_token() ) {
			return;
		}
		if ( 'changes' === $this->settings->get( 'sync_mode' ) ) {
			$this->run_changes_feed();
			return;
		}

		/**
		 * Filters how many days an undelivered order keeps being polled.
		 *
		 * @since 1.0.0
		 *
		 * @param int $days Days.
		 */
		$days = (int) apply_filters( 'dalli_delivery_sync_max_age_days', 60 );

		$orders = wc_get_orders(
			array(
				'limit'        => 200,
				'orderby'      => 'date',
				'order'        => 'ASC',
				'return'       => 'ids',
				'date_created' => '>' . ( time() - $days * DAY_IN_SECONDS ),
				'meta_query'   => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- bounded by date and limit.
					'relation' => 'AND',
					array(
						'key'     => OrderData::META_BARCODE,
						'compare' => 'EXISTS',
					),
					array(
						'key'     => OrderData::META_STATUS_FINAL,
						'compare' => 'NOT EXISTS',
					),
				),
			)
		);

		foreach ( (array) $orders as $order_id ) {
			$args = array( (int) $order_id );
			if ( function_exists( 'as_enqueue_async_action' ) ) {
				if ( ! function_exists( 'as_has_scheduled_action' ) || ! as_has_scheduled_action( OrderSync::ACTION_SYNC_ORDER, $args, OrderSync::GROUP ) ) {
					as_enqueue_async_action( OrderSync::ACTION_SYNC_ORDER, $args, OrderSync::GROUP );
				}
			} else {
				$this->handle_sync_order( (int) $order_id );
			}
		}
	}

	/**
	 * Action Scheduler callback: refresh one order.
	 *
	 * @param int $order_id Order ID.
	 * @return void
	 */
	public function handle_sync_order( $order_id ): void {
		$order = wc_get_order( (int) $order_id );
		if ( $order instanceof \WC_Order ) {
			$this->sync_order( $order );
		}
	}

	/**
	 * Refresh one order.
	 *
	 * @param \WC_Order $order Order.
	 * @return bool
	 */
	public function sync_order( \WC_Order $order ): bool {
		$barcode = OrderData::barcode( $order );
		if ( '' === $barcode ) {
			return false;
		}
		try {
			$info = $this->api->status( $barcode );
		} catch ( ApiException $e ) {
			$this->logger->error( sprintf( 'Status sync of order %s failed: %s', $order->get_order_number(), $e->display_message() ) );
			return false;
		}
		if ( ! $info || '' === $info->status ) {
			$order->update_meta_data( OrderData::META_SYNCED_AT, (string) time() );
			$order->save();
			return false;
		}
		$this->apply( $order, $info );
		return true;
	}

	/**
	 * Read the changes feed and acknowledge it.
	 *
	 * @return void
	 */
	private function run_changes_feed(): void {
		try {
			$changes = $this->api->changed_statuses();
		} catch ( ApiException $e ) {
			$this->logger->error( 'Status changes feed failed: ' . $e->display_message() );
			return;
		}

		foreach ( $changes as $info ) {
			$order = $this->find_order( $info );
			if ( $order ) {
				$this->apply( $order, $info );
			}
		}

		try {
			$this->api->commit_statuses();
		} catch ( ApiException $e ) {
			$this->logger->error( 'Could not acknowledge status changes: ' . $e->display_message() );
		}
	}

	/**
	 * Find the WooCommerce order belonging to a status record.
	 *
	 * @param StatusInfo $info Record.
	 * @return \WC_Order|null
	 */
	private function find_order( StatusInfo $info ): ?\WC_Order {
		foreach ( array(
			OrderData::META_NUMBER  => $info->number,
			OrderData::META_BARCODE => $info->barcode,
		) as $key => $value ) {
			if ( '' === $value ) {
				continue;
			}
			$found = wc_get_orders(
				array(
					'limit'      => 1,
					'meta_key'   => $key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- single lookup by exact value.
					'meta_value' => $value, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				)
			);
			if ( $found && $found[0] instanceof \WC_Order ) {
				return $found[0];
			}
		}
		return null;
	}

	/**
	 * Store a status on an order and optionally update the WooCommerce status.
	 *
	 * @param \WC_Order  $order Order.
	 * @param StatusInfo $info  Status.
	 * @return void
	 */
	public function apply( \WC_Order $order, StatusInfo $info ): void {
		$old = OrderData::status( $order );
		$new = $info->status;

		$order->update_meta_data( OrderData::META_SYNCED_AT, (string) time() );
		$order->update_meta_data( OrderData::META_STATUS, $new );
		$order->update_meta_data( OrderData::META_STATUS_TITLE, $info->title );
		$order->update_meta_data( OrderData::META_STATUS_TIME, $info->event_time );
		if ( $info->history ) {
			$order->update_meta_data( OrderData::META_STATUS_HISTORY, $info->history );
		}
		if ( '' !== $info->courier_name || '' !== $info->courier_phone ) {
			$order->update_meta_data(
				OrderData::META_COURIER,
				array(
					'name'  => $info->courier_name,
					'phone' => $info->courier_phone,
				)
			);
		}
		if ( Catalog::is_final_status( $new ) ) {
			$order->update_meta_data( OrderData::META_STATUS_FINAL, 'yes' );
		} else {
			$order->delete_meta_data( OrderData::META_STATUS_FINAL );
		}

		if ( $old !== $new ) {
			$order->add_order_note(
				sprintf(
					/* translators: 1: Dalli status label, 2: Dalli status code */
					__( 'Dalli delivery status: %1$s (%2$s)', 'dalli-delivery-for-woocommerce' ),
					Catalog::status_label( $new ),
					$new
				)
			);
		}

		$mapper = new StatusMapper( (array) $this->settings->get( 'status_map' ) );
		$valid  = array_map(
			static function ( $slug ) {
				return 0 === strpos( (string) $slug, 'wc-' ) ? substr( (string) $slug, 3 ) : (string) $slug;
			},
			array_keys( wc_get_order_statuses() )
		);
		$target = $old !== $new ? $mapper->target( $new, $order->get_status(), $valid ) : '';

		$order->save();

		if ( '' !== $target ) {
			$order->update_status(
				$target,
				sprintf(
					/* translators: %s: Dalli status code */
					__( 'Status changed by the Dalli delivery status %s.', 'dalli-delivery-for-woocommerce' ),
					$new
				)
			);
		}

		if ( $old !== $new ) {
			/**
			 * Fires when the Dalli status of an order changed.
			 *
			 * @since 1.0.0
			 *
			 * @param \WC_Order $order Order.
			 * @param string    $old   Previous Dalli status ('' if none).
			 * @param string    $new   New Dalli status.
			 */
			do_action( 'dalli_delivery_status_changed', $order, $old, $new );
		}
	}
}
