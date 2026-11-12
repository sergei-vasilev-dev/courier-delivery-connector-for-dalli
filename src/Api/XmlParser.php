<?php
/**
 * Parses Dalli API responses.
 *
 * @package DalliDelivery
 */

namespace DalliDelivery\Api;

use DalliDelivery\Domain\Catalog;
use DalliDelivery\Domain\PickupPoint;
use DalliDelivery\Domain\Rate;
use DalliDelivery\Domain\StatusInfo;

defined( 'ABSPATH' ) || exit;

/**
 * Safe XML parsing (no DOCTYPE, no network, no entity substitution) and mapping to value objects.
 */
class XmlParser {

	/**
	 * Parse XML safely.
	 *
	 * @param string $xml Raw response body.
	 * @return \SimpleXMLElement
	 * @throws ApiException When the document is empty, malformed or declares a DOCTYPE.
	 */
	public function parse( string $xml ): \SimpleXMLElement {
		$xml = trim( $xml );
		// Strip a UTF-8 BOM.
		if ( 0 === strpos( $xml, "\xEF\xBB\xBF" ) ) {
			$xml = substr( $xml, 3 );
		}
		if ( '' === $xml ) {
			throw ApiException::malformed( esc_html__( 'Empty response from the Dalli API.', 'dalli-delivery-for-woocommerce' ) );
		}
		if ( false !== stripos( $xml, '<!DOCTYPE' ) || false !== stripos( $xml, '<!ENTITY' ) ) {
			throw ApiException::malformed( esc_html__( 'Unexpected DOCTYPE in the Dalli API response.', 'dalli-delivery-for-woocommerce' ) );
		}

		$previous = libxml_use_internal_errors( true );
		$doc      = new \DOMDocument();
		// LIBXML_NONET forbids network access; entities are not substituted (no LIBXML_NOENT).
		$loaded = $doc->loadXML( $xml, LIBXML_NONET | LIBXML_NOCDATA | LIBXML_COMPACT );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		if ( ! $loaded ) {
			throw ApiException::malformed( esc_html__( 'Malformed XML in the Dalli API response.', 'dalli-delivery-for-woocommerce' ) );
		}

		$element = simplexml_import_dom( $doc );
		if ( ! $element instanceof \SimpleXMLElement ) {
			throw ApiException::malformed( esc_html__( 'Malformed XML in the Dalli API response.', 'dalli-delivery-for-woocommerce' ) );
		}
		return $element;
	}

	/**
	 * Raise an exception when the root element carries an error attribute.
	 *
	 * Handles the generic auth error (<request error="401" .../>) and per-method errors
	 * such as <deliverycost error="6" errormsg="..."/>.
	 *
	 * @param \SimpleXMLElement $root Root element.
	 * @return void
	 * @throws ApiException On error.
	 */
	public function assert_ok( \SimpleXMLElement $root ): void {
		if ( ! isset( $root['error'] ) ) {
			return;
		}
		$code = trim( (string) $root['error'] );
		if ( '' === $code || '0' === $code ) {
			return;
		}
		$message = trim( (string) $root['errormsg'] );
		if ( '' === $message ) {
			$message = 'API error ' . $code;
		}
		if ( '401' === $code || '403' === $code ) {
			throw ApiException::auth( esc_html( $message ), esc_html( $code ) );
		}
		throw ApiException::api( esc_html( $message ), esc_html( $code ) );
	}

	/**
	 * Parse a deliverycost response (standard and x2 formats).
	 *
	 * @param string $xml Body.
	 * @return Rate[]
	 */
	public function delivery_cost( string $xml ): array {
		$root = $this->parse( $xml );
		$this->assert_ok( $root );

		$rates = array();
		if ( isset( $root->price ) ) {
			foreach ( $root->price as $node ) {
				$rates[] = $this->rate_from( $node, (string) $root['partner'] );
			}
			return array_values( array_filter( $rates ) );
		}

		if ( isset( $root['price'] ) ) {
			$rate = $this->rate_from( $root, (string) $root['partner'] );
			return $rate ? array( $rate ) : array();
		}
		return array();
	}

	/**
	 * Map one rate node.
	 *
	 * @param \SimpleXMLElement $node    Node holding the attributes.
	 * @param string            $partner Partner code from the root.
	 * @return Rate|null
	 */
	private function rate_from( \SimpleXMLElement $node, string $partner ): ?Rate {
		$price = trim( (string) $node['price'] );
		if ( '' === $price || ! is_numeric( $price ) ) {
			return null;
		}
		$type = strtoupper( trim( (string) $node['typedelivery'] ) );
		if ( ! in_array( $type, array( Catalog::TYPE_COURIER, Catalog::TYPE_PVZ ), true ) ) {
			return null;
		}
		$rate               = new Rate();
		$rate->partner      = Catalog::normalize_partner( $partner );
		$rate->type         = $type;
		$rate->service      = (int) $node['service'];
		$rate->price        = (float) $price;
		$rate->period       = trim( (string) $node['delivery_period'] );
		$rate->message      = trim( (string) $node['msg'] );
		$rate->zone         = trim( (string) $node['zone'] );
		$rate->vat_rate     = isset( $node['VATrate'] ) && is_numeric( (string) $node['VATrate'] ) ? (int) $node['VATrate'] : null;
		$rate->only_prepaid = 'T' === strtoupper( trim( (string) $node['onlyPrepaid'] ) );
		return $rate;
	}

	/**
	 * Parse the services reference.
	 *
	 * @param string $xml Body.
	 * @return array<int,string> Code => name.
	 */
	public function services( string $xml ): array {
		$root = $this->parse( $xml );
		$this->assert_ok( $root );
		$out = array();
		foreach ( $root->service as $service ) {
			$out[ (int) $service->code ] = trim( (string) $service->name );
		}
		return $out;
	}

	/**
	 * Parse pointsInfo.
	 *
	 * @param string $xml Body.
	 * @return PickupPoint[]
	 */
	public function points( string $xml ): array {
		$root = $this->parse( $xml );
		$this->assert_ok( $root );
		$points = array();
		foreach ( $root->point as $node ) {
			$code = trim( (string) $node['code'] );
			if ( '' === $code ) {
				continue;
			}
			$point               = new PickupPoint();
			$point->code         = $code;
			$point->partner      = Catalog::normalize_partner( (string) $node->partner );
			$point->name         = trim( (string) $node->name );
			$address             = trim( (string) $node->address );
			$point->address      = '' !== $address ? $address : trim( (string) $node->{'addressReduce'} );
			$point->town         = trim( (string) $node->town );
			$point->description  = trim( (string) $node->description );
			$point->schedule     = trim( (string) $node->{'workShedule'} );
			$point->phone        = trim( (string) $node->phone );
			$point->service      = (int) $node->service;
			$point->weight_limit = (float) $node->{'weightLimit'};
			$point->only_prepaid = '1' === trim( (string) $node->{'onlyPrepaid'} );
			$point->acquiring    = '1' === trim( (string) $node->acquiring );
			$point->cash         = '1' === trim( (string) $node->cash );
			$point->postamat     = '1' === trim( (string) $node->postamat );
			$gps                 = explode( ',', (string) $node->{'GPS'} );
			if ( 2 === count( $gps ) && is_numeric( trim( $gps[0] ) ) && is_numeric( trim( $gps[1] ) ) ) {
				$point->lat = (float) trim( $gps[0] );
				$point->lng = (float) trim( $gps[1] );
			}
			$points[] = $point;
		}
		return $points;
	}

	/**
	 * Parse basketcreate / editbasket.
	 *
	 * @param string $xml Body.
	 * @return array<string,array{barcode:string,errors:array<int,array{field:string,code:string,message:string}>}> Keyed by our order number.
	 */
	public function basket_create( string $xml ): array {
		$root = $this->parse( $xml );
		$this->assert_ok( $root );
		$out = array();
		foreach ( $root->order as $order ) {
			$number         = trim( (string) $order['number'] );
			$out[ $number ] = array(
				'barcode' => '',
				'errors'  => array(),
			);
			if ( isset( $order->success ) ) {
				$out[ $number ]['barcode'] = trim( (string) $order->success['barcode'] );
			}
			foreach ( $order->error as $error ) {
				$out[ $number ]['errors'][] = array(
					'field'   => trim( (string) $error['error'] ),
					'code'    => trim( (string) $error['errorCode'] ),
					'message' => trim( (string) $error['errorMessage'] ),
				);
			}
		}
		return $out;
	}

	/**
	 * Parse sendbasket. A result with errorCode 0 is a success.
	 *
	 * @param string $xml Body.
	 * @return array<int,array{number:string,barcode:string,ok:bool,code:string,message:string}>
	 */
	public function send_basket( string $xml ): array {
		$root = $this->parse( $xml );
		$this->assert_ok( $root );
		$out = array();
		foreach ( $root->order as $order ) {
			$ok      = false;
			$code    = '';
			$message = '';
			foreach ( $order->error as $error ) {
				$code    = trim( (string) $error['errorCode'] );
				$message = trim( (string) $error['errorMessage'] );
				if ( '0' === $code ) {
					$ok = true;
					break;
				}
			}
			$out[] = array(
				'number'  => trim( (string) $order['number'] ),
				'barcode' => trim( (string) $order['barcode'] ),
				'ok'      => $ok,
				'code'    => $code,
				'message' => $message,
			);
		}
		return $out;
	}

	/**
	 * Parse cancelorder.
	 *
	 * @param string $xml Body.
	 * @return array{ok:bool,code:string,message:string,ordercode:string}
	 * @throws ApiException When the response has no <response> element.
	 */
	public function cancel_order( string $xml ): array {
		$root = $this->parse( $xml );
		$this->assert_ok( $root );
		if ( ! isset( $root->response ) ) {
			throw ApiException::malformed( esc_html__( 'Unexpected cancelOrder response.', 'dalli-delivery-for-woocommerce' ) );
		}
		$response = $root->response;
		$code     = trim( (string) $response['error'] );
		return array(
			'ok'        => '0' === $code,
			'code'      => $code,
			'message'   => trim( (string) $response['message'] ),
			'ordercode' => trim( (string) $response['ordercode'] ),
		);
	}

	/**
	 * Parse statusreq.
	 *
	 * @param string $xml Body.
	 * @return StatusInfo[]
	 */
	public function statuses( string $xml ): array {
		$root = $this->parse( $xml );
		$this->assert_ok( $root );
		$out = array();
		foreach ( $root->order as $order ) {
			$info                 = new StatusInfo();
			$info->number         = trim( (string) $order['orderno'] );
			$barcode              = trim( (string) $order->barcode );
			$info->barcode        = '' !== $barcode ? $barcode : trim( (string) $order['givencode'] );
			$info->delivered_date = trim( (string) $order->delivereddate );
			$info->courier_name   = trim( (string) $order->courier->name );
			$info->courier_phone  = trim( (string) $order->courier->phone );
			$info->receipt_url    = trim( (string) $order->receipt );
			if ( isset( $order->status ) ) {
				$status           = $order->status;
				$info->status     = strtoupper( trim( (string) $status ) );
				$info->title      = trim( (string) $status['title'] );
				$info->event_time = trim( (string) $status['eventtime'] );
				$info->message    = trim( (string) $status['message'] );
			}
			if ( isset( $order->statushistory ) ) {
				foreach ( $order->statushistory->status as $entry ) {
					$info->history[] = array(
						'status'      => strtoupper( trim( (string) $entry ) ),
						'title'       => trim( (string) $entry['title'] ),
						'event_time'  => trim( (string) $entry['eventtime'] ),
						'event_store' => trim( (string) $entry['eventstore'] ),
						'created_gmt' => trim( (string) $entry['createtimegmt'] ),
					);
				}
			}
			$out[] = $info;
		}
		return $out;
	}

	/**
	 * Parse the commitlaststatus acknowledgement.
	 *
	 * @param string $xml Body.
	 * @return bool
	 */
	public function commit( string $xml ): bool {
		$root = $this->parse( $xml );
		return '0' === trim( (string) $root['error'] );
	}

	/**
	 * Parse intervals (both output=intervals and output=dates).
	 *
	 * @param string $xml Body.
	 * @return array<string,array<int,array{min:string,max:string,type:string}>> Date (or empty string key for undated) => intervals.
	 */
	public function intervals( string $xml ): array {
		$root = $this->parse( $xml );
		$this->assert_ok( $root );
		$out = array();
		if ( isset( $root->date ) ) {
			foreach ( $root->date as $date ) {
				$key         = trim( (string) $date['value'] );
				$out[ $key ] = $this->interval_list( $date->intervals );
			}
			return $out;
		}
		$out[''] = $this->interval_list( $root );
		return $out;
	}

	/**
	 * Collect <interval> children.
	 *
	 * @param \SimpleXMLElement $container Container with interval children.
	 * @return array<int,array{min:string,max:string,type:string}>
	 */
	private function interval_list( \SimpleXMLElement $container ): array {
		$list = array();
		foreach ( $container->interval as $interval ) {
			$min = self::normalize_time( (string) $interval->time_min );
			$max = self::normalize_time( (string) $interval->time_max );
			if ( '' === $min || '' === $max ) {
				continue;
			}
			$list[] = array(
				'min'  => $min,
				'max'  => $max,
				'type' => trim( (string) $interval['type'] ),
			);
		}
		return $list;
	}

	/**
	 * Normalise "9", "09:00", "09:00:00" to "HH:MM".
	 *
	 * @param string $time Raw time.
	 * @return string Empty when invalid.
	 */
	public static function normalize_time( string $time ): string {
		$time = trim( $time );
		if ( preg_match( '/^(\d{1,2})(?::(\d{2}))?(?::\d{2})?$/', $time, $m ) ) {
			$hours   = (int) $m[1];
			$minutes = isset( $m[2] ) ? (int) $m[2] : 0;
			if ( $hours > 23 || $minutes > 59 ) {
				return '';
			}
			return sprintf( '%02d:%02d', $hours, $minutes );
		}
		return '';
	}

	/**
	 * Extract a binary file from stickers / getact responses.
	 *
	 * The API streams the PDF by default; with <returnas>base64</returnas> it wraps it in XML.
	 *
	 * @param string $body Raw body.
	 * @return string Binary file contents.
	 * @throws ApiException When no file can be extracted.
	 */
	public function file( string $body ): string {
		if ( 0 === strpos( ltrim( $body ), '%PDF' ) ) {
			return $body;
		}
		$root = $this->parse( $body );
		$this->assert_ok( $root );
		$encoded = preg_replace( '/\s+/', '', (string) $root->base64 );
		if ( null === $encoded || '' === $encoded ) {
			throw ApiException::malformed( esc_html__( 'The response does not contain a file.', 'dalli-delivery-for-woocommerce' ) );
		}
		$binary = base64_decode( $encoded, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- decodes the PDF returned by the API, not code.
		if ( false === $binary || '' === $binary ) {
			throw ApiException::malformed( esc_html__( 'The file in the response is not valid base64.', 'dalli-delivery-for-woocommerce' ) );
		}
		return $binary;
	}
}
