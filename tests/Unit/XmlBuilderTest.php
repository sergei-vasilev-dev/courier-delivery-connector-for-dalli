<?php
/**
 * Tests for the XML request builder.
 *
 * @package DalliDelivery
 */

namespace DalliDelivery\Tests\Unit;

use DalliDelivery\Api\XmlBuilder;
use DalliDelivery\Domain\DeliveryCostQuery;
use DalliDelivery\Domain\OrderItem;
use DalliDelivery\Domain\OrderPayload;
use DalliDelivery\Tests\TestCase;

class XmlBuilderTest extends TestCase {

	private function xpath( string $xml ): \DOMXPath {
		$doc = new \DOMDocument();
		$this->assertTrue( $doc->loadXML( $xml ), 'The builder must produce well-formed XML.' );
		return new \DOMXPath( $doc );
	}

	public function test_simple_request_has_root_and_auth(): void {
		$xml = ( new XmlBuilder() )->simple( 'services', 'TEST_TOKEN' );
		$this->assertStringStartsWith( '<?xml version="1.0" encoding="UTF-8"?>', $xml );
		$xp = $this->xpath( $xml );
		$this->assertSame( 'services', $xp->query( '/*' )->item( 0 )->nodeName );
		$this->assertSame( 'TEST_TOKEN', $xp->query( '/services/auth/@token' )->item( 0 )->nodeValue );
	}

	public function test_delivery_cost_request_extended(): void {
		$query                 = new DeliveryCostQuery();
		$query->partner        = 'CDEK';
		$query->to             = 'г Москва, ул Тверская & "Ко" <1>';
		$query->weight         = 1.25;
		$query->length         = 20;
		$query->width          = 10.5;
		$query->height         = 5;
		$query->declared_value = 1500.5;
		$query->sender_code    = '77';
		$xml                   = ( new XmlBuilder() )->delivery_cost( 'TEST_TOKEN', $query );
		$xp                    = $this->xpath( $xml );

		$this->assertSame( 'deliverycost', $xp->query( '/*' )->item( 0 )->nodeName );
		$this->assertSame( 'CDEK', $xp->evaluate( 'string(/deliverycost/partner)' ) );
		$this->assertSame( 'г Москва, ул Тверская & "Ко" <1>', $xp->evaluate( 'string(/deliverycost/to)' ) );
		$this->assertSame( '1.25', $xp->evaluate( 'string(/deliverycost/weight)' ) );
		$this->assertSame( '10.5', $xp->evaluate( 'string(/deliverycost/width)' ) );
		$this->assertSame( '1500.5', $xp->evaluate( 'string(/deliverycost/inshprice)' ) );
		$this->assertSame( '77', $xp->evaluate( 'string(/deliverycost/sendercode)' ) );
		$this->assertSame( 'x2', $xp->evaluate( 'string(/deliverycost/output)' ) );
		$this->assertSame( 0.0, $xp->evaluate( 'count(/deliverycost/typedelivery)' ) );
		$this->assertStringContainsString( '&amp;', $xml );
		$this->assertStringContainsString( '&lt;1&gt;', $xml );
	}

	public function test_delivery_cost_request_single_type(): void {
		$query           = new DeliveryCostQuery();
		$query->to       = 'Москва';
		$query->extended = false;
		$query->type     = 'PVZ';
		$xp              = $this->xpath( ( new XmlBuilder() )->delivery_cost( 'T', $query ) );
		$this->assertSame( 'PVZ', $xp->evaluate( 'string(/deliverycost/typedelivery)' ) );
		$this->assertSame( 0.0, $xp->evaluate( 'count(/deliverycost/output)' ) );
		$this->assertSame( 0.0, $xp->evaluate( 'count(/deliverycost/weight)' ) );
	}

	public function test_control_characters_are_removed(): void {
		$this->assertSame( 'ab', XmlBuilder::clean( "a\x00\x0B\x1Fb" ) );
		$this->assertSame( "a\tb\nc", XmlBuilder::clean( "a\tb\nc" ) );
		$xml = ( new XmlBuilder() )->simple( 'x', "TO\x00KEN", array( 'note' => "bad\x08char" ) );
		$this->assertStringNotContainsString( "\x00", $xml );
		$this->assertStringNotContainsString( "\x08", $xml );
	}

	public function test_number_formatting(): void {
		$this->assertSame( '0', XmlBuilder::number( 0 ) );
		$this->assertSame( '1100', XmlBuilder::number( 1100.0 ) );
		$this->assertSame( '99.9', XmlBuilder::number( 99.90 ) );
		$this->assertSame( '1234.57', XmlBuilder::number( 1234.567 ) );
		$this->assertSame( '0.25', XmlBuilder::number( 0.25 ) );
		$this->assertSame( '0.125', XmlBuilder::number( 0.125, 3 ) );
	}

	private function payload(): OrderPayload {
		$item           = new OrderItem();
		$item->name     = 'Чай "Эрл Грей" & <мёд>';
		$item->quantity = 2;
		$item->weight   = 0.25;
		$item->price    = 499.5;
		$item->article  = 'SKU-1';
		$item->barcode  = 'SKU-1';
		$item->vat_rate = 22;

		$payload                 = new OrderPayload();
		$payload->number         = 'WC-1001';
		$payload->service        = 1;
		$payload->paytype        = 'CASH';
		$payload->price          = 1199;
		$payload->priced         = 200;
		$payload->declared_value = 1199;
		$payload->address        = 'г Москва, ул Складочная, д 1';
		$payload->person         = 'Иван Иванов';
		$payload->phone          = '79991234567, ivan@example.test';
		$payload->date           = '2030-01-02';
		$payload->time_min       = '10:00';
		$payload->time_max       = '22:00';
		$payload->instruction    = 'Домофон <10> & "5"';
		$payload->items          = array( $item );
		return $payload;
	}

	public function test_basket_create_courier_order(): void {
		$xml = ( new XmlBuilder() )->basket_create( 'TEST_TOKEN', $this->payload(), true );
		$xp  = $this->xpath( $xml );

		$this->assertSame( 'T', $xp->evaluate( 'string(/basketcreate/@autosend)' ) );
		$this->assertSame( 'WC-1001', $xp->evaluate( 'string(/basketcreate/order/@number)' ) );
		$this->assertSame( 'г Москва, ул Складочная, д 1', $xp->evaluate( 'string(//receiver/address)' ) );
		$this->assertSame( 0.0, $xp->evaluate( 'count(//receiver/pvzcode)' ) );
		$this->assertSame( '79991234567, ivan@example.test', $xp->evaluate( 'string(//receiver/phone)' ) );
		$this->assertSame( '2030-01-02', $xp->evaluate( 'string(//receiver/date)' ) );
		$this->assertSame( '1', $xp->evaluate( 'string(/basketcreate/order/service)' ) );
		$this->assertSame( 'CASH', $xp->evaluate( 'string(/basketcreate/order/paytype)' ) );
		$this->assertSame( '1199', $xp->evaluate( 'string(/basketcreate/order/price)' ) );
		$this->assertSame( '200', $xp->evaluate( 'string(/basketcreate/order/priced)' ) );
		$this->assertSame( 'Чай "Эрл Грей" & <мёд>', $xp->evaluate( 'string(//items/item)' ) );
		$this->assertSame( '2', $xp->evaluate( 'string(//items/item/@quantity)' ) );
		$this->assertSame( '499.5', $xp->evaluate( 'string(//items/item/@retprice)' ) );
		$this->assertSame( '22', $xp->evaluate( 'string(//items/item/@VATrate)' ) );
		$this->assertSame( 'Домофон <10> & "5"', $xp->evaluate( 'string(/basketcreate/order/instruction)' ) );
		$this->assertSame( 0.0, $xp->evaluate( 'count(/basketcreate/order/acceptpartially)' ) );
	}

	public function test_basket_create_pvz_order_and_no_autosend(): void {
		$payload           = $this->payload();
		$payload->service  = 13;
		$payload->pvz_code = '53f5b1e4-9cf0-437c-b8b3-d082dbb9c2f4';
		$payload->address  = '';
		$xml               = ( new XmlBuilder() )->basket_create( 'T', $payload, false );
		$xp                = $this->xpath( $xml );
		$this->assertSame( '53f5b1e4-9cf0-437c-b8b3-d082dbb9c2f4', $xp->evaluate( 'string(//receiver/pvzcode)' ) );
		$this->assertSame( 0.0, $xp->evaluate( 'count(//receiver/address)' ) );
		$this->assertSame( 0.0, $xp->evaluate( 'count(/basketcreate/@autosend)' ) );
	}

	public function test_documents_and_status_requests(): void {
		$builder = new XmlBuilder();
		$xp      = $this->xpath( $builder->documents( 'stickers', 'T', array( 'A1', 'A2' ), 4 ) );
		$this->assertSame( 'base64', $xp->evaluate( 'string(/stickers/returnas)' ) );
		$this->assertSame( '4', $xp->evaluate( 'string(/stickers/format)' ) );
		$this->assertSame( 2.0, $xp->evaluate( 'count(/stickers/barcodes/barcode)' ) );

		$xp = $this->xpath( $builder->documents( 'getact', 'T', array() ) );
		$this->assertSame( 0.0, $xp->evaluate( 'count(/getact/barcodes)' ) );
		$this->assertSame( 0.0, $xp->evaluate( 'count(/getact/format)' ) );

		$xp = $this->xpath( $builder->status( 'T', 'A1' ) );
		$this->assertSame( 'A1', $xp->evaluate( 'string(/statusreq/orderno)' ) );
		$xp = $this->xpath( $builder->status( 'T', '', true ) );
		$this->assertSame( 'ONLY_LAST', $xp->evaluate( 'string(/statusreq/changes)' ) );

		$xp = $this->xpath( $builder->by_orders( 'cancelorder', 'T', array( 'A1' ), array( 'N-1' ) ) );
		$this->assertSame( 'A1', $xp->evaluate( 'string(/cancelorder/barcode)' ) );
		$this->assertSame( 'N-1', $xp->evaluate( 'string(/cancelorder/number)' ) );
	}

	public function test_points_and_intervals_requests(): void {
		$builder = new XmlBuilder();
		$xp      = $this->xpath( $builder->points( 'T', 'CDEK', 'Москва', 'Московская область' ) );
		$this->assertSame( 'CDEK', $xp->evaluate( 'string(/pointsInfo/partner)' ) );
		$this->assertSame( 'Москва', $xp->evaluate( 'string(/pointsInfo/town)' ) );
		$xp = $this->xpath( $builder->points( 'T', 'DS', 'Москва', '', '0c5b2444-70a0-4932-980c-b4dc0d3f02b5' ) );
		$this->assertSame( 0.0, $xp->evaluate( 'count(/pointsInfo/town)' ) );
		$this->assertSame( '0c5b2444-70a0-4932-980c-b4dc0d3f02b5', $xp->evaluate( 'string(/pointsInfo/fias)' ) );

		$xp = $this->xpath( $builder->intervals( 'T', 'Москва', 1, 'dates', '2030-01-01' ) );
		$this->assertSame( '2030-01-01', $xp->evaluate( 'string(/intervals/pickup_date)' ) );
		$this->assertSame( 'minutes', $xp->evaluate( 'string(/intervals/format)' ) );
		$this->assertSame( 'dates', $xp->evaluate( 'string(/intervals/output)' ) );
	}
}
