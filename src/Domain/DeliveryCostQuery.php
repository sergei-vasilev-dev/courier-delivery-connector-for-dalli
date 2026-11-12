<?php
/**
 * Parameters of a deliverycost request.
 *
 * @package DalliDelivery
 */

namespace DalliDelivery\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * Value object describing a rate request.
 */
final class DeliveryCostQuery {

	/**
	 * Partner code.
	 *
	 * @var string
	 */
	public $partner = Catalog::PARTNER_DS;

	/**
	 * Full destination address.
	 *
	 * @var string
	 */
	public $to = '';

	/**
	 * Weight in kg.
	 *
	 * @var float
	 */
	public $weight = 0.0;

	/**
	 * Length in cm.
	 *
	 * @var float
	 */
	public $length = 0.0;

	/**
	 * Width in cm.
	 *
	 * @var float
	 */
	public $width = 0.0;

	/**
	 * Height in cm.
	 *
	 * @var float
	 */
	public $height = 0.0;

	/**
	 * Cash on delivery amount.
	 *
	 * @var float|null
	 */
	public $price = null;

	/**
	 * Declared value.
	 *
	 * @var float|null
	 */
	public $declared_value = null;

	/**
	 * Sender (pickup address) code.
	 *
	 * @var string
	 */
	public $sender_code = '';

	/**
	 * Delivery type, only used when $extended is false.
	 *
	 * @var string
	 */
	public $type = Catalog::TYPE_COURIER;

	/**
	 * Use the extended (x2) response with several options.
	 *
	 * @var bool
	 */
	public $extended = true;

	/**
	 * Stable hash of the parameters, used as a cache key.
	 *
	 * @return string
	 */
	public function hash(): string {
		return md5(
			wp_json_encode(
				array(
					$this->partner,
					$this->to,
					round( $this->weight, 3 ),
					round( $this->length, 1 ),
					round( $this->width, 1 ),
					round( $this->height, 1 ),
					$this->price,
					$this->declared_value,
					$this->sender_code,
					$this->type,
					$this->extended,
				)
			)
		);
	}
}
