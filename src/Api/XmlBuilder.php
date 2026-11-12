<?php
/**
 * Builds XML request bodies for the Dalli API.
 *
 * @package DalliDelivery
 */

namespace DalliDelivery\Api;

use DalliDelivery\Domain\Catalog;
use DalliDelivery\Domain\DeliveryCostQuery;
use DalliDelivery\Domain\OrderPayload;

defined( 'ABSPATH' ) || exit;

/**
 * All request documents are produced with DOM, so every value is escaped
 * (& < > ") and control characters illegal in XML 1.0 are stripped.
 */
class XmlBuilder {

	/**
	 * Strip characters that are not allowed in XML 1.0 documents.
	 *
	 * @param string $value Raw text.
	 * @return string
	 */
	public static function clean( string $value ): string {
		$clean = preg_replace( '/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $value );
		return null === $clean ? '' : $clean;
	}

	/**
	 * Format a decimal number with a dot separator and no trailing noise.
	 *
	 * @param float $number   Number.
	 * @param int   $decimals Max decimals.
	 * @return string
	 */
	public static function number( float $number, int $decimals = 2 ): string {
		$formatted = number_format( $number, $decimals, '.', '' );
		if ( false !== strpos( $formatted, '.' ) ) {
			$formatted = rtrim( rtrim( $formatted, '0' ), '.' );
		}
		return '' === $formatted || '-0' === $formatted ? '0' : $formatted;
	}

	/**
	 * Create a document with a root element and the auth tag.
	 *
	 * @param string               $root       Root element name.
	 * @param string               $token      API token.
	 * @param array<string,string> $root_attrs Root attributes.
	 * @return array{0:\DOMDocument,1:\DOMElement} Document and root.
	 */
	private function document( string $root, string $token, array $root_attrs = array() ): array {
		$doc     = new \DOMDocument( '1.0', 'UTF-8' );
		$root_el = $doc->createElement( $root );
		foreach ( $root_attrs as $name => $value ) {
			$root_el->setAttribute( $name, self::clean( $value ) );
		}
		$doc->appendChild( $root_el );
		$auth = $doc->createElement( 'auth' );
		$auth->setAttribute( 'token', self::clean( $token ) );
		$root_el->appendChild( $auth );
		return array( $doc, $root_el );
	}

	/**
	 * Append <name>text</name>.
	 *
	 * @param \DOMElement $container Container.
	 * @param string      $name      Element name.
	 * @param string      $text      Text.
	 * @return \DOMElement
	 */
	private function text_el( \DOMElement $container, string $name, string $text ): \DOMElement {
		$doc = $this->owner( $container );
		$el  = $doc->createElement( $name );
		$el->appendChild( $doc->createTextNode( self::clean( $text ) ) );
		$container->appendChild( $el );
		return $el;
	}

	/**
	 * The document an element belongs to.
	 *
	 * @param \DOMElement $element Element.
	 * @return \DOMDocument
	 */
	private function owner( \DOMElement $element ): \DOMDocument {
		$doc = $element->ownerDocument; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOM API property.
		return $doc instanceof \DOMDocument ? $doc : new \DOMDocument( '1.0', 'UTF-8' );
	}

	/**
	 * Serialise.
	 *
	 * @param \DOMDocument $doc Document.
	 * @return string
	 */
	private function dump( \DOMDocument $doc ): string {
		$xml = $doc->saveXML();
		return false === $xml ? '' : $xml;
	}

	/**
	 * Request with only a root and auth, plus optional simple children.
	 *
	 * @param string               $root     Root element name (the API method).
	 * @param string               $token    Token.
	 * @param array<string,string> $children Child element name => text.
	 * @return string
	 */
	public function simple( string $root, string $token, array $children = array() ): string {
		list( $doc, $root_el ) = $this->document( $root, $token );
		foreach ( $children as $name => $text ) {
			$this->text_el( $root_el, $name, $text );
		}
		return $this->dump( $doc );
	}

	/**
	 * Request that selects orders by barcode and/or number (sendbasket, cancelorder, removebasket, getbasket).
	 *
	 * @param string   $root     Root element name.
	 * @param string   $token    Token.
	 * @param string[] $barcodes Barcodes.
	 * @param string[] $numbers  Order numbers.
	 * @return string
	 */
	public function by_orders( string $root, string $token, array $barcodes = array(), array $numbers = array() ): string {
		list( $doc, $root_el ) = $this->document( $root, $token );
		foreach ( $barcodes as $barcode ) {
			$this->text_el( $root_el, 'barcode', (string) $barcode );
		}
		foreach ( $numbers as $number ) {
			$this->text_el( $root_el, 'number', (string) $number );
		}
		return $this->dump( $doc );
	}

	/**
	 * Request for stickers / stickersbasket / getact.
	 *
	 * @param string   $root     Root element name.
	 * @param string   $token    Token.
	 * @param string[] $barcodes Barcodes (empty = all of today).
	 * @param int      $format   Paper format (stickers only), 0 to omit.
	 * @return string
	 */
	public function documents( string $root, string $token, array $barcodes, int $format = 0 ): string {
		list( $doc, $root_el ) = $this->document( $root, $token );
		$this->text_el( $root_el, 'returnas', 'base64' );
		if ( $format > 0 ) {
			$this->text_el( $root_el, 'format', (string) $format );
		}
		if ( $barcodes ) {
			$list = $doc->createElement( 'barcodes' );
			$root_el->appendChild( $list );
			foreach ( $barcodes as $barcode ) {
				$this->text_el( $list, 'barcode', (string) $barcode );
			}
		}
		return $this->dump( $doc );
	}

	/**
	 * Request for statusreq.
	 *
	 * @param string $token   Token.
	 * @param string $orderno Order number or barcode.
	 * @param bool   $changes Use the ONLY_LAST feed.
	 * @return string
	 */
	public function status( string $token, string $orderno = '', bool $changes = false ): string {
		list( $doc, $root_el ) = $this->document( 'statusreq', $token );
		if ( $changes ) {
			$this->text_el( $root_el, 'changes', 'ONLY_LAST' );
		} else {
			$this->text_el( $root_el, 'orderno', $orderno );
		}
		return $this->dump( $doc );
	}

	/**
	 * Request for pointsInfo.
	 *
	 * @param string $token   Token.
	 * @param string $partner Partner code.
	 * @param string $town    Town.
	 * @param string $region  Region (settlement).
	 * @param string $fias    FIAS code (preferred when known).
	 * @return string
	 */
	public function points( string $token, string $partner, string $town = '', string $region = '', string $fias = '' ): string {
		list( $doc, $root_el ) = $this->document( 'pointsInfo', $token );
		if ( '' !== $fias ) {
			$this->text_el( $root_el, 'fias', $fias );
		} else {
			if ( '' !== $town ) {
				$this->text_el( $root_el, 'town', $town );
			}
			if ( '' !== $region ) {
				$this->text_el( $root_el, 'settlement', $region );
			}
		}
		if ( '' !== $partner ) {
			$this->text_el( $root_el, 'partner', $partner );
		}
		return $this->dump( $doc );
	}

	/**
	 * Request for intervals.
	 *
	 * @param string $token   Token.
	 * @param string $address Full address.
	 * @param int    $service Service code, 0 to omit.
	 * @param string $output  intervals|dates.
	 * @param string $date    Date (intervals) or pickup date (dates), Y-m-d.
	 * @return string
	 */
	public function intervals( string $token, string $address, int $service = 0, string $output = 'intervals', string $date = '' ): string {
		list( $doc, $root_el ) = $this->document( 'intervals', $token );
		$this->text_el( $root_el, 'address', $address );
		if ( $service > 0 ) {
			$this->text_el( $root_el, 'service', (string) $service );
		}
		if ( '' !== $date ) {
			$this->text_el( $root_el, 'dates' === $output ? 'pickup_date' : 'date', $date );
		}
		$this->text_el( $root_el, 'format', 'minutes' );
		$this->text_el( $root_el, 'output', $output );
		return $this->dump( $doc );
	}

	/**
	 * Request for deliverycost.
	 *
	 * @param string            $token Token.
	 * @param DeliveryCostQuery $query Query.
	 * @return string
	 */
	public function delivery_cost( string $token, DeliveryCostQuery $query ): string {
		list( $doc, $root_el ) = $this->document( 'deliverycost', $token );
		$this->text_el( $root_el, 'partner', $query->partner );
		$this->text_el( $root_el, 'to', $query->to );
		if ( $query->weight > 0 ) {
			$this->text_el( $root_el, 'weight', self::number( $query->weight, 3 ) );
		}
		foreach ( array(
			'length' => $query->length,
			'width'  => $query->width,
			'height' => $query->height,
		) as $name => $value ) {
			if ( $value > 0 ) {
				$this->text_el( $root_el, $name, self::number( $value, 1 ) );
			}
		}
		if ( null !== $query->price ) {
			$this->text_el( $root_el, 'price', self::number( $query->price ) );
		}
		if ( null !== $query->declared_value ) {
			$this->text_el( $root_el, 'inshprice', self::number( $query->declared_value ) );
		}
		if ( '' !== $query->sender_code ) {
			$this->text_el( $root_el, 'sendercode', $query->sender_code );
		}
		if ( $query->extended ) {
			$this->text_el( $root_el, 'output', 'x2' );
		} else {
			$this->text_el( $root_el, 'typedelivery', Catalog::TYPE_PVZ === $query->type ? Catalog::TYPE_PVZ : Catalog::TYPE_COURIER );
		}
		return $this->dump( $doc );
	}

	/**
	 * Request for basketcreate with a single order.
	 *
	 * @param string       $token   Token.
	 * @param OrderPayload $payload Order.
	 * @param bool         $autosend Send straight to pickup, skipping the basket.
	 * @return string
	 */
	public function basket_create( string $token, OrderPayload $payload, bool $autosend = true ): string {
		list( $doc, $root_el ) = $this->document( 'basketcreate', $token, $autosend ? array( 'autosend' => 'T' ) : array() );

		$order = $doc->createElement( 'order' );
		$order->setAttribute( 'number', self::clean( $payload->number ) );
		$root_el->appendChild( $order );

		$receiver = $doc->createElement( 'receiver' );
		$order->appendChild( $receiver );
		if ( '' !== $payload->pvz_code ) {
			$this->text_el( $receiver, 'pvzcode', $payload->pvz_code );
		}
		if ( '' !== $payload->address || '' === $payload->pvz_code ) {
			$this->text_el( $receiver, 'address', $payload->address );
		}
		$this->text_el( $receiver, 'person', $payload->person );
		$this->text_el( $receiver, 'phone', $payload->phone );
		$this->text_el( $receiver, 'date', $payload->date );
		$this->text_el( $receiver, 'time_min', $payload->time_min );
		$this->text_el( $receiver, 'time_max', $payload->time_max );

		$this->text_el( $order, 'service', (string) $payload->service );
		$this->text_el( $order, 'quantity', (string) max( 1, $payload->quantity ) );
		$this->text_el( $order, 'paytype', $payload->paytype );
		$this->text_el( $order, 'priced', self::number( $payload->priced ) );
		$this->text_el( $order, 'price', self::number( $payload->price ) );
		$this->text_el( $order, 'inshprice', self::number( $payload->declared_value ) );
		if ( '' !== $payload->sender_code ) {
			$this->text_el( $order, 'sendercode', $payload->sender_code );
		}
		if ( '' !== $payload->instruction ) {
			$this->text_el( $order, 'instruction', $payload->instruction );
		}
		if ( '' !== $payload->accept_partially ) {
			$this->text_el( $order, 'acceptpartially', $payload->accept_partially );
		}

		$items = $doc->createElement( 'items' );
		$order->appendChild( $items );
		foreach ( $payload->items as $item ) {
			$el = $this->text_el( $items, 'item', $item->name );
			$el->setAttribute( 'quantity', (string) max( 1, $item->quantity ) );
			$el->setAttribute( 'weight', self::number( $item->weight, 3 ) );
			$el->setAttribute( 'retprice', self::number( $item->price ) );
			if ( '' !== $item->barcode ) {
				$el->setAttribute( 'barcode', self::clean( $item->barcode ) );
			}
			if ( '' !== $item->article ) {
				$el->setAttribute( 'article', self::clean( $item->article ) );
			}
			$el->setAttribute( 'VATrate', (string) $item->vat_rate );
		}

		return $this->dump( $doc );
	}
}
