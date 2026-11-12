<?php
/**
 * A single delivery cost option returned by deliverycost.
 *
 * @package DalliDelivery
 */

namespace DalliDelivery\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * Value object for one rate.
 */
final class Rate {

	/**
	 * Partner code (DS, CDEK, ...).
	 *
	 * @var string
	 */
	public $partner = '';

	/**
	 * Delivery type: KUR or PVZ.
	 *
	 * @var string
	 */
	public $type = '';

	/**
	 * Service code from the Dalli services reference.
	 *
	 * @var int
	 */
	public $service = 0;

	/**
	 * Delivery price in roubles.
	 *
	 * @var float
	 */
	public $price = 0.0;

	/**
	 * Delivery period as returned by the API (usually days).
	 *
	 * @var string
	 */
	public $period = '';

	/**
	 * Informational message.
	 *
	 * @var string
	 */
	public $message = '';

	/**
	 * Zone identifier.
	 *
	 * @var string
	 */
	public $zone = '';

	/**
	 * VAT rate in percent.
	 *
	 * @var int|null
	 */
	public $vat_rate = null;

	/**
	 * Only prepaid orders can be delivered.
	 *
	 * @var bool
	 */
	public $only_prepaid = false;

	/**
	 * Build from an array (cache round trip).
	 *
	 * @param array<string,mixed> $data Data.
	 * @return self
	 */
	public static function from_array( array $data ): self {
		$rate               = new self();
		$rate->partner      = (string) ( $data['partner'] ?? '' );
		$rate->type         = (string) ( $data['type'] ?? '' );
		$rate->service      = (int) ( $data['service'] ?? 0 );
		$rate->price        = (float) ( $data['price'] ?? 0 );
		$rate->period       = (string) ( $data['period'] ?? '' );
		$rate->message      = (string) ( $data['message'] ?? '' );
		$rate->zone         = (string) ( $data['zone'] ?? '' );
		$rate->vat_rate     = isset( $data['vat_rate'] ) ? (int) $data['vat_rate'] : null;
		$rate->only_prepaid = ! empty( $data['only_prepaid'] );
		return $rate;
	}

	/**
	 * Export to a plain array.
	 *
	 * @return array<string,mixed>
	 */
	public function to_array(): array {
		return array(
			'partner'      => $this->partner,
			'type'         => $this->type,
			'service'      => $this->service,
			'price'        => $this->price,
			'period'       => $this->period,
			'message'      => $this->message,
			'zone'         => $this->zone,
			'vat_rate'     => $this->vat_rate,
			'only_prepaid' => $this->only_prepaid,
		);
	}
}
