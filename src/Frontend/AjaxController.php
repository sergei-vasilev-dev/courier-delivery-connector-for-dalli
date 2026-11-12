<?php
/**
 * AJAX endpoints used by the checkout widgets (classic and Blocks).
 *
 * @package DalliDelivery
 */

namespace DalliDelivery\Frontend;

use DalliDelivery\Api\ApiException;
use DalliDelivery\Api\DalliApi;
use DalliDelivery\Domain\Catalog;
use DalliDelivery\Domain\PickupPoint;
use DalliDelivery\Shipping\AddressFormatter;
use DalliDelivery\Shipping\ChosenRate;
use DalliDelivery\Shipping\PvzService;
use DalliDelivery\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Every endpoint verifies a nonce, needs a non-empty cart with a chosen Dalli rate, and only
 * ever returns data derived from the server-side session (never trusting client supplied cities).
 */
class AjaxController {

	public const NONCE = 'dalli_delivery_frontend';

	/**
	 * Selection storage.
	 *
	 * @var Selection
	 */
	private $selection;

	/**
	 * Pickup point service.
	 *
	 * @var PvzService
	 */
	private $pvz;

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
	 * Constructor.
	 *
	 * @param Selection  $selection Selection storage.
	 * @param PvzService $pvz       Pickup points.
	 * @param DalliApi   $api       API.
	 * @param Settings   $settings  Settings.
	 */
	public function __construct( Selection $selection, PvzService $pvz, DalliApi $api, Settings $settings ) {
		$this->selection = $selection;
		$this->pvz       = $pvz;
		$this->api       = $api;
		$this->settings  = $settings;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		foreach ( array( 'state', 'points', 'select_point', 'intervals', 'select_interval' ) as $action ) {
			add_action( 'wp_ajax_dalli_delivery_' . $action, array( $this, 'handle_' . $action ) );
			add_action( 'wp_ajax_nopriv_dalli_delivery_' . $action, array( $this, 'handle_' . $action ) );
		}
	}

	/**
	 * Common guard; sends a JSON error and exits when it fails.
	 *
	 * @return array<string,mixed> The chosen Dalli rate.
	 */
	private function guard(): array {
		if ( ! check_ajax_referer( self::NONCE, 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Your session has expired. Please reload the page.', 'dalli-delivery-for-woocommerce' ) ), 403 );
		}
		if ( ! WC()->cart || WC()->cart->is_empty() ) {
			wp_send_json_error( array( 'message' => __( 'Your cart is empty.', 'dalli-delivery-for-woocommerce' ) ), 400 );
		}
		$rate = ChosenRate::current();
		if ( ! $rate ) {
			wp_send_json_error( array( 'message' => __( 'Dalli delivery is not selected.', 'dalli-delivery-for-woocommerce' ) ), 400 );
		}
		return $rate;
	}

	/**
	 * Customer address pieces from the session.
	 *
	 * @return array<string,string>
	 */
	private function customer_address(): array {
		$c = WC()->customer;
		if ( ! $c ) {
			return array();
		}
		$use_shipping = '' !== $c->get_shipping_city();
		return array(
			'postcode'  => (string) ( $use_shipping ? $c->get_shipping_postcode() : $c->get_billing_postcode() ),
			'state'     => (string) ( $use_shipping ? $c->get_shipping_state() : $c->get_billing_state() ),
			'city'      => (string) ( $use_shipping ? $c->get_shipping_city() : $c->get_billing_city() ),
			'address_1' => (string) ( $use_shipping ? $c->get_shipping_address_1() : $c->get_billing_address_1() ),
			'address_2' => (string) ( $use_shipping ? $c->get_shipping_address_2() : $c->get_billing_address_2() ),
		);
	}

	/**
	 * Current selection and chosen rate, so the widget can render itself.
	 *
	 * @return void
	 */
	public function handle_state(): void {
		$rate = $this->guard();
		wp_send_json_success( $this->state_payload( $rate ) );
	}

	/**
	 * Build the state response.
	 *
	 * @param array<string,mixed> $rate Chosen rate.
	 * @return array<string,mixed>
	 */
	public function state_payload( array $rate ): array {
		$address  = $this->customer_address();
		$pvz      = $this->selection->pvz_for( (string) $rate['partner'], (string) ( $address['city'] ?? '' ) );
		$interval = Catalog::TYPE_COURIER === $rate['type'] ? $this->selection->interval() : null;

		return array(
			'rate'      => array(
				'id'      => (string) $rate['rate_id'],
				'partner' => (string) $rate['partner'],
				'type'    => (string) $rate['type'],
				'service' => (int) $rate['service'],
			),
			'needsPvz'  => Catalog::TYPE_PVZ === $rate['type'],
			'intervals' => Catalog::TYPE_COURIER === $rate['type'] && Catalog::supports_intervals( (int) $rate['service'] ) && $this->settings->is_enabled( 'choose_interval' ),
			'pvz'       => $pvz ? $this->public_point( $pvz ) : null,
			'interval'  => $interval,
		);
	}

	/**
	 * Pickup points for the customer's town.
	 *
	 * @return void
	 */
	public function handle_points(): void {
		$rate = $this->guard();
		if ( Catalog::TYPE_PVZ !== $rate['type'] ) {
			wp_send_json_error( array( 'message' => __( 'The selected delivery method does not use pickup points.', 'dalli-delivery-for-woocommerce' ) ), 400 );
		}

		$address = $this->customer_address();
		$town    = (string) ( $address['city'] ?? '' );
		if ( '' === $town ) {
			wp_send_json_error( array( 'message' => __( 'Enter your city first.', 'dalli-delivery-for-woocommerce' ) ), 400 );
		}

		$points = $this->pvz->for_town( (string) $rate['partner'], $town, $this->region_name( $address ) );
		if ( '' !== $this->pvz->error() ) {
			wp_send_json_error( array( 'message' => __( 'Pickup points are temporarily unavailable. Please try again later.', 'dalli-delivery-for-woocommerce' ) ), 502 );
		}

		$weight_limit = $this->cart_weight();
		$out          = array();
		foreach ( $points as $point ) {
			if ( $point->weight_limit > 0 && $weight_limit > $point->weight_limit ) {
				continue;
			}
			$out[] = $this->public_point( $point->to_array() );
			if ( count( $out ) >= 3000 ) {
				break;
			}
		}

		$selected = $this->selection->pvz_for( (string) $rate['partner'], $town );
		wp_send_json_success(
			array(
				'town'     => $town,
				'points'   => $out,
				'selected' => $selected ? (string) $selected['code'] : '',
			)
		);
	}

	/**
	 * Store the chosen pickup point.
	 *
	 * @return void
	 */
	public function handle_select_point(): void {
		$rate = $this->guard();
		if ( Catalog::TYPE_PVZ !== $rate['type'] ) {
			wp_send_json_error( array( 'message' => __( 'The selected delivery method does not use pickup points.', 'dalli-delivery-for-woocommerce' ) ), 400 );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in guard().
		$code    = isset( $_POST['code'] ) ? sanitize_text_field( wp_unslash( $_POST['code'] ) ) : '';
		$address = $this->customer_address();
		$point   = '' !== $code ? $this->pvz->find( (string) $rate['partner'], (string) ( $address['city'] ?? '' ), $this->region_name( $address ), $code ) : null;

		if ( ! $point instanceof PickupPoint ) {
			wp_send_json_error( array( 'message' => __( 'This pickup point is not available. Please choose another one.', 'dalli-delivery-for-woocommerce' ) ), 404 );
		}

		$data          = $point->to_array();
		$data['_town'] = (string) ( $address['city'] ?? '' );
		$this->selection->set_pvz( $data );
		wp_send_json_success( $this->state_payload( $rate ) );
	}

	/**
	 * Available delivery dates and intervals for own-courier delivery.
	 *
	 * @return void
	 */
	public function handle_intervals(): void {
		$rate = $this->guard();
		if ( ! Catalog::supports_intervals( (int) $rate['service'] ) || ! $this->settings->is_enabled( 'choose_interval' ) ) {
			wp_send_json_error( array( 'message' => __( 'Delivery intervals are not available for this method.', 'dalli-delivery-for-woocommerce' ) ), 400 );
		}

		$dates = $this->interval_options( $rate );
		if ( null === $dates ) {
			wp_send_json_error( array( 'message' => __( 'Delivery intervals are temporarily unavailable.', 'dalli-delivery-for-woocommerce' ) ), 502 );
		}
		$interval = $this->selection->interval();
		wp_send_json_success(
			array(
				'dates'    => $dates,
				'selected' => $interval,
			)
		);
	}

	/**
	 * Store the chosen interval after checking it against the offered ones.
	 *
	 * @return void
	 */
	public function handle_select_interval(): void {
		$rate = $this->guard();
		if ( ! Catalog::supports_intervals( (int) $rate['service'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Delivery intervals are not available for this method.', 'dalli-delivery-for-woocommerce' ) ), 400 );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in guard().
		$date = isset( $_POST['date'] ) ? sanitize_text_field( wp_unslash( $_POST['date'] ) ) : '';
		$min  = isset( $_POST['min'] ) ? sanitize_text_field( wp_unslash( $_POST['min'] ) ) : '';
		$max  = isset( $_POST['max'] ) ? sanitize_text_field( wp_unslash( $_POST['max'] ) ) : '';
		// phpcs:enable

		if ( '' === $date && '' === $min && '' === $max ) {
			$this->selection->clear_interval();
			wp_send_json_success( $this->state_payload( $rate ) );
		}

		$dates = $this->interval_options( $rate );
		$valid = false;
		foreach ( (array) $dates as $offered_date => $intervals ) {
			if ( (string) $offered_date !== $date ) {
				continue;
			}
			foreach ( $intervals as $interval ) {
				if ( $interval['min'] === $min && $interval['max'] === $max ) {
					$valid = true;
				}
			}
		}
		if ( ! $valid ) {
			wp_send_json_error( array( 'message' => __( 'This delivery interval is not available. Please choose another one.', 'dalli-delivery-for-woocommerce' ) ), 400 );
		}

		$this->selection->set_interval( $date, $min, $max );
		wp_send_json_success( $this->state_payload( $rate ) );
	}

	/**
	 * Offered dates → intervals, cached for a few minutes.
	 *
	 * @param array<string,mixed> $rate Chosen rate.
	 * @return array<string,array<int,array{min:string,max:string,type:string}>>|null Null on failure.
	 */
	private function interval_options( array $rate ): ?array {
		$address = AddressFormatter::format( $this->customer_address(), AddressFormatter::russian_states() );
		if ( '' === $address ) {
			return null;
		}
		$key    = 'dalli_int_' . md5( $this->settings->api_url() . '|' . $address . '|' . $rate['service'] . '|' . wp_date( 'Y-m-d' ) );
		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		try {
			$dates = $this->api->intervals( $address, (int) $rate['service'], 'dates', wp_date( 'Y-m-d' ) );
		} catch ( ApiException $e ) {
			return null;
		}
		unset( $dates[''] );
		set_transient( $key, $dates, 10 * MINUTE_IN_SECONDS );
		return $dates;
	}

	/**
	 * Region name used to narrow pickup point searches.
	 *
	 * @param array<string,string> $address Customer address.
	 * @return string
	 */
	private function region_name( array $address ): string {
		$state  = (string) ( $address['state'] ?? '' );
		$states = AddressFormatter::russian_states();
		$name   = isset( $states[ $state ] ) ? (string) $states[ $state ] : '';
		return preg_match( '/[А-Яа-яЁё]/u', $name ) ? $name : '';
	}

	/**
	 * Total weight of the cart in kg.
	 *
	 * @return float
	 */
	private function cart_weight(): float {
		return (float) wc_get_weight( (float) WC()->cart->get_cart_contents_weight(), 'kg' );
	}

	/**
	 * Fields sent to the browser.
	 *
	 * @param array<string,mixed> $point Point data.
	 * @return array<string,mixed>
	 */
	private function public_point( array $point ): array {
		return array(
			'code'        => (string) $point['code'],
			'name'        => (string) $point['name'],
			'address'     => (string) $point['address'],
			'schedule'    => (string) $point['schedule'],
			'phone'       => (string) $point['phone'],
			'description' => (string) $point['description'],
			'lat'         => $point['lat'] ?? null,
			'lng'         => $point['lng'] ?? null,
			'prepaidOnly' => ! empty( $point['only_prepaid'] ),
			'cash'        => ! empty( $point['cash'] ),
			'card'        => ! empty( $point['acquiring'] ),
			'locker'      => ! empty( $point['postamat'] ),
		);
	}
}
