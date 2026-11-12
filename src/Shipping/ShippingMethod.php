<?php
/**
 * The "Dalli delivery" shipping method (shipping zones, instance settings).
 *
 * @package DalliDelivery
 */

namespace DalliDelivery\Shipping;

use DalliDelivery\Domain\Catalog;
use DalliDelivery\Domain\Rate;
use DalliDelivery\Plugin;
use DalliDelivery\Support\Format;

defined( 'ABSPATH' ) || exit;

/**
 * One shipping method that, for the destination, offers every enabled partner/delivery type
 * returned by the Dalli rate calculation as a separate rate.
 */
class ShippingMethod extends \WC_Shipping_Method {

	public const ID = 'dalli_delivery';

	/**
	 * Constructor.
	 *
	 * @param int $instance_id Shipping zone instance ID.
	 */
	public function __construct( $instance_id = 0 ) {
		parent::__construct( $instance_id );
		$this->id                 = self::ID;
		$this->instance_id        = absint( $instance_id );
		$this->method_title       = __( 'Dalli delivery', 'dalli-delivery-for-woocommerce' );
		$this->method_description = __( 'Live delivery rates and pickup points from the Dalli courier service and its partners. Connection settings are under WooCommerce → Settings → Shipping → Dalli.', 'dalli-delivery-for-woocommerce' );
		$this->supports           = array(
			'shipping-zones',
			'instance-settings',
			'instance-settings-modal',
		);
		$this->init();
	}

	/**
	 * Load settings and hooks.
	 *
	 * @return void
	 */
	public function init(): void {
		$this->instance_form_fields = $this->build_form_fields();
		$this->init_settings();

		$this->title      = (string) $this->get_option( 'title' );
		$this->tax_status = (string) $this->get_option( 'tax_status' );

		add_action( 'woocommerce_update_options_shipping_' . $this->id, array( $this, 'process_admin_options' ) );
	}

	/**
	 * Instance setting fields.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private function build_form_fields(): array {
		$partners = Catalog::partners();
		return array(
			'title'          => array(
				'title'       => __( 'Method title', 'dalli-delivery-for-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'Shown in the admin only; customers see the carrier and delivery type of each rate.', 'dalli-delivery-for-woocommerce' ),
				'default'     => __( 'Dalli delivery', 'dalli-delivery-for-woocommerce' ),
				'desc_tip'    => true,
			),
			'tax_status'     => array(
				'title'   => __( 'Tax status', 'dalli-delivery-for-woocommerce' ),
				'type'    => 'select',
				'class'   => 'wc-enhanced-select',
				'default' => 'taxable',
				'options' => array(
					'taxable' => __( 'Taxable', 'dalli-delivery-for-woocommerce' ),
					'none'    => _x( 'None', 'Tax status', 'dalli-delivery-for-woocommerce' ),
				),
			),
			'partners'       => array(
				'title'    => __( 'Carriers', 'dalli-delivery-for-woocommerce' ),
				'type'     => 'multiselect',
				'class'    => 'wc-enhanced-select',
				'default'  => array_keys( $partners ),
				'options'  => $partners,
				'desc_tip' => __( 'Only the selected carriers are priced. Each one adds a request to the Dalli API (cached).', 'dalli-delivery-for-woocommerce' ),
			),
			'types'          => array(
				'title'   => __( 'Delivery types', 'dalli-delivery-for-woocommerce' ),
				'type'    => 'multiselect',
				'class'   => 'wc-enhanced-select',
				'default' => array( Catalog::TYPE_COURIER, Catalog::TYPE_PVZ ),
				'options' => Catalog::types(),
			),
			'markup_percent' => array(
				'title'       => __( 'Markup, %', 'dalli-delivery-for-woocommerce' ),
				'type'        => 'text',
				'default'     => '0',
				'description' => __( 'Percentage added to the carrier price.', 'dalli-delivery-for-woocommerce' ),
				'desc_tip'    => true,
				'class'       => 'wc_input_decimal',
			),
			'markup_fixed'   => array(
				'title'       => __( 'Fixed markup', 'dalli-delivery-for-woocommerce' ),
				'type'        => 'text',
				'default'     => '0',
				'description' => __( 'Amount added to the carrier price (may be negative to give a discount).', 'dalli-delivery-for-woocommerce' ),
				'desc_tip'    => true,
				'class'       => 'wc_input_decimal',
			),
			'free_over'      => array(
				'title'       => __( 'Free shipping over', 'dalli-delivery-for-woocommerce' ),
				'type'        => 'text',
				'default'     => '',
				'description' => __( 'Delivery is free when the cart total reaches this amount. Leave empty to disable.', 'dalli-delivery-for-woocommerce' ),
				'desc_tip'    => true,
				'class'       => 'wc_input_decimal',
			),
			'fixed_price'    => array(
				'title'       => __( 'Fixed price', 'dalli-delivery-for-woocommerce' ),
				'type'        => 'text',
				'default'     => '',
				'description' => __( 'Charge this price for every rate instead of the carrier price. Leave empty to disable.', 'dalli-delivery-for-woocommerce' ),
				'desc_tip'    => true,
				'class'       => 'wc_input_decimal',
			),
			'show_period'    => array(
				'title'   => __( 'Delivery period', 'dalli-delivery-for-woocommerce' ),
				'type'    => 'checkbox',
				'label'   => __( 'Show the estimated delivery period next to the rate name', 'dalli-delivery-for-woocommerce' ),
				'default' => 'yes',
			),
		);
	}

	/**
	 * Whether the method can be used for the package.
	 *
	 * @param array<string,mixed> $package Package.
	 * @return bool
	 */
	public function is_available( $package ) {
		$country = isset( $package['destination']['country'] ) ? (string) $package['destination']['country'] : '';
		if ( 'RU' !== $country ) {
			return false;
		}
		if ( ! Plugin::instance()->settings()->has_token() ) {
			return false;
		}
		return parent::is_available( $package );
	}

	/**
	 * Calculate rates.
	 *
	 * @param array<string,mixed> $package Package.
	 * @return void
	 */
	public function calculate_shipping( $package = array() ) {
		$plugin   = Plugin::instance();
		$settings = $plugin->settings();

		$address = $this->destination_address( $package );
		if ( '' === $address ) {
			return;
		}

		$partners = (array) $this->get_option( 'partners', array() );
		$types    = (array) $this->get_option( 'types', array() );
		$partners = array_values( array_intersect( $partners, Catalog::partner_codes() ) );
		$types    = array_values( array_intersect( $types, array( Catalog::TYPE_COURIER, Catalog::TYPE_PVZ ) ) );
		if ( ! $partners || ! $types ) {
			return;
		}

		$spec     = $this->package_spec( $package );
		$contents = isset( $package['contents_cost'] ) ? (float) $package['contents_cost'] : 0.0;
		$declared = $this->declared_value( $contents );

		$partner_types = array();
		foreach ( $partners as $partner ) {
			$partner_types[ $partner ] = $types;
		}

		$rates = $plugin->rate_calculator()->calculate( $spec, $address, $declared, $partner_types, (string) $settings->get( 'sender_code' ) );

		$rules = array(
			'fixed_price'        => $this->get_option( 'fixed_price' ),
			'free_over'          => $this->get_option( 'free_over' ),
			'markup_percent'     => $this->get_option( 'markup_percent' ),
			'markup_fixed'       => $this->get_option( 'markup_fixed' ),
			'rounding_step'      => $settings->get( 'rounding_step' ),
			'rounding_direction' => $settings->get( 'rounding_direction' ),
		);

		$price_rules = new PriceRules();
		foreach ( $rates as $rate ) {
			$cost = $price_rules->apply( $rate->price, $contents, $rules );

			$args = array(
				'id'        => $this->get_rate_id( $rate->partner . '_' . $rate->type . '_' . $rate->service ),
				'label'     => $this->rate_label( $rate ),
				'cost'      => $cost,
				'package'   => $package,
				'meta_data' => array(
					'dalli_partner'      => $rate->partner,
					'dalli_type'         => $rate->type,
					'dalli_service'      => $rate->service,
					'dalli_period'       => $rate->period,
					'dalli_zone'         => $rate->zone,
					'dalli_vat_rate'     => null === $rate->vat_rate ? '' : $rate->vat_rate,
					'dalli_only_prepaid' => $rate->only_prepaid ? '1' : '',
					'dalli_base_cost'    => $rate->price,
				),
			);

			/**
			 * Filters the WooCommerce rate built from a Dalli rate.
			 *
			 * @since 1.0.0
			 *
			 * @param array<string,mixed> $args    Arguments for WC_Shipping_Method::add_rate().
			 * @param Rate                $rate    The Dalli rate.
			 * @param array<string,mixed> $package Package.
			 */
			$args = apply_filters( 'dalli_delivery_rate_args', $args, $rate, $package );
			if ( is_array( $args ) ) {
				$this->add_rate( $args );
			}
		}
	}

	/**
	 * Human readable rate label, e.g. "CDEK pickup point (3 days)".
	 *
	 * @param Rate $rate Rate.
	 * @return string
	 */
	private function rate_label( Rate $rate ): string {
		$name = Catalog::partner_label( $rate->partner );
		if ( Catalog::TYPE_PVZ === $rate->type ) {
			/* translators: %s: carrier name, e.g. CDEK */
			$label = sprintf( __( '%s pickup point', 'dalli-delivery-for-woocommerce' ), $name );
		} else {
			/* translators: %s: carrier name, e.g. CDEK */
			$label = sprintf( __( '%s courier', 'dalli-delivery-for-woocommerce' ), $name );
		}

		$variant = Catalog::service_variant_label( $rate->service );
		if ( '' !== $variant ) {
			$label .= ', ' . $variant;
		}

		if ( 'yes' === $this->get_option( 'show_period', 'yes' ) ) {
			$period = Format::period( $rate->period );
			if ( '' !== $period ) {
				$label .= ' (' . $period . ')';
			}
		}
		return $label;
	}

	/**
	 * Declared value according to the settings.
	 *
	 * @param float $contents_cost Cart contents cost.
	 * @return float
	 */
	private function declared_value( float $contents_cost ): float {
		$settings = Plugin::instance()->settings();
		if ( 'percent' === $settings->get( 'declared_value' ) ) {
			return $contents_cost * max( 0.0, min( 100.0, $settings->get_float( 'declared_percent' ) ) ) / 100;
		}
		return $contents_cost;
	}

	/**
	 * Destination as a single address line.
	 *
	 * @param array<string,mixed> $package Package.
	 * @return string Empty when the destination is not specific enough.
	 */
	private function destination_address( array $package ): string {
		$dest = isset( $package['destination'] ) ? (array) $package['destination'] : array();
		if ( ! isset( $dest['address_1'] ) && isset( $dest['address'] ) ) {
			$dest['address_1'] = $dest['address'];
		}
		return AddressFormatter::format( $dest, AddressFormatter::russian_states() );
	}

	/**
	 * Parcel specification from the package contents.
	 *
	 * @param array<string,mixed> $package Package.
	 * @return PackageSpec
	 */
	private function package_spec( array $package ): PackageSpec {
		$settings = Plugin::instance()->settings();

		$lines = array();
		foreach ( (array) ( $package['contents'] ?? array() ) as $item ) {
			$product = $item['data'] ?? null;
			if ( ! $product instanceof \WC_Product || ! $product->needs_shipping() ) {
				continue;
			}
			$lines[] = array(
				'qty'    => (int) $item['quantity'],
				'weight' => (float) wc_get_weight( (float) $product->get_weight(), 'kg' ),
				'length' => (float) wc_get_dimension( (float) $product->get_length(), 'cm' ),
				'width'  => (float) wc_get_dimension( (float) $product->get_width(), 'cm' ),
				'height' => (float) wc_get_dimension( (float) $product->get_height(), 'cm' ),
			);
		}

		$spec = ( new PackageBuilder() )->build(
			$lines,
			array(
				'weight' => $settings->get_float( 'default_weight' ),
				'length' => $settings->get_float( 'default_length' ),
				'width'  => $settings->get_float( 'default_width' ),
				'height' => $settings->get_float( 'default_height' ),
			),
			array(
				'weight' => $settings->get_float( 'box_weight' ),
				'length' => $settings->get_float( 'box_length' ),
				'width'  => $settings->get_float( 'box_width' ),
				'height' => $settings->get_float( 'box_height' ),
			)
		);

		/**
		 * Filters the parcel used for rate calculation.
		 *
		 * @since 1.0.0
		 *
		 * @param PackageSpec         $spec    Parcel.
		 * @param array<string,mixed> $package WooCommerce package.
		 */
		$filtered = apply_filters( 'dalli_delivery_package_spec', $spec, $package );
		return $filtered instanceof PackageSpec ? $filtered : $spec;
	}
}
