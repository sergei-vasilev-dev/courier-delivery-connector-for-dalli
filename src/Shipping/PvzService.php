<?php
/**
 * Pickup point lists with caching.
 *
 * @package DalliDelivery
 */

namespace DalliDelivery\Shipping;

use DalliDelivery\Api\ApiException;
use DalliDelivery\Api\DalliApi;
use DalliDelivery\Domain\PickupPoint;
use DalliDelivery\Settings;
use DalliDelivery\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Fetches pointsInfo for a partner and town and caches the result in a transient (12 hours by default).
 * The vendor recommends refreshing point data roughly once a day.
 */
class PvzService {

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
	 * Last error message.
	 *
	 * @var string
	 */
	private $error = '';

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
	 * Last error message (empty when the last call succeeded).
	 *
	 * @return string
	 */
	public function error(): string {
		return $this->error;
	}

	/**
	 * Points of a partner in a town.
	 *
	 * @param string $partner Partner code.
	 * @param string $town    Town (customer city).
	 * @param string $region  Region name (optional, narrows the search).
	 * @return PickupPoint[]
	 */
	public function for_town( string $partner, string $town, string $region = '' ): array {
		$this->error = '';
		$town        = trim( $town );
		if ( '' === $town ) {
			return array();
		}

		$key    = 'dalli_pvz_' . md5( $this->settings->api_url() . '|' . $this->settings->get( 'mode' ) . '|' . $partner . '|' . $town . '|' . $region );
		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			return array_map( array( PickupPoint::class, 'from_array' ), $cached );
		}

		try {
			$points = $this->api->points( $partner, $town, $region );
		} catch ( ApiException $e ) {
			$this->error = $e->display_message();
			// "No pickup points in the city" (code 4) is an empty list, not a failure.
			$this->logger->error( sprintf( 'Pickup point request for %s failed: %s', $partner, $e->display_message() ) );
			return array();
		}

		// Keep only points of the requested partner and shrink long texts to keep the transient small.
		$kept = array();
		foreach ( $points as $point ) {
			if ( $point->partner !== $partner && '' !== $point->partner ) {
				continue;
			}
			$point->partner     = $partner;
			$point->description = self::truncate( $point->description, 300 );
			$kept[]             = $point;
		}

		/**
		 * Filters how long pickup point lists are cached.
		 *
		 * @since 1.0.0
		 *
		 * @param int $ttl Seconds.
		 */
		$ttl = (int) apply_filters( 'dalli_delivery_pvz_ttl', 12 * HOUR_IN_SECONDS );
		if ( $ttl > 0 ) {
			set_transient(
				$key,
				array_map(
					static function ( PickupPoint $point ): array {
						return $point->to_array();
					},
					$kept
				),
				$ttl
			);
		}
		return $kept;
	}

	/**
	 * Find one point by code inside a town list.
	 *
	 * @param string $partner Partner.
	 * @param string $town    Town.
	 * @param string $region  Region.
	 * @param string $code    Point code.
	 * @return PickupPoint|null
	 */
	public function find( string $partner, string $town, string $region, string $code ): ?PickupPoint {
		foreach ( $this->for_town( $partner, $town, $region ) as $point ) {
			if ( $point->code === $code ) {
				return $point;
			}
		}
		return null;
	}

	/**
	 * Truncate text.
	 *
	 * @param string $text  Text.
	 * @param int    $limit Max chars.
	 * @return string
	 */
	private static function truncate( string $text, int $limit ): string {
		if ( mb_strlen( $text, 'UTF-8' ) <= $limit ) {
			return $text;
		}
		return rtrim( mb_substr( $text, 0, $limit - 1, 'UTF-8' ) ) . '…';
	}
}
