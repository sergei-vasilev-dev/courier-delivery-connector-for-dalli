# Dalli API notes

Findings from reading the official documentation (<https://api.dalli-service.com/doc/v1/>, read November 2026) and
from comparing with the two unofficial PHP SDKs (read for hints only, no code was copied). Everything here is what
the **plugin relies on**. Items marked **UNVERIFIED** could not be confirmed without a real token and should be
re-checked against the Dalli test platform before a production roll-out.

## Transport

| Topic | Finding |
| --- | --- |
| Endpoint | A **single URL per branch**. Every method is an HTTP `POST` of an XML document to the base URL; the **root element name selects the method** (`<services>`, `<deliverycost>`, `<basketcreate>` ...). The docs never state this explicitly, it is visible in the "XML debugger" page (read-only field `https://api.dalli-service.com/v1/`) and in both SDKs. |
| Moscow branch | `https://api.dalli-service.com/v1/` (personal account `https://lk.dalli-service.com/`) |
| St. Petersburg branch | `https://spbapi.dalli-service.com/v1/` (personal account `https://spblk.dalli-service.com/`) |
| Encoding | UTF-8, decimal separator `.`, dates `YYYY-MM-DD`, times `HH:MM`. `& < > "` must be escaped in text. The plugin builds every request with DOM (`XmlBuilder`) so escaping is automatic and control characters are stripped. |
| Auth | `<auth token="..."/>` as a child of the root element of **every** request. Tokens are issued per customer account in the personal account (section "Учётная запись"). |
| Auth failure | The generic envelope `<request error="401" errormsg="Ошибка авторизации (Неверный или пустой токен!)"/>` with **HTTP 200** (verified with an invalid dummy token). The parser maps `error=401/403` to `ApiException::TYPE_AUTH`. |
| Rate limit | `errorCode 998`: "no more than one request per second; currently not enabled, may be enabled under load". The client keeps retries to a minimum (rates 1 retry, order creation 0). |
| Request log | Dalli keeps an "API journal" (`https://lk.dalli-service.com/integration/`) that shows requests/responses - the best tool to debug a first integration. |
| Support | `it@dalli-service.com` (name, client code, company). |

### Test platform

The documentation describes a **shared** test client (`login: dsdalli`, password published in the docs). It is visible to all
users, so never send real personal data. Log into the test personal account to obtain a test token and paste it into
**Test API token** in the plugin (mode "Test platform"). The plugin does **not** depend on it for its own tests: the
automated tests use fixtures and a local mock server (`tests/mock-server`). `pointsInfo` is limited to 100 rows for the test client.

## Methods used by the plugin

| Method (root element) | Used for | Notes |
| --- | --- | --- |
| `services` | Connection test | Returns the delivery types (`service` codes) of the contract; cheap and side-effect free. |
| `deliverycost` | Live rates | See below. |
| `pointsInfo` | Pickup point lists | See below. |
| `intervals` | Delivery date/interval selector | `output=dates` returns dates plus intervals; `format=minutes` returns `HH:MM`. Valid for Dalli own courier services 1, 11, 22. |
| `basketcreate` | Create the order | With root attribute `autosend="T"` the order skips the basket and goes straight to pickup (same as `sendbasket`). |
| `sendbasket` | (client implemented, not used by default) | Success is `errorCode="0"` inside an `<error>` element. |
| `cancelorder` | Cancel | Root element is **lower-case** `cancelorder` (not `cancelOrder` as the page title suggests). Only Dalli own courier services 1, 11, 22; only before the courier takes the order. |
| `statusreq` | Status | By order number or barcode. `<changes>ONLY_LAST</changes>` returns all orders changed since the last `commitlaststatus` (account-wide feed). |
| `commitlaststatus` | Acknowledge the changes feed | Responds `<commitlaststatus error="0">OK </commitlaststatus>`. |
| `stickers` | Labels | `returnas=base64` wraps the PDF in `<stickers><base64>..</base64></stickers>`; default is a raw PDF stream. Formats 1-7 (1 Zebra, 2 A4 framed, 3 A4, 4 A6, 5 100x50 mm, 6 105x148 mm, 7 75x120 mm). **Only orders sent today are included.** |
| `getact` | Transfer act (Акт ПП) | Same `returnas` options. Only orders sent today. |

Not used in v1 (documented for the next versions): `editbasket`, `getbasket`, `removebasket`, `getPickupAddresses`,
`pickupcreate`, `filials`, `getPolygons`, `schedules`, `rupostcreate` (Russian Post has its own creation method and requires `output=x2`).

## `deliverycost`

* Required: `partner` (`DS`, `YANDEX`, `CDEK`, `5POST`, `RUPOST`, `MAGNIT`), the destination (`to` full address is recommended;
  alternatives `fias`, `kladr`, `pvzcode`, `townto` + `oblname`, `cdekcityid`, `boxberrycityid`) and **either** `typedelivery`
  (`KUR`|`PVZ`) **or** `<output>x2</output>`.
* The plugin always sends `output=x2` (one request per partner returns every option of that partner: e.g. for `DS`
  service 1 courier, 2 express, 4 pickup point). `<weight>` (kg), `<length>`, `<width>`, `<height>` (cm), `<inshprice>`
  (declared value) and `<sendercode>` are sent when known. `<price>` (cash on delivery) is **not** sent because the payment
  method is not known at cart time.
* Multi-place `<packages>` is supported only for Dalli itself; the plugin always prices one parcel.
* Standard response: one `<deliverycost partner townto to_filial days_id service typedelivery price delivery_period msg zone VATrate [polygon_id] [onlyPrepaid]/>`.
  x2 response: `<deliverycost partner ...><price service typedelivery price delivery_period msg zone VATrate [onlyPrepaid]/>...</deliverycost>`.
  Both are handled by `XmlParser::delivery_cost()`.
* Errors are returned as attributes of the root: `error` and `errormsg`. Documented codes: `1` cannot calculate, `4` no pickup points in the city,
  `6` not all required parameters, `12` multi-place not supported via partners. They are treated as "no rates for this partner" (cached briefly), not as failures.
* `onlyPrepaid="T"` marks rates that can only be used for prepaid orders. The plugin stores the flag in the rate meta and refuses a cash-on-delivery checkout for such a rate.
* `delivery_period` is a number of days in all examples (`0` = same day). The plugin formats numbers as "N days" and prints other values as "X days" verbatim.
* Doc inconsistencies: in the `packages` attribute list "height" and "width" descriptions look swapped (typo); `boxberrycityid` is described as the YANDEX city code (the old Boxberry brand became Yandex Delivery).

## `pointsInfo`

* Request filters: `town`, `settlement` (region), `fias` (recommended), `zipcode`, `partner` (`YANDEX`, `CDEK`, `5POST`, `MAGNIT`, `DS`), `pvzcode`, `acquiring`, `cash`.
  The plugin queries by **town text** (+ region when it is Russian) because the WooCommerce checkout has no FIAS code. **UNVERIFIED:** how forgiving the
  vendor's town matching is (e.g. "Москва" vs "г Москва"); it is isolated in `PvzService::for_town()`.
* No filter returns **all** points, more than 10 MB of XML. The vendor asks to fetch lists at most once a day. The plugin never requests the full list; it caches
  per partner+town for 12 hours (`dalli_delivery_pvz_ttl` filter).
* Quirk: the request partner is `CDEK` but responses carry `SDEK` (and `BOXBERRY` for Yandex). `Catalog::normalize_partner()` maps them. The docs
  also contain a Cyrillic "С" in one `СDEK` enumeration - the plugin sends Latin `CDEK` (as in `deliverycost`).
* Response fields used: `code` (attribute, passed to `<pvzcode>`), `name`, `address` (falls back to `addressReduce`), `town`, `description`, `workShedule` (sic),
  `GPS` (`lat,lng`), `phone`, `service`, `weightLimit`, `onlyPrepaid`, `acquiring`, `cash`, `postamat`. "Depending on the partner some fields may be unavailable."
* Points whose `weightLimit` is lower than the cart weight are hidden; points that accept prepaid orders only block cash-on-delivery checkouts.

## `basketcreate`

* One `<order number="...">` per order. `number`: 2-50 characters of Latin/Cyrillic letters, digits, `_`, `-` (`Format::order_number()`; optional
  prefix setting; must be unique per Dalli account, error `4`/`33` otherwise).
* Mandatory children: `receiver{address|pvzcode|fias, person, phone, date, time_min, time_max}`, `service`, `quantity` (number of **places**, not items), `items/item`.
  `phone` may contain the e-mail after a comma. `date` is mandatory but ignored for partner deliveries; the plugin sends tomorrow with the default interval.
* `paytype`: `CASH` (default), `CARD` (own couriers only, error `20` otherwise), `NO` (prepaid: `price` is zeroed).
* **Money rule (error `26`):** `price` (cash on delivery) must equal `sum(item.retprice * item.quantity) + delivery`. The plugin converts every line
  into a unit price from the final line total (after discounts, incl. tax) and splits a line in two when the total does not divide evenly
  (`ItemPlanner`); shipping goes to `priced` for cash-on-delivery orders and is `0` for prepaid ones. Free items are forbidden (error `49`) and are dropped.
  `inshprice` (declared value) must be > 0 (error `32`) and >= `price` (error `31`) - enforced by `AmountPlanner`.
  If the Dalli account has delivery conditions configured in the personal account, `priced` is ignored by Dalli.
* Item `VATrate`: integer percent; Dalli's default is 22; `0` means "no VAT" (a 0 % rate is not supported). Configurable in the settings.
* Response per order: `<success barcode="A1234567"/>` or one/many `<error error="<field>" errorCode="<n>" errorMessage="..."/>`. The full code list (about 90 codes) is
  documented at `/doc/v1/errorCodes`; the plugin shows `[code] message` in the order note and the order's "last error". Critical codes: 995-999
  (`997` ordering blocked for unpaid invoices, `998` rate limit, `999` generic).
* With `autosend="T"` no basket step is needed. **UNVERIFIED:** the initial status immediately after `autosend` (the docs say basket orders are invisible
  to `statusreq` until sent); the plugin schedules a first status check two minutes after creation.
* Duplicate protection: order creation is never retried at HTTP level. If a response is lost and the order exists, the second attempt gets error `4`/`33` and the plugin
  recovers the barcode with `statusreq` by order number.

## Statuses (`statusreq`)

30 documented codes (`Catalog::statuses()`): `AWAITING_SYNC, NEW, NEWPICKUP, PICKUP, WMSASSEMBLED, WMSDISASSEMBLED, ACCEPTED, CUSTOMSPROCESS, CUSTOMSFINISHED, CONFIRM, UNCONFIRM,
DEPARTURING, DEPARTURE, INVENTORY, PICKUPREADY, DELIVERY, COURIERDELIVERED, COURIERPARTIALLY, COURIERCANCELED, COURIERRETURN, DATECHANGE, COMPLETE, PARTIALLY, CANCELED, RETURNING,
RETURNED, PARTLYRETURNING, PARTLYRETURNED, TRANSACCEPTED, PICKUPTRANS`. The text node of `<status>` is the code; attributes `title` (Russian), `eventtime` (branch time zone), `createtimegmt`, `eventstore`.

* The vendor warns that statuses are **not guaranteed to be sequential** (an operator can correct `COMPLETE` back to `NEW`). The plugin therefore stores the
  latest status as sent and never infers order from the code.
* `CANCELED` is an intermediate status (a return follows, or re-delivery), so it is **not** final. Final (polling stops): `COMPLETE`, `PARTIALLY`, `RETURNED`, `PARTLYRETURNED`.
* Two sync strategies: per order (default, one `statusreq` per open order via Action Scheduler) or the `ONLY_LAST` changes feed. The feed is
  **account-wide state**: whoever calls `commitlaststatus` consumes the events for everyone, so it is opt-in.

## Stickers and acts

Both accept `<barcodes>`; with none, all orders sent **today** are included; other days' barcodes are silently ignored (the plugin tells the user).
`returnas=base64` is always requested so the response is XML-wrapped and errors stay detectable; a raw `%PDF` body is accepted as well.

## Service codes (`services`)

The list depends on the contract. Observed in the docs sample: `1` Обычная МСК и МО, `2` Экспресс МСК, `3` Срочная МСК, `4` ПВЗ МСК, `9` ПВЗ СДЭК, `10` Курьерская СДЭК,
`11` Обычная СПБ и ЛО, `12` ПВЗ СПБ, `13` ПВЗ Яндекс, `19` Почта России, `20` 5POST, `22` Курьерская Регионы, `23` ПВЗ Регионы, `25` Внутригородская Регионы,
`26` Курьерская Яндекс, `30` Доставка КГТ, `31` Внутрирегиональная доставка, `32` Доставка КГТ Регион, `33` ПВЗ Магнит (plus service/pickup/return codes 5, 7, 8, 16, 24, 28, 29).
The plugin takes the service code **from the rate response** and keeps `Catalog::fallback_service()` only for rates that lack one.

## Design decisions and known limitations

* One parcel per order (`quantity=1`); the parcel is derived from product weight/dimensions with a documented volume-stacking heuristic (`PackageBuilder`).
* Cart-time rates do not know the payment method, therefore COD commissions (if any) are not part of the displayed price.
* `RUPOST` and the marketplace/KGT (`30`, `32`) services are out of scope for v1.
* Pickup point maps are intentionally not included (no third-party tile servers, no tracking). The picker is an accessible searchable list.
* Behaviour against the live API (town matching in `pointsInfo`, exact `autosend` initial status, `cancelorder` response in edge cases) is **UNVERIFIED** because no real token was available.
