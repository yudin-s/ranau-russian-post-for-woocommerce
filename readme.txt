=== Ranau Russian Post for WooCommerce ===
Contributors: yudins
Tags: woocommerce, shipping, russian post, pickup, delivery
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.1.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Calculate Russian Post rates and let customers choose an office or confirmed address without creating shipments.

== Description ==

Ranau Russian Post for WooCommerce is an independent shipping method for stores that fulfil orders in another system.

Features:

* Russian Post office search and selection.
* Address confirmation with optional Yandex Geocoder integration.
* Domestic and international tariff calculation.
* Checkout Blocks, classic checkout, and HPOS support.
* Provider-local state machine with server-owned selection and quote commits.
* No shipment, label, pickup request, tracking, or fulfilment API calls.
* No Ranau account, license key, tracking, or remote Ranau service.

The plugin sends parcel parameters and destination data to Russian Post tariff and directory endpoints. Office proximity search uses OpenStreetMap Overpass. Address confirmation and the optional map contact Yandex only when the store configures a Yandex API key and the customer uses address delivery.

== Installation ==

1. Install and activate WooCommerce.
2. Upload and activate the plugin ZIP.
3. Set the WooCommerce store postcode; it is used as the origin postcode.
4. Add “Ranau Russian Post” to the required WooCommerce shipping zones.
5. Optionally provide a Yandex Geocoder key with the `ranau_russian_post_yandex_geocoder_api_key` filter.
6. Test cart dimensions, tariff availability, office selection, address confirmation, and every payment method before launch.

== Frequently Asked Questions ==

= Does this plugin create Russian Post shipments? =

No. It calculates delivery and stores the confirmed selection on the WooCommerce order. Fulfilment remains external.

= Is a Ranau service required? =

No. The plugin is self-contained and makes no requests to Ranau.

== External services ==

The plugin uses the public Russian Post tariff and directory services at `tariff.pochta.ru`. Rate requests can contain origin and destination postcodes or destination country, postal object type, aggregate parcel weight, declared value, and standard package code. Office lookup sends one or more postcodes. The plugin never creates or tracks a postal shipment.

Nearby office discovery uses the community Overpass API at `overpass-api.de`. To reduce precision, coordinates are rounded to two decimal places before the request. Every candidate postcode is then revalidated with the official Russian Post directory.

When a store owner configures a Yandex Geocoder key, address delivery uses Yandex Maps and Geocoder. Requests can contain the configured key, a customer-entered address or map coordinates, IP address, and device information.

Russian Post tariff API documentation: https://tariff.pochta.ru/post-calculator-api.pdf
OpenStreetMap copyright and contributor information: https://www.openstreetmap.org/copyright
Overpass API: https://wiki.openstreetmap.org/wiki/Overpass_API
Yandex Maps API terms: https://yandex.ru/legal/maps_api/ru/
Yandex privacy policy: https://yandex.ru/legal/confidential/

== Privacy ==

For calculation and selection, the plugin may send the destination postcode, country, parcel weight/value, office coordinates, or a customer-entered delivery address to the carrier and map providers described above. The plugin does not send checkout data to Ranau and adds no analytics or tracking.

== Support ==

Community issues: https://github.com/yudin-s/ranau-russian-post-for-woocommerce/issues

Optional paid support and custom WooCommerce development are available from Ranau at https://ranau.uk/ and are not required to use the plugin.

== Changelog ==

= 0.1.1 =

* Corrected the WordPress.org contributor account.

= 0.1.0 =

* Initial independent open-source release candidate.
