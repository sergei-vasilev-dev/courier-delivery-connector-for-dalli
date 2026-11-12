<?php
/**
 * Customer choices made during checkout (pickup point, delivery interval), kept in the WooCommerce session.
 *
 * @package DalliDelivery
 */

namespace DalliDelivery\Frontend;

use DalliDelivery\Support\Format;

defined( 'ABSPATH' ) || exit;

/**
 * Session storage shared by the classic checkout, the Blocks checkout and the order creation hooks.
 */
class Selection {

	private const KEY = 'dalli_delivery_selection';

	/**
	 * Whether a customer session is available.
	 *
	 * @return bool
	 */
	private function has_session(): bool {
		return function_exists( 'WC' ) && WC() && WC()->session instanceof \WC_Session;
	}

	/**
	 * Read the stored array.
	 *
	 * @return array<string,mixed>
	 */
	private function read(): array {
		if ( ! $this->has_session() ) {
			return array();
		}
		$data = WC()->session->get( self::KEY );
		return is_array( $data ) ? $data : array();
	}

	/**
	 * Persist the array.
	 *
	 * @param array<string,mixed> $data Data.
	 * @return void
	 */
	private function write( array $data ): void {
		if ( $this->has_session() ) {
			WC()->session->set( self::KEY, $data );
		}
	}

	/**
	 * Selected pickup point.
	 *
	 * @return array<string,mixed>|null PickupPoint::to_array() data.
	 */
	public function pvz(): ?array {
		$data = $this->read();
		return isset( $data['pvz'] ) && is_array( $data['pvz'] ) ? $data['pvz'] : null;
	}

	/**
	 * Selected pickup point, only when it matches the partner and the town the customer currently ships to.
	 *
	 * @param string $partner Partner code.
	 * @param string $town    Customer's town.
	 * @return array<string,mixed>|null
	 */
	public function pvz_for( string $partner, string $town ): ?array {
		$pvz = $this->pvz();
		if ( ! $pvz || ( $pvz['partner'] ?? '' ) !== $partner || ! Format::same_text( (string) ( $pvz['_town'] ?? '' ), $town ) ) {
			return null;
		}
		return $pvz;
	}

	/**
	 * Remember a pickup point.
	 *
	 * @param array<string,mixed> $point Point data.
	 * @return void
	 */
	public function set_pvz( array $point ): void {
		$data        = $this->read();
		$data['pvz'] = $point;
		$this->write( $data );
	}

	/**
	 * Forget the pickup point.
	 *
	 * @return void
	 */
	public function clear_pvz(): void {
		$data = $this->read();
		unset( $data['pvz'] );
		$this->write( $data );
	}

	/**
	 * Selected delivery interval.
	 *
	 * @return array{date:string,min:string,max:string}|null
	 */
	public function interval(): ?array {
		$data = $this->read();
		if ( isset( $data['interval']['date'], $data['interval']['min'], $data['interval']['max'] ) ) {
			return array(
				'date' => (string) $data['interval']['date'],
				'min'  => (string) $data['interval']['min'],
				'max'  => (string) $data['interval']['max'],
			);
		}
		return null;
	}

	/**
	 * Remember a delivery interval.
	 *
	 * @param string $date Date, Y-m-d.
	 * @param string $min  Start, H:i.
	 * @param string $max  End, H:i.
	 * @return void
	 */
	public function set_interval( string $date, string $min, string $max ): void {
		$data             = $this->read();
		$data['interval'] = array(
			'date' => $date,
			'min'  => $min,
			'max'  => $max,
		);
		$this->write( $data );
	}

	/**
	 * Forget the interval.
	 *
	 * @return void
	 */
	public function clear_interval(): void {
		$data = $this->read();
		unset( $data['interval'] );
		$this->write( $data );
	}

	/**
	 * Forget everything (after an order is placed).
	 *
	 * @return void
	 */
	public function clear(): void {
		$this->write( array() );
	}
}
