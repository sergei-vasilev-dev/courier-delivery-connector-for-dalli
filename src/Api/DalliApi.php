<?php
/**
 * High-level Dalli API facade.
 *
 * @package DalliDelivery
 */

namespace DalliDelivery\Api;

use DalliDelivery\Domain\DeliveryCostQuery;
use DalliDelivery\Domain\OrderPayload;
use DalliDelivery\Domain\PickupPoint;
use DalliDelivery\Domain\Rate;
use DalliDelivery\Domain\StatusInfo;

defined( 'ABSPATH' ) || exit;

/**
 * One method per Dalli API call used by the plugin.
 */
class DalliApi {

	/**
	 * Transport.
	 *
	 * @var Client
	 */
	private $client;

	/**
	 * Request builder.
	 *
	 * @var XmlBuilder
	 */
	private $builder;

	/**
	 * Response parser.
	 *
	 * @var XmlParser
	 */
	private $parser;

	/**
	 * Constructor.
	 *
	 * @param Client     $client  Transport.
	 * @param XmlBuilder $builder Builder.
	 * @param XmlParser  $parser  Parser.
	 */
	public function __construct( Client $client, XmlBuilder $builder, XmlParser $parser ) {
		$this->client  = $client;
		$this->builder = $builder;
		$this->parser  = $parser;
	}

	/**
	 * Cheap call used for the "Test connection" button: the delivery types of the contract.
	 *
	 * @return array<int,string> Service code => name.
	 */
	public function services(): array {
		$body = $this->client->send( $this->builder->simple( 'services', $this->client->token() ), array( 'retries' => 0 ) );
		return $this->parser->services( $body );
	}

	/**
	 * Calculate delivery cost options.
	 *
	 * @param DeliveryCostQuery $query Query.
	 * @return Rate[]
	 */
	public function delivery_cost( DeliveryCostQuery $query ): array {
		$body = $this->client->send(
			$this->builder->delivery_cost( $this->client->token(), $query ),
			array(
				'timeout' => 12,
				'retries' => 1,
			)
		);
		return $this->parser->delivery_cost( $body );
	}

	/**
	 * Pickup points for a town (and partner).
	 *
	 * @param string $partner Partner code.
	 * @param string $town    Town.
	 * @param string $region  Region.
	 * @param string $fias    FIAS.
	 * @return PickupPoint[]
	 */
	public function points( string $partner, string $town, string $region = '', string $fias = '' ): array {
		$body = $this->client->send(
			$this->builder->points( $this->client->token(), $partner, $town, $region, $fias ),
			array(
				'timeout'      => 30,
				'log_response' => false,
			)
		);
		return $this->parser->points( $body );
	}

	/**
	 * Delivery intervals.
	 *
	 * @param string $address Address.
	 * @param int    $service Service code.
	 * @param string $output  intervals|dates.
	 * @param string $date    Date or pickup date.
	 * @return array<string,array<int,array{min:string,max:string,type:string}>>
	 */
	public function intervals( string $address, int $service, string $output = 'dates', string $date = '' ): array {
		$body = $this->client->send(
			$this->builder->intervals( $this->client->token(), $address, $service, $output, $date ),
			array(
				'timeout' => 12,
				'retries' => 1,
			)
		);
		return $this->parser->intervals( $body );
	}

	/**
	 * Create an order and send it straight to pickup.
	 *
	 * @param OrderPayload $payload  Order.
	 * @param bool         $autosend Whether to skip the basket.
	 * @return array{barcode:string,errors:array<int,array{field:string,code:string,message:string}>}
	 */
	public function create_order( OrderPayload $payload, bool $autosend = true ): array {
		$body   = $this->client->send( $this->builder->basket_create( $this->client->token(), $payload, $autosend ), array( 'retries' => 0 ) );
		$result = $this->parser->basket_create( $body );
		if ( isset( $result[ $payload->number ] ) ) {
			return $result[ $payload->number ];
		}
		$first = reset( $result );
		return false !== $first ? $first : array(
			'barcode' => '',
			'errors'  => array(
				array(
					'field'   => 'response',
					'code'    => '',
					'message' => 'The response did not contain the created order.',
				),
			),
		);
	}

	/**
	 * Move orders from the basket to pickup.
	 *
	 * @param string[] $barcodes Barcodes.
	 * @param string[] $numbers  Order numbers.
	 * @return array<int,array{number:string,barcode:string,ok:bool,code:string,message:string}>
	 */
	public function send_basket( array $barcodes, array $numbers = array() ): array {
		$body = $this->client->send( $this->builder->by_orders( 'sendbasket', $this->client->token(), $barcodes, $numbers ), array( 'retries' => 0 ) );
		return $this->parser->send_basket( $body );
	}

	/**
	 * Cancel an order (own courier delivery only).
	 *
	 * @param string $barcode Barcode.
	 * @return array{ok:bool,code:string,message:string,ordercode:string}
	 */
	public function cancel_order( string $barcode ): array {
		$body = $this->client->send( $this->builder->by_orders( 'cancelorder', $this->client->token(), array( $barcode ) ), array( 'retries' => 0 ) );
		return $this->parser->cancel_order( $body );
	}

	/**
	 * Status of one order.
	 *
	 * @param string $orderno Barcode or order number.
	 * @return StatusInfo|null
	 */
	public function status( string $orderno ): ?StatusInfo {
		$body  = $this->client->send( $this->builder->status( $this->client->token(), $orderno ) );
		$items = $this->parser->statuses( $body );
		return $items ? $items[0] : null;
	}

	/**
	 * Orders changed since the last committed request (ONLY_LAST feed).
	 *
	 * @return StatusInfo[]
	 */
	public function changed_statuses(): array {
		$body = $this->client->send( $this->builder->status( $this->client->token(), '', true ), array( 'timeout' => 40 ) );
		return $this->parser->statuses( $body );
	}

	/**
	 * Acknowledge received changes.
	 *
	 * @return bool
	 */
	public function commit_statuses(): bool {
		$body = $this->client->send( $this->builder->simple( 'commitlaststatus', $this->client->token() ) );
		return $this->parser->commit( $body );
	}

	/**
	 * Stickers (labels) as a PDF.
	 *
	 * @param string[] $barcodes Barcodes.
	 * @param int      $format   Paper format 1-7.
	 * @return string PDF bytes.
	 */
	public function stickers( array $barcodes, int $format ): string {
		$body = $this->client->send(
			$this->builder->documents( 'stickers', $this->client->token(), $barcodes, $format ),
			array(
				'timeout'      => 40,
				'log_response' => false,
			)
		);
		return $this->parser->file( $body );
	}

	/**
	 * Transfer act (Акт ПП) as a PDF.
	 *
	 * @param string[] $barcodes Barcodes (empty = all sent today).
	 * @return string PDF bytes.
	 */
	public function act( array $barcodes ): string {
		$body = $this->client->send(
			$this->builder->documents( 'getact', $this->client->token(), $barcodes ),
			array(
				'timeout'      => 40,
				'log_response' => false,
			)
		);
		return $this->parser->file( $body );
	}
}
