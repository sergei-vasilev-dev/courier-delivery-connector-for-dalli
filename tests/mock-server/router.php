<?php
/**
 * A tiny stand-in for the Dalli API, used by the Docker smoke test. It mimics the documented request/response formats
 * (single POST endpoint, root element = method) and a few of the real validation rules (e.g. error 26), so the plugin
 * can be exercised end to end without a real token or network access.
 *
 * Start: php -S 0.0.0.0:8100 tests/mock-server/router.php
 *
 * Control endpoints (GET):
 *   /mock/requests            JSON list of the requests received (tokens redacted)
 *   /mock/state               JSON of stored orders
 *   /mock/advance?barcode=X&status=COMPLETE   set the delivery status of an order
 *   /mock/reset               wipe state
 *   /mock/fail?method=pointsInfo&times=2      make the next N calls of a method fail with HTTP 503
 *
 * @package DalliDelivery
 */

const MOCK_TOKEN = 'FAKE_TOKEN_FOR_MOCK';

/**
 * Load state.
 *
 * @return array
 */
function state_load() {
	$file = sys_get_temp_dir() . '/dalli-mock-state.json';
	if ( is_readable( $file ) ) {
		$data = json_decode( (string) file_get_contents( $file ), true );
		if ( is_array( $data ) ) {
			return $data + array( 'orders' => array(), 'requests' => array(), 'fail' => array(), 'changes' => array() );
		}
	}
	return array( 'orders' => array(), 'requests' => array(), 'fail' => array(), 'changes' => array() );
}

/**
 * Save state.
 *
 * @param array $state State.
 * @return void
 */
function state_save( array $state ) {
	file_put_contents( sys_get_temp_dir() . '/dalli-mock-state.json', json_encode( $state, JSON_UNESCAPED_UNICODE ) );
}

/**
 * Send XML.
 *
 * @param string $xml XML.
 * @return void
 */
function respond( $xml ) {
	header( 'Content-Type: text/xml; charset=utf-8' );
	echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . $xml;
	exit;
}

/**
 * Escape.
 *
 * @param string $value Value.
 * @return string
 */
function e( $value ) {
	return htmlspecialchars( (string) $value, ENT_QUOTES | ENT_XML1, 'UTF-8' );
}

$path = parse_url( $_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH );

// ---------------------------------------------------------------- control endpoints.
if ( 0 === strpos( $path, '/mock/' ) ) {
	$state = state_load();
	header( 'Content-Type: application/json' );
	switch ( $path ) {
		case '/mock/requests':
			echo json_encode( $state['requests'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );
			break;
		case '/mock/state':
			echo json_encode( $state['orders'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );
			break;
		case '/mock/reset':
			state_save( array( 'orders' => array(), 'requests' => array(), 'fail' => array(), 'changes' => array() ) );
			echo '{"ok":true}';
			break;
		case '/mock/advance':
			$barcode = $_GET['barcode'] ?? '';
			if ( isset( $state['orders'][ $barcode ] ) ) {
				$status = strtoupper( (string) ( $_GET['status'] ?? 'NEW' ) );
				$state['orders'][ $barcode ]['status']   = $status;
				$state['orders'][ $barcode ]['history'][] = array( 'status' => $status, 'time' => gmdate( 'Y-m-d H:i:s' ) );
				$state['changes'][ $barcode ]             = true;
				state_save( $state );
				echo '{"ok":true}';
			} else {
				http_response_code( 404 );
				echo '{"ok":false}';
			}
			break;
		case '/mock/fail':
			$state['fail'][ $_GET['method'] ?? '' ] = (int) ( $_GET['times'] ?? 1 );
			state_save( $state );
			echo '{"ok":true}';
			break;
		default:
			http_response_code( 404 );
	}
	exit;
}

if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
	http_response_code( 405 );
	echo 'POST XML to /v1/';
	exit;
}

// ---------------------------------------------------------------- API.
$body = file_get_contents( 'php://input' );
$doc  = new DOMDocument();
if ( ! @$doc->loadXML( $body, LIBXML_NONET ) || ! $doc->documentElement ) {
	respond( '<request error="400" errormsg="Malformed XML" />' );
}
$root   = $doc->documentElement;
$method = $root->nodeName;
$xp     = new DOMXPath( $doc );

$state = state_load();

// Never store the token in the request log.
$state['requests'][] = array(
	'method' => $method,
	'time'   => gmdate( 'c' ),
	'xml'    => preg_replace( '/token="[^"]*"/', 'token="[redacted]"', $body ),
);
state_save( $state );

$token = $xp->evaluate( 'string(/*/auth/@token)' );
if ( MOCK_TOKEN !== $token ) {
	respond( '<request error="401" errormsg="Ошибка авторизации (Неверный или пустой токен!)" />' );
}

if ( ! empty( $state['fail'][ $method ] ) ) {
	--$state['fail'][ $method ];
	state_save( $state );
	http_response_code( 503 );
	exit;
}

/**
 * Text of a child element.
 *
 * @param DOMXPath $xp   XPath.
 * @param string   $expr Expression.
 * @return string
 */
function val( DOMXPath $xp, $expr ) {
	return trim( $xp->evaluate( 'string(' . $expr . ')' ) );
}

switch ( strtolower( $method ) ) {
	case 'services':
		respond(
			'<services count="4"><service><code>1</code><name>Обычная МСК и МО</name></service><service><code>4</code><name>ПВЗ МСК</name></service>'
			. '<service><code>9</code><name>ПВЗ СДЭК</name></service><service><code>13</code><name>ПВЗ Яндекс</name></service></services>'
		);

	case 'deliverycost':
		$partner = val( $xp, '/deliverycost/partner' );
		$to      = val( $xp, '/deliverycost/to' );
		if ( '' === $to || '' === $partner ) {
			respond( '<deliverycost error="6" errormsg="Указаны не все обязательные параметры"></deliverycost>' );
		}
		if ( false !== mb_stripos( $to, 'Нетгород' ) ) {
			respond( '<deliverycost error="1" errormsg="Невозможно произвести расчет"></deliverycost>' );
		}
		$weight = (float) val( $xp, '/deliverycost/weight' );
		$extra  = (int) round( max( 0, $weight - 1 ) * 20 );
		$rows   = array(
			'DS'     => array(
				array( 1, 'KUR', 329 + $extra, 1, 'Доставка на следующий день' ),
				array( 2, 'KUR', 1061 + $extra, 0, 'Экспресс доставка день в день' ),
				array( 4, 'PVZ', 239 + $extra, 1, 'ПВЗ Dalli' ),
			),
			'CDEK'   => array(
				array( 9, 'PVZ', 199 + $extra, 3, 'ПВЗ СДЭК' ),
				array( 10, 'KUR', 399 + $extra, 2, 'Курьер СДЭК' ),
			),
			'YANDEX' => array( array( 13, 'PVZ', 149 + $extra, 3, 'ПВЗ Яндекс' ) ),
			'5POST'  => array( array( 20, 'PVZ', 99 + $extra, 4, '5POST постамат' ) ),
			'MAGNIT' => array( array( 33, 'PVZ', 129 + $extra, 4, 'ПВЗ Магнит' ) ),
		);
		if ( empty( $rows[ $partner ] ) ) {
			respond( '<deliverycost error="1" errormsg="Невозможно произвести расчет"></deliverycost>' );
		}
		$out = '<deliverycost partner="' . e( $partner ) . '" townto="' . e( $to ) . '" to_filial="1" days_id="0" zone="MSK">';
		foreach ( $rows[ $partner ] as $row ) {
			$prepaid = ( 'MAGNIT' === $partner ) ? ' onlyPrepaid="T"' : '';
			$out    .= '<price service="' . $row[0] . '" typedelivery="' . $row[1] . '" price="' . $row[2] . '" delivery_period="' . $row[3] . '" msg="' . e( $row[4] ) . '" zone="0" VATrate="22"' . $prepaid . ' />';
		}
		respond( $out . '</deliverycost>' );

	case 'pointsinfo':
		$partner = val( $xp, '/pointsInfo/partner' );
		$town    = val( $xp, '/pointsInfo/town' );
		if ( '' === $town ) {
			respond( '<pointsInfo count="0"></pointsInfo>' );
		}
		$names   = array( 'CDEK' => array( 'SDEK', 9 ), 'YANDEX' => array( 'BOXBERRY', 13 ), '5POST' => array( '5POST', 20 ), 'MAGNIT' => array( 'MAGNIT', 33 ), 'DS' => array( 'DS', 4 ) );
		$meta    = $names[ $partner ] ?? array( $partner, 4 );
		$out     = '';
		$count   = 0;
		for ( $i = 1; $i <= 5; $i++ ) {
			++$count;
			$out .= '<point code="' . e( strtolower( $partner ) . '-' . md5( $town ) . '-' . $i ) . '">'
				. '<name>' . e( $partner . ' ' . $town . ' пункт ' . $i ) . '</name>'
				. '<town>' . e( $town ) . '</town>'
				. '<address>' . e( $town . ', ул. Тестовая, д. ' . $i ) . '</address>'
				. '<description>' . e( 'Вход со двора, пункт №' . $i ) . '</description>'
				. '<onlyPrepaid>' . ( 5 === $i ? 1 : 0 ) . '</onlyPrepaid><acquiring>1</acquiring><cash>1</cash>'
				. '<workShedule>пн-вс: 09.00-21.00</workShedule><GPS>55.7' . $i . ',37.6' . $i . '</GPS><phone>+74951570020</phone>'
				. '<partner>' . e( $meta[0] ) . '</partner><service>' . $meta[1] . '</service><weightLimit>' . ( 4 === $i ? 1 : 30 ) . '</weightLimit>'
				. '<postamat>' . ( 3 === $i ? 1 : 0 ) . '</postamat></point>';
		}
		respond( '<pointsInfo count="' . $count . '">' . $out . '</pointsInfo>' );

	case 'intervals':
		$out = '<dates count="3">';
		for ( $d = 1; $d <= 3; $d++ ) {
			$out .= '<date value="' . gmdate( 'Y-m-d', time() + $d * 86400 ) . '"><intervals count="2">'
				. '<interval type="basic" zone="0" service="1"><time_min>09:00</time_min><time_max>13:00</time_max></interval>'
				. '<interval type="basic" zone="0" service="1"><time_min>14:00</time_min><time_max>18:00</time_max></interval>'
				. '</intervals></date>';
		}
		respond( $out . '</dates>' );

	case 'basketcreate':
		$result = '';
		foreach ( $xp->query( '/basketcreate/order' ) as $order ) {
			$number = $order->getAttribute( 'number' );
			$errors = array();
			$o      = new DOMXPath( $doc );
			$val    = function ( $expr ) use ( $o, $order ) {
				return trim( $o->evaluate( 'string(' . $expr . ')', $order ) );
			};

			if ( ! preg_match( '/^[A-Za-z0-9А-Яа-я_-]{2,50}$/u', $number ) ) {
				$errors[] = array( 'number', 3, 'В номере заказа присутствуют запрещенные символы' );
			}
			foreach ( $state['orders'] as $existing ) {
				if ( $existing['number'] === $number ) {
					$errors[] = array( 'number', 4, 'Указанный номер заказа уже есть в системе' );
				}
			}
			$service = (int) $val( 'service' );
			$paytype = $val( 'paytype' );
			$price   = (float) $val( 'price' );
			$priced  = (float) $val( 'priced' );
			$insh    = (float) $val( 'inshprice' );
			if ( strlen( preg_replace( '/\D/', '', $val( 'receiver/phone' ) ) ) < 10 ) {
				$errors[] = array( 'phone', 12, 'Некорректно указан телефон получателя' );
			}
			if ( mb_strlen( $val( 'receiver/person' ) ) < 2 ) {
				$errors[] = array( 'person', 11, 'Поле получатель слишком короткое' );
			}
			if ( ! preg_match( '/^\d{2}:\d{2}$/', $val( 'receiver/time_min' ) ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $val( 'receiver/date' ) ) ) {
				$errors[] = array( 'interval', 14, 'Некорректно указан временной интервал доставки' );
			}
			$pvz_service = in_array( $service, array( 4, 9, 12, 13, 20, 23, 33 ), true );
			if ( $pvz_service && '' === $val( 'receiver/pvzcode' ) ) {
				$errors[] = array( 'pvzcode', 28, 'Некорректный код ПВЗ' );
			}
			if ( ! $pvz_service && '' === $val( 'receiver/address' ) ) {
				$errors[] = array( 'address', 10, 'Поле адрес не может быть пустым' );
			}
			$items_total = 0.0;
			foreach ( $o->query( 'items/item', $order ) as $item ) {
				$retprice = (float) $item->getAttribute( 'retprice' );
				if ( $retprice <= 0 ) {
					$errors[] = array( 'items', 49, 'Бесплатные товарные позиции запрещены' );
				}
				$items_total += $retprice * (int) $item->getAttribute( 'quantity' );
			}
			if ( 'NO' !== $paytype && abs( $price - ( $items_total + $priced ) ) > 0.001 ) {
				$errors[] = array( 'items', 26, 'Наложенный платеж не совпадает со стоимостью товарных позиций + стоимость доставки' );
			}
			if ( $insh <= 0 ) {
				$errors[] = array( 'inshprice', 32, 'Сумма объявленной ценности должна быть больше нуля' );
			}
			if ( $price > $insh ) {
				$errors[] = array( 'inshprice', 31, 'Сумма наложенного платежа не может быть больше объявленной ценности' );
			}
			if ( 'CARD' === $paytype && ! in_array( $service, array( 1, 11, 22 ), true ) ) {
				$errors[] = array( 'paytype', 20, 'Оплата картой недоступна при доставке через партнеров' );
			}

			$result .= '<order number="' . e( $number ) . '">';
			if ( $errors ) {
				foreach ( $errors as $error ) {
					$result .= '<error error="' . e( $error[0] ) . '" errorCode="' . $error[1] . '" errorMessage="' . e( $error[2] ) . '" />';
				}
			} else {
				$barcode                     = 'A' . ( 1000000 + count( $state['orders'] ) + 1 );
				$state['orders'][ $barcode ] = array(
					'number'  => $number,
					'barcode' => $barcode,
					'service' => $service,
					'paytype' => $paytype,
					'price'   => $price,
					'priced'  => $priced,
					'insh'    => $insh,
					'pvz'     => $val( 'receiver/pvzcode' ),
					'address' => $val( 'receiver/address' ),
					'date'    => $val( 'receiver/date' ),
					'time'    => $val( 'receiver/time_min' ) . '-' . $val( 'receiver/time_max' ),
					'phone'   => $val( 'receiver/phone' ),
					'items'   => $o->query( 'items/item', $order )->length,
					'status'  => 'NEW',
					'history' => array( array( 'status' => 'NEW', 'time' => gmdate( 'Y-m-d H:i:s' ) ) ),
					'cancel'  => false,
				);
				$state['changes'][ $barcode ] = true;
				$result .= '<success barcode="' . $barcode . '" />';
			}
			$result .= '</order>';
		}
		state_save( $state );
		respond( '<basketcreate>' . $result . '</basketcreate>' );

	case 'statusreq':
		$orderno = val( $xp, '/statusreq/orderno' );
		$only    = 'ONLY_LAST' === val( $xp, '/statusreq/changes' );
		$out     = '';
		foreach ( $state['orders'] as $barcode => $order ) {
			if ( $only ? empty( $state['changes'][ $barcode ] ) : ( $orderno !== $barcode && $orderno !== $order['number'] ) ) {
				continue;
			}
			$hist = '';
			foreach ( array_reverse( $order['history'] ) as $h ) {
				$hist .= '<status store="1" eventstore="Офис в Москве" eventtime="' . e( $h['time'] ) . '" createtimegmt="' . e( $h['time'] ) . '" title="' . e( $h['status'] ) . '">' . e( $h['status'] ) . '</status>';
			}
			$last = end( $order['history'] );
			$out .= '<order orderno="' . e( $order['number'] ) . '" ordercode="1" givencode="' . e( $barcode ) . '" awb="' . e( $barcode ) . '"><barcode>' . e( $barcode ) . '</barcode>'
				. '<courier><code>1</code><phone>+7 999 000 00 00</phone><name>Курьер Тестов</name></courier>'
				. '<status store="1" eventstore="Офис в Москве" eventtime="' . e( $last['time'] ) . '" createtimegmt="' . e( $last['time'] ) . '" title="' . e( $order['status'] ) . '">' . e( $order['status'] ) . '</status>'
				. '<statushistory>' . $hist . '</statushistory></order>';
		}
		respond( '<statusreq>' . $out . '</statusreq>' );

	case 'commitlaststatus':
		$state['changes'] = array();
		state_save( $state );
		respond( '<commitlaststatus error="0">OK </commitlaststatus>' );

	case 'cancelorder':
		$barcode = val( $xp, '/cancelorder/barcode' );
		if ( isset( $state['orders'][ $barcode ] ) && ! in_array( $state['orders'][ $barcode ]['status'], array( 'CANCELED', 'COMPLETE', 'DELIVERY' ), true ) && in_array( $state['orders'][ $barcode ]['service'], array( 1, 11, 22 ), true ) ) {
			$state['orders'][ $barcode ]['cancel'] = true;
			state_save( $state );
			respond( '<cancelorder><response error="0" barcode="' . e( $barcode ) . '" ordercode="1" message="Заявка отменена" /></cancelorder>' );
		}
		respond( '<cancelorder><response error="1" barcode="' . e( $barcode ) . '" message="Заявка не найдена или её уже нельзя отменить" /></cancelorder>' );

	case 'stickers':
	case 'getact':
		$pdf = "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 200 200]>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF";
		respond( '<' . strtolower( $method ) . '><base64>' . base64_encode( $pdf ) . '</base64></' . strtolower( $method ) . '>' );

	default:
		respond( '<request error="404" errormsg="Unknown method ' . e( $method ) . '" />' );
}
