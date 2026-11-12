<?php
/**
 * Small formatting/sanitising helpers (pure functions).
 *
 * @package DalliDelivery
 */

namespace DalliDelivery\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Static helpers.
 */
final class Format {

	/**
	 * Build a Dalli-safe order number: 2-50 chars of Latin/Cyrillic letters, digits, "_" and "-".
	 *
	 * @param string $number   Original number (e.g. the WooCommerce order number).
	 * @param string $fallback Used when nothing usable remains.
	 * @return string
	 */
	public static function order_number( string $number, string $fallback = 'order' ): string {
		$clean = preg_replace( '/[^A-Za-z0-9А-Яа-яЁё_-]+/u', '-', $number );
		$clean = trim( (string) $clean, '-' );
		if ( '' === $clean ) {
			$clean = $fallback;
		}
		if ( function_exists( 'mb_substr' ) ) {
			$clean = mb_substr( $clean, 0, 50, 'UTF-8' );
		} else {
			$clean = substr( $clean, 0, 50 );
		}
		if ( ( function_exists( 'mb_strlen' ) ? mb_strlen( $clean, 'UTF-8' ) : strlen( $clean ) ) < 2 ) {
			$clean = 'N-' . $clean;
		}
		return $clean;
	}

	/**
	 * Normalise a Russian phone number to 11 digits starting with 7. Other numbers are returned digits-only.
	 *
	 * @param string $phone Raw phone.
	 * @return string Digits; empty when no digits.
	 */
	public static function phone( string $phone ): string {
		$digits = preg_replace( '/\D+/', '', $phone );
		$digits = null === $digits ? '' : $digits;
		if ( 11 === strlen( $digits ) && '8' === $digits[0] ) {
			return '7' . substr( $digits, 1 );
		}
		if ( 10 === strlen( $digits ) && '9' === $digits[0] ) {
			return '7' . $digits;
		}
		return $digits;
	}

	/**
	 * Human readable delivery period.
	 *
	 * @param string $period Value of delivery_period (normally days).
	 * @return string Empty when unknown.
	 */
	public static function period( string $period ): string {
		$period = trim( $period );
		if ( '' === $period ) {
			return '';
		}
		if ( ctype_digit( $period ) ) {
			$days = (int) $period;
			if ( 0 === $days ) {
				return __( 'same day', 'dalli-delivery-for-woocommerce' );
			}
			/* translators: %d: number of days */
			return sprintf( _n( '%d day', '%d days', $days, 'dalli-delivery-for-woocommerce' ), $days );
		}
		/* translators: %s: delivery period such as "1-2" */
		return sprintf( __( '%s days', 'dalli-delivery-for-woocommerce' ), $period );
	}

	/**
	 * Strip tags and entities from a product name and collapse whitespace.
	 *
	 * @param string $name Name.
	 * @return string
	 */
	public static function plain_text( string $name ): string {
		$name = wp_strip_all_tags( $name );
		$name = html_entity_decode( $name, ENT_QUOTES, 'UTF-8' );
		return trim( (string) preg_replace( '/\s+/u', ' ', $name ) );
	}

	/**
	 * Join non-empty address parts with ", ".
	 *
	 * @param array<int,string> $parts Parts.
	 * @return string
	 */
	public static function address( array $parts ): string {
		$clean = array();
		foreach ( $parts as $part ) {
			$part = trim( (string) $part );
			if ( '' !== $part && ! in_array( $part, $clean, true ) ) {
				$clean[] = $part;
			}
		}
		return implode( ', ', $clean );
	}

	/**
	 * Case-insensitive, whitespace-insensitive comparison of two texts (UTF-8 aware when mbstring is present).
	 *
	 * @param string $a First.
	 * @param string $b Second.
	 * @return bool
	 */
	public static function same_text( string $a, string $b ): bool {
		$a = trim( $a );
		$b = trim( $b );
		if ( function_exists( 'mb_strtolower' ) ) {
			return mb_strtolower( $a, 'UTF-8' ) === mb_strtolower( $b, 'UTF-8' );
		}
		return strtolower( $a ) === strtolower( $b );
	}
}
