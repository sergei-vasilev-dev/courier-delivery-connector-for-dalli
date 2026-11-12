<?php
/**
 * A partner pickup point (PVZ).
 *
 * @package DalliDelivery
 */

namespace DalliDelivery\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * Value object for a pickup point from pointsInfo.
 */
final class PickupPoint {

	/**
	 * Unique point code used as <pvzcode>.
	 *
	 * @var string
	 */
	public $code = '';

	/**
	 * Partner code (normalised).
	 *
	 * @var string
	 */
	public $partner = '';

	/**
	 * Name.
	 *
	 * @var string
	 */
	public $name = '';

	/**
	 * Full address.
	 *
	 * @var string
	 */
	public $address = '';

	/**
	 * Town.
	 *
	 * @var string
	 */
	public $town = '';

	/**
	 * Free-form description / directions.
	 *
	 * @var string
	 */
	public $description = '';

	/**
	 * Working hours.
	 *
	 * @var string
	 */
	public $schedule = '';

	/**
	 * Latitude.
	 *
	 * @var float|null
	 */
	public $lat = null;

	/**
	 * Longitude.
	 *
	 * @var float|null
	 */
	public $lng = null;

	/**
	 * Phone.
	 *
	 * @var string
	 */
	public $phone = '';

	/**
	 * Service code.
	 *
	 * @var int
	 */
	public $service = 0;

	/**
	 * Weight limit in kg (0 = unknown).
	 *
	 * @var float
	 */
	public $weight_limit = 0.0;

	/**
	 * Accepts only prepaid orders.
	 *
	 * @var bool
	 */
	public $only_prepaid = false;

	/**
	 * Accepts card payments.
	 *
	 * @var bool
	 */
	public $acquiring = false;

	/**
	 * Accepts cash.
	 *
	 * @var bool
	 */
	public $cash = false;

	/**
	 * Is a parcel locker.
	 *
	 * @var bool
	 */
	public $postamat = false;

	/**
	 * Build from an array.
	 *
	 * @param array<string,mixed> $data Data.
	 * @return self
	 */
	public static function from_array( array $data ): self {
		$point               = new self();
		$point->code         = (string) ( $data['code'] ?? '' );
		$point->partner      = (string) ( $data['partner'] ?? '' );
		$point->name         = (string) ( $data['name'] ?? '' );
		$point->address      = (string) ( $data['address'] ?? '' );
		$point->town         = (string) ( $data['town'] ?? '' );
		$point->description  = (string) ( $data['description'] ?? '' );
		$point->schedule     = (string) ( $data['schedule'] ?? '' );
		$point->lat          = isset( $data['lat'] ) ? (float) $data['lat'] : null;
		$point->lng          = isset( $data['lng'] ) ? (float) $data['lng'] : null;
		$point->phone        = (string) ( $data['phone'] ?? '' );
		$point->service      = (int) ( $data['service'] ?? 0 );
		$point->weight_limit = (float) ( $data['weight_limit'] ?? 0 );
		$point->only_prepaid = ! empty( $data['only_prepaid'] );
		$point->acquiring    = ! empty( $data['acquiring'] );
		$point->cash         = ! empty( $data['cash'] );
		$point->postamat     = ! empty( $data['postamat'] );
		return $point;
	}

	/**
	 * Export to a plain array.
	 *
	 * @return array<string,mixed>
	 */
	public function to_array(): array {
		return array(
			'code'         => $this->code,
			'partner'      => $this->partner,
			'name'         => $this->name,
			'address'      => $this->address,
			'town'         => $this->town,
			'description'  => $this->description,
			'schedule'     => $this->schedule,
			'lat'          => $this->lat,
			'lng'          => $this->lng,
			'phone'        => $this->phone,
			'service'      => $this->service,
			'weight_limit' => $this->weight_limit,
			'only_prepaid' => $this->only_prepaid,
			'acquiring'    => $this->acquiring,
			'cash'         => $this->cash,
			'postamat'     => $this->postamat,
		);
	}
}
