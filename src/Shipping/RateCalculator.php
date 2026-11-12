<?php
/**
 * Fetches (and caches) delivery cost options from Dalli.
 *
 * @package DalliDelivery
 */

namespace DalliDelivery\Shipping;

use DalliDelivery\Api\ApiException;
use DalliDelivery\Api\DalliApi;
use DalliDelivery\Domain\DeliveryCostQuery;
use DalliDelivery\Domain\Rate;
use DalliDelivery\Settings;
use DalliDelivery\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Calls deliverycost once per enabled partner (extended x2 format) and caches the raw answers
 * in transients keyed by a hash of the request, so identical carts/addresses do not hit the API again.
 */
class RateCalculator {

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
	 * Errors of the last calculation.
	 *
	 * @var string[]
	 */
	private $errors = array();

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
	 * Errors that occurred during the last call to calculate().
	 *
	 * @return string[]
	 */
	public function errors(): array {
		return $this->errors;
	}

	/**
	 * Calculate options for all requested partners.
	 *
	 * @param PackageSpec            $package        Parcel.
	 * @param string                 $address        Destination address.
	 * @param float                  $declared_value Declared value (0 = omit).
	 * @param array<string,string[]> $partner_types  Partner => allowed delivery types.
	 * @param string                 $sender_code    Pickup address code.
	 * @return Rate[]
	 */
	public function calculate( PackageSpec $package, string $address, float $declared_value, array $partner_types, string $sender_code = '' ): array {
		$this->errors = array();
		$rates        = array();

		foreach ( $partner_types as $partner => $types ) {
			$query                 = new DeliveryCostQuery();
			$query->partner        = (string) $partner;
			$query->to             = $address;
			$query->weight         = $package->weight;
			$query->length         = $package->length;
			$query->width          = $package->width;
			$query->height         = $package->height;
			$query->declared_value = $declared_value > 0 ? round( $declared_value, 2 ) : null;
			$query->sender_code    = $sender_code;
			$query->extended       = true;

			foreach ( $this->for_partner( $query ) as $rate ) {
				if ( in_array( $rate->type, $types, true ) ) {
					$rates[] = $rate;
				}
			}
		}

		return $rates;
	}

	/**
	 * Rates of one partner, from cache when possible.
	 *
	 * @param DeliveryCostQuery $query Query.
	 * @return Rate[]
	 */
	private function for_partner( DeliveryCostQuery $query ): array {
		$key = 'dalli_rates_' . md5( $this->settings->api_url() . '|' . $this->settings->get( 'mode' ) . '|' . $query->hash() );

		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			return array_map( array( Rate::class, 'from_array' ), $cached );
		}

		$ttl = $this->settings->rates_ttl();
		try {
			$rates = $this->api->delivery_cost( $query );
			if ( $ttl > 0 ) {
				set_transient(
					$key,
					array_map(
						static function ( Rate $rate ): array {
							return $rate->to_array();
						},
						$rates
					),
					$ttl
				);
			}
			return $rates;
		} catch ( ApiException $e ) {
			$this->errors[] = $e->display_message();
			// Codes 1, 4, 6, 12 mean "no price for this partner/destination": cache the empty answer briefly.
			if ( ApiException::TYPE_API === $e->get_type() ) {
				$this->logger->info( sprintf( 'No rates from %s: [%s] %s', $query->partner, $e->get_api_code(), $e->display_message() ) );
				if ( $ttl > 0 ) {
					set_transient( $key, array(), min( $ttl, 5 * MINUTE_IN_SECONDS ) );
				}
			} else {
				$this->logger->error( sprintf( 'Rate request for %s failed: %s', $query->partner, $e->display_message() ) );
			}
			return array();
		}
	}
}
