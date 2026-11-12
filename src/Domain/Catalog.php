<?php
/**
 * Static reference data: partners, delivery types, service codes, statuses.
 *
 * @package DalliDelivery
 */

namespace DalliDelivery\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * Reference data taken from the Dalli API documentation (see docs/api-notes.md).
 */
final class Catalog {

	public const PARTNER_DS     = 'DS';
	public const PARTNER_CDEK   = 'CDEK';
	public const PARTNER_YANDEX = 'YANDEX';
	public const PARTNER_5POST  = '5POST';
	public const PARTNER_MAGNIT = 'MAGNIT';

	public const TYPE_COURIER = 'KUR';
	public const TYPE_PVZ     = 'PVZ';

	/**
	 * Partners supported by this plugin (RUPOST needs a separate API method and is out of scope for v1).
	 *
	 * @return string[] Partner codes.
	 */
	public static function partner_codes(): array {
		return array(
			self::PARTNER_DS,
			self::PARTNER_CDEK,
			self::PARTNER_YANDEX,
			self::PARTNER_5POST,
			self::PARTNER_MAGNIT,
		);
	}

	/**
	 * Human readable partner names.
	 *
	 * @return array<string,string>
	 */
	public static function partners(): array {
		return array(
			self::PARTNER_DS     => __( 'Dalli', 'dalli-delivery-for-woocommerce' ),
			self::PARTNER_CDEK   => __( 'CDEK', 'dalli-delivery-for-woocommerce' ),
			self::PARTNER_YANDEX => __( 'Yandex Delivery', 'dalli-delivery-for-woocommerce' ),
			self::PARTNER_5POST  => __( '5Post', 'dalli-delivery-for-woocommerce' ),
			self::PARTNER_MAGNIT => __( 'Magnit', 'dalli-delivery-for-woocommerce' ),
		);
	}

	/**
	 * Partner label with a safe fallback.
	 *
	 * @param string $partner Partner code.
	 * @return string
	 */
	public static function partner_label( string $partner ): string {
		$partners = self::partners();
		return $partners[ $partner ] ?? $partner;
	}

	/**
	 * Delivery type labels.
	 *
	 * @return array<string,string>
	 */
	public static function types(): array {
		return array(
			self::TYPE_COURIER => __( 'Courier', 'dalli-delivery-for-woocommerce' ),
			self::TYPE_PVZ     => __( 'Pickup point', 'dalli-delivery-for-woocommerce' ),
		);
	}

	/**
	 * Delivery type label with a safe fallback.
	 *
	 * @param string $type Type code.
	 * @return string
	 */
	public static function type_label( string $type ): string {
		$types = self::types();
		return $types[ $type ] ?? $type;
	}

	/**
	 * Normalise a partner code as returned by the pickup point API (SDEK/BOXBERRY) to the code used everywhere else.
	 *
	 * @param string $partner Raw partner code.
	 * @return string
	 */
	public static function normalize_partner( string $partner ): string {
		$partner = strtoupper( trim( $partner ) );
		$aliases = array(
			'SDEK'     => self::PARTNER_CDEK,
			'СDEK'     => self::PARTNER_CDEK, // Cyrillic "С" appears in the vendor docs.
			'BOXBERRY' => self::PARTNER_YANDEX,
			'DALLI'    => self::PARTNER_DS,
		);
		return $aliases[ $partner ] ?? $partner;
	}

	/**
	 * Fallback service code when a rate did not carry one.
	 *
	 * @param string $partner Partner code.
	 * @param string $type    Delivery type.
	 * @param string $branch  Branch: msk|spb.
	 * @return int Service code, 0 when unknown.
	 */
	public static function fallback_service( string $partner, string $type, string $branch = 'msk' ): int {
		$spb = ( 'spb' === $branch );
		$map = array(
			self::PARTNER_DS . '|' . self::TYPE_COURIER   => $spb ? 11 : 1,
			self::PARTNER_DS . '|' . self::TYPE_PVZ       => $spb ? 12 : 4,
			self::PARTNER_CDEK . '|' . self::TYPE_PVZ     => 9,
			self::PARTNER_CDEK . '|' . self::TYPE_COURIER => 10,
			self::PARTNER_YANDEX . '|' . self::TYPE_PVZ   => 13,
			self::PARTNER_YANDEX . '|' . self::TYPE_COURIER => 26,
			self::PARTNER_5POST . '|' . self::TYPE_PVZ    => 20,
			self::PARTNER_MAGNIT . '|' . self::TYPE_PVZ   => 33,
		);
		return $map[ $partner . '|' . $type ] ?? 0;
	}

	/**
	 * Services delivered by Dalli couriers themselves (support intervals and cancelOrder).
	 *
	 * @return int[]
	 */
	public static function own_courier_services(): array {
		return array( 1, 11, 22 );
	}

	/**
	 * Whether a service supports delivery date/interval selection.
	 *
	 * @param int $service Service code.
	 * @return bool
	 */
	public static function supports_intervals( int $service ): bool {
		return in_array( $service, self::own_courier_services(), true );
	}

	/**
	 * Whether cancelOrder is available for a service.
	 *
	 * @param int $service Service code.
	 * @return bool
	 */
	public static function supports_cancel( int $service ): bool {
		return in_array( $service, self::own_courier_services(), true );
	}

	/**
	 * Order statuses documented for the statusreq method, with English titles.
	 *
	 * @return array<string,string>
	 */
	public static function statuses(): array {
		return array(
			'AWAITING_SYNC'    => __( 'Awaiting synchronisation', 'dalli-delivery-for-woocommerce' ),
			'NEW'              => __( 'New', 'dalli-delivery-for-woocommerce' ),
			'NEWPICKUP'        => __( 'Pickup created', 'dalli-delivery-for-woocommerce' ),
			'PICKUP'           => __( 'Picked up from sender', 'dalli-delivery-for-woocommerce' ),
			'WMSASSEMBLED'     => __( 'Assembled at fulfilment warehouse', 'dalli-delivery-for-woocommerce' ),
			'WMSDISASSEMBLED'  => __( 'Disassembled at fulfilment warehouse', 'dalli-delivery-for-woocommerce' ),
			'ACCEPTED'         => __( 'Received by warehouse', 'dalli-delivery-for-woocommerce' ),
			'CUSTOMSPROCESS'   => __( 'Customs control in progress', 'dalli-delivery-for-woocommerce' ),
			'CUSTOMSFINISHED'  => __( 'Customs control completed', 'dalli-delivery-for-woocommerce' ),
			'CONFIRM'          => __( 'Delivery confirmed', 'dalli-delivery-for-woocommerce' ),
			'UNCONFIRM'        => __( 'Delivery could not be confirmed', 'dalli-delivery-for-woocommerce' ),
			'DEPARTURING'      => __( 'Transfer to another warehouse planned', 'dalli-delivery-for-woocommerce' ),
			'DEPARTURE'        => __( 'Sent to another warehouse', 'dalli-delivery-for-woocommerce' ),
			'INVENTORY'        => __( 'Inventory check', 'dalli-delivery-for-woocommerce' ),
			'PICKUPREADY'      => __( 'Ready for pickup at the point', 'dalli-delivery-for-woocommerce' ),
			'DELIVERY'         => __( 'Handed to courier', 'dalli-delivery-for-woocommerce' ),
			'COURIERDELIVERED' => __( 'Delivered (preliminary)', 'dalli-delivery-for-woocommerce' ),
			'COURIERPARTIALLY' => __( 'Partially delivered (preliminary)', 'dalli-delivery-for-woocommerce' ),
			'COURIERCANCELED'  => __( 'Not delivered, per courier', 'dalli-delivery-for-woocommerce' ),
			'COURIERRETURN'    => __( 'Returned by courier', 'dalli-delivery-for-woocommerce' ),
			'DATECHANGE'       => __( 'Delivery date changed', 'dalli-delivery-for-woocommerce' ),
			'COMPLETE'         => __( 'Delivered', 'dalli-delivery-for-woocommerce' ),
			'PARTIALLY'        => __( 'Partially delivered', 'dalli-delivery-for-woocommerce' ),
			'CANCELED'         => __( 'Not delivered (return or cancellation)', 'dalli-delivery-for-woocommerce' ),
			'RETURNING'        => __( 'Return to sender planned', 'dalli-delivery-for-woocommerce' ),
			'RETURNED'         => __( 'Returned to sender', 'dalli-delivery-for-woocommerce' ),
			'PARTLYRETURNING'  => __( 'Return of the remainder planned', 'dalli-delivery-for-woocommerce' ),
			'PARTLYRETURNED'   => __( 'Remainder returned to sender', 'dalli-delivery-for-woocommerce' ),
			'TRANSACCEPTED'    => __( 'Arrived at carrier warehouse', 'dalli-delivery-for-woocommerce' ),
			'PICKUPTRANS'      => __( 'Picked up from carrier', 'dalli-delivery-for-woocommerce' ),
		);
	}

	/**
	 * Label for a status code.
	 *
	 * @param string $status Status code.
	 * @return string
	 */
	public static function status_label( string $status ): string {
		$statuses = self::statuses();
		return $statuses[ $status ] ?? $status;
	}

	/**
	 * Statuses after which nothing more is expected, so polling stops.
	 *
	 * @return string[]
	 */
	public static function final_statuses(): array {
		return array( 'COMPLETE', 'PARTIALLY', 'RETURNED', 'PARTLYRETURNED' );
	}

	/**
	 * Whether a status is terminal.
	 *
	 * @param string $status Status code.
	 * @return bool
	 */
	public static function is_final_status( string $status ): bool {
		return in_array( $status, self::final_statuses(), true );
	}

	/**
	 * Short label for tariff variants of the same partner/type (e.g. express vs standard courier).
	 *
	 * @param int $service Service code.
	 * @return string Empty for the regular tariff.
	 */
	public static function service_variant_label( int $service ): string {
		switch ( $service ) {
			case 2:
				return __( 'express', 'dalli-delivery-for-woocommerce' );
			case 3:
				return __( 'urgent', 'dalli-delivery-for-woocommerce' );
			case 30:
			case 32:
				return __( 'oversized cargo', 'dalli-delivery-for-woocommerce' );
			default:
				return '';
		}
	}
}
