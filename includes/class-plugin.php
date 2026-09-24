<?php

declare(strict_types=1);

namespace Ranau\RussianPost;

use Automattic\WooCommerce\StoreApi\Exceptions\RouteException;
use DomainException;
use Ranau\RussianPost\Internal\DeliveryState\ProviderStateStore;

defined('ABSPATH') || exit;

final class Plugin
{
    private const METHODS = array(
        'ranau_russian_post' => TariffClient::OBJECT_DOMESTIC_OPS,
        'ranau_russian_post_world' => TariffClient::OBJECT_INTERNATIONAL_PARCEL,
    );
    private const DOMESTIC_METHOD = 'ranau_russian_post';
    private const MODE_OPS = 'ops';
    private const MODE_ADDRESS = 'address';
    private const STORE_API_NAMESPACE = 'ranau-russian-post-for-woocommerce';

    private static ?Plugin $instance = null;
    private bool $store_api_registered = false;
    private bool $store_update_registered = false;

    public static function instance(): Plugin
    {
        if (!self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function init(): void
    {
        add_action('woocommerce_shipping_init', array($this, 'load_shipping_methods'));
        add_filter('woocommerce_shipping_methods', array($this, 'register_shipping_methods'));
        add_action('rest_api_init', array($this, 'register_rest_routes'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_assets'));
        add_action('woocommerce_after_shipping_rate', array($this, 'render_classic_selector'), 10, 2);
        add_action('woocommerce_after_checkout_validation', array($this, 'validate_classic_checkout'), 15, 2);
        add_action('woocommerce_checkout_create_order', array($this, 'save_classic_order'), 15, 2);
        add_action('woocommerce_blocks_loaded', array($this, 'register_store_api'));
        add_action('woocommerce_blocks_loaded', array($this, 'register_store_api_update'));
        add_action('woocommerce_store_api_checkout_update_order_from_request', array($this, 'validate_blocks_checkout'), 15, 2);
        add_action('woocommerce_store_api_checkout_update_order_from_request', array($this, 'save_blocks_order'), 20, 2);
        add_action('woocommerce_checkout_order_processed', array($this, 'clear_processed_order_session'), 20, 3);
        add_action('woocommerce_store_api_checkout_order_processed', array($this, 'clear_processed_store_api_order_session'), 20, 1);

        if (function_exists('woocommerce_store_api_register_endpoint_data')) {
            $this->register_store_api();
        }
        if (function_exists('woocommerce_store_api_register_update_callback')) {
            $this->register_store_api_update();
        }
    }

    public function load_shipping_methods(): void
    {
        require_once RANAU_RUSSIAN_POST_DIR . 'includes/class-shipping-method.php';
    }

    public function register_shipping_methods(array $methods): array
    {
        $methods['ranau_russian_post'] = OpsShippingMethod::class;
        $methods['ranau_russian_post_ops'] = LegacyOpsShippingMethod::class;
        $methods['ranau_russian_post_ems'] = EmsShippingMethod::class;
        $methods['ranau_russian_post_world'] = WorldShippingMethod::class;
        return $methods;
    }

    public function register_rest_routes(): void
    {
        register_rest_route('ranau-russian-post-for-woocommerce/v1', '/points', array(
            'methods' => \WP_REST_Server::READABLE,
            'callback' => array($this, 'rest_points'),
            'permission_callback' => array($this, 'check_nonce'),
        ));
        register_rest_route('ranau-russian-post-for-woocommerce/v1', '/selection', array(
            'methods' => \WP_REST_Server::CREATABLE,
            'callback' => array($this, 'rest_selection'),
            'permission_callback' => array($this, 'check_nonce'),
        ));
        register_rest_route('ranau-russian-post-for-woocommerce/v1', '/address', array(
            'methods' => \WP_REST_Server::CREATABLE,
            'callback' => array($this, 'rest_address'),
            'permission_callback' => array($this, 'check_nonce'),
        ));
        register_rest_route('ranau-russian-post-for-woocommerce/v1', '/commit', array(
            'methods' => \WP_REST_Server::CREATABLE,
            'callback' => array($this, 'rest_commit'),
            'permission_callback' => array($this, 'check_nonce'),
        ));
    }

    public function check_nonce(\WP_REST_Request $request): bool
    {
        $nonce = (string) $request->get_header('x_wp_nonce');
        return $nonce !== '' && wp_verify_nonce($nonce, 'wp_rest');
    }

    public function rest_points(\WP_REST_Request $request): \WP_REST_Response
    {
        $query = array(
            'postcode' => sanitize_text_field((string) $request->get_param('postcode')),
            'lat' => $request->get_param('lat'),
            'lon' => $request->get_param('lon'),
        );
        return rest_ensure_response(array('points' => (new OfficeClient())->search($query)));
    }

    public function rest_selection(\WP_REST_Request $request): \WP_REST_Response
    {
        $this->ensure_cart_loaded();
        $data = $this->normalize_array($request->get_json_params());
        $method = self::DOMESTIC_METHOD;
        if (!empty($data['clear'])) {
            $this->invalidate_provider_state($method);
            $this->set_session($method . '_selection', array());
            $this->set_session($method . '_quote', array());
            $this->clear_shipping_cache();
            return rest_ensure_response(array('selection' => array(), 'quote' => array()));
        }

        $postcode = preg_replace('/\D+/', '', (string) ($data['postcode'] ?? ''));
        $office = (new OfficeClient())->by_postcode($postcode);
        if (empty($office['ok'])) {
            return new \WP_REST_Response($office, 400);
        }

        $package = (new PackageResolver())->resolve_cart();
        $quote = (new TariffClient())->quote($package, $postcode, self::METHODS[$method]);
        if (empty($quote['ok'])) {
            return new \WP_REST_Response($quote, 400);
        }

        $selection = array_merge($office['office'], array('committed' => false, 'provider' => 'russian_post', 'method' => $method, 'delivery_mode' => self::MODE_OPS));
        $commit = $this->begin_provider_state($method, $selection, $quote);
        $this->set_session($method . '_selection', $selection);
        $this->set_session($method . '_quote', $quote);
        $this->clear_shipping_cache();
        $this->persist_session();

        return rest_ensure_response(array('selection' => $selection, 'quote' => $quote, 'commit' => $commit));
    }

    public function rest_address(\WP_REST_Request $request): \WP_REST_Response
    {
        $this->ensure_cart_loaded();
        $data = $this->normalize_array($request->get_json_params());
        $method = self::DOMESTIC_METHOD;
        if (!empty($data['clear'])) {
            $this->invalidate_provider_state($method);
            $this->set_session($method . '_selection', array());
            $this->set_session($method . '_quote', array());
            $this->clear_shipping_cache();
            $this->persist_session();
            return rest_ensure_response(array('selection' => array(), 'quote' => array()));
        }

        $lat = isset($data['lat']) && is_numeric($data['lat']) ? (float) $data['lat'] : null;
        $lon = isset($data['lon']) && is_numeric($data['lon']) ? (float) $data['lon'] : null;
        $confirmed = (new AddressClient())->confirm((string) ($data['address'] ?? ''), $lat, $lon);
        if (empty($confirmed['ok'])) {
            return new \WP_REST_Response($confirmed, 400);
        }

        $selection = array_merge((array) $confirmed['address'], array('committed' => false, 'provider' => 'russian_post', 'method' => $method, 'delivery_mode' => self::MODE_ADDRESS));
        $quote = (new TariffClient())->quote((new PackageResolver())->resolve_cart(), (string) $selection['postcode'], TariffClient::OBJECT_DOMESTIC_EMS);
        if (empty($quote['ok'])) {
            return new \WP_REST_Response($quote, 400);
        }

        $commit = $this->begin_provider_state($method, $selection, $quote);
        $this->set_session($method . '_selection', $selection);
        $this->set_session($method . '_quote', $quote);
        $this->clear_shipping_cache();
        $this->persist_session();

        return rest_ensure_response(array('selection' => $selection, 'quote' => $quote, 'commit' => $commit));
    }

    public function rest_commit(\WP_REST_Request $request): \WP_REST_Response
    {
        $this->ensure_cart_loaded();

        try {
            $committed = $this->commit_provider_state($this->normalize_array($request->get_json_params()));
        } catch (DomainException $exception) {
            return new \WP_REST_Response(array(
                'code' => sanitize_key($exception->getMessage()),
                'message' => __('Выбор доставки устарел. Рассчитайте доставку ещё раз.', 'ranau-russian-post-for-woocommerce'),
            ), 409);
        }

        return rest_ensure_response(array('committed' => $committed));
    }

    public function enqueue_assets(): void
    {
        if ((!function_exists('is_checkout') || !is_checkout()) && (!function_exists('is_cart') || !is_cart())) {
            return;
        }
        wp_enqueue_style('ranau-russian-post-for-woocommerce', RANAU_RUSSIAN_POST_URL . 'assets/css/russian-post.css', array(), $this->asset_version('assets/css/russian-post.css'));
        wp_enqueue_script('ranau-russian-post-delivery-runtime', RANAU_RUSSIAN_POST_URL . 'assets/js/delivery-runtime.js', array(), $this->asset_version('assets/js/delivery-runtime.js'), true);
        wp_enqueue_script('ranau-russian-post-for-woocommerce', RANAU_RUSSIAN_POST_URL . 'assets/js/russian-post.js', array('ranau-russian-post-delivery-runtime', 'wc-blocks-checkout'), $this->asset_version('assets/js/russian-post.js'), true);
        wp_localize_script('ranau-russian-post-for-woocommerce', 'RanauRussianPost', array(
            'restUrl' => esc_url_raw(rest_url('ranau-russian-post-for-woocommerce/v1')),
            'restNonce' => wp_create_nonce('wp_rest'),
            'storeApiNamespace' => self::STORE_API_NAMESPACE,
            'methods' => array_keys(self::METHODS),
            'originPostcode' => TariffClient::origin_postcode(),
            'selection' => $this->session_array('ranau_russian_post_selection'),
            'quote' => $this->session_array('ranau_russian_post_quote'),
            'yandexMapApiKey' => $this->yandex_map_api_key(),
            'packageFingerprint' => $this->package_fingerprint(),
        ));
    }

    public function render_classic_selector($rate, int $index): void
    {
        if (!is_object($rate) || !method_exists($rate, 'get_method_id')) {
            return;
        }
        $method = $rate->get_method_id();
        if ($method !== self::DOMESTIC_METHOD) {
            return;
        }
        echo '<div class="ranau-russian-post-for-woocommerce-selector ranau-russian-post-for-woocommerce-unified-selector" data-ranau-russian-post-for-woocommerce-classic data-ranau-russian-post-for-woocommerce-method="' . esc_attr($method) . '" data-ranau-delivery-method="' . esc_attr($method) . '" data-ranau-delivery-complete="0"></div>';
    }

    public function register_store_api(): void
    {
        if ($this->store_api_registered || !function_exists('woocommerce_store_api_register_endpoint_data')) {
            return;
        }
        woocommerce_store_api_register_endpoint_data(array(
            'endpoint' => 'checkout',
            'namespace' => self::STORE_API_NAMESPACE,
            'schema_callback' => static function (): array {
                return array(
                    'method' => array('type' => 'string', 'context' => array('view', 'edit'), 'required' => false),
                    'postcode' => array('type' => 'string', 'context' => array('view', 'edit'), 'required' => false),
                    'point_id' => array('type' => 'string', 'context' => array('view', 'edit'), 'required' => false),
                    'address' => array('type' => 'string', 'context' => array('view', 'edit'), 'required' => false),
                );
            },
        ));
        $this->store_api_registered = true;
    }

    public function register_store_api_update(): void
    {
        if ($this->store_update_registered || !function_exists('woocommerce_store_api_register_update_callback')) {
            return;
        }
        woocommerce_store_api_register_update_callback(array('namespace' => self::STORE_API_NAMESPACE, 'callback' => array($this, 'store_api_update')));
        $this->store_update_registered = true;
    }

    public function store_api_update($data): void
    {
        $data = $this->normalize_array($data);
        if (empty($data['commit_token'])) {
            $method = $this->sanitize_method((string) ($data['method'] ?? self::DOMESTIC_METHOD));
            $this->invalidate_provider_state($method);
            $this->set_session($method . '_selection', array());
            $this->set_session($method . '_quote', array());
            $this->clear_shipping_cache();
            return;
        }

        try {
            $this->commit_provider_state($data);
        } catch (DomainException $exception) {
            $message = __('Выбор доставки устарел. Рассчитайте доставку ещё раз.', 'ranau-russian-post-for-woocommerce');
            if (class_exists(RouteException::class)) {
                throw new RouteException(esc_attr($exception->getMessage()), esc_html($message), 409);
            }
            throw new \RuntimeException(esc_html($message));
        }
    }

    public function validate_classic_checkout(array $data, \WP_Error $errors): void
    {
        $message = $this->selection_error($this->selected_method_id());
        if ($message !== '') {
            $errors->add('ranau_russian_post_incomplete', $message);
        }
    }

    public function validate_blocks_checkout(\WC_Order $order, \WP_REST_Request $request): void
    {
        if ($this->is_totals_request($request)) {
            return;
        }
        $message = $this->selection_error($this->selected_method_id($order), $order);
        if ($message !== '') {
            if (class_exists(RouteException::class)) {
                throw new RouteException('ranau_russian_post_incomplete', esc_html($message), 400);
            }
            throw new \RuntimeException(esc_html($message));
        }
    }

    public function save_classic_order(\WC_Order $order, array $data): void
    {
        $this->save_order($order);
    }

    public function save_blocks_order(\WC_Order $order, \WP_REST_Request $request): void
    {
        if ($this->is_totals_request($request)) {
            return;
        }
        $this->save_order($order);
    }

    private function save_order(\WC_Order $order): void
    {
        $method = $this->selected_method_id($order);
        if (!isset(self::METHODS[$method])) {
            return;
        }
        $selection = $this->session_array($method . '_selection');
        $object = $this->selected_object($order);
        $quote = $method === 'ranau_russian_post_world'
            ? (array) ($this->session_array($method . '_quotes')[$object] ?? array())
            : $this->session_array($method . '_quote');
        $destination = $this->destination_metadata($order, $method, $selection);
        foreach (array(
            '_ranau_delivery_provider' => 'russian_post',
            '_ranau_delivery_fulfillment_mode' => 'external',
            '_ranau_russian_post_method' => $method,
            '_ranau_russian_post_delivery_mode' => (string) ($selection['delivery_mode'] ?? ''),
            '_ranau_russian_post_origin_postcode' => TariffClient::origin_postcode(),
            '_ranau_russian_post_destination_postcode' => $destination['postcode'],
            '_ranau_russian_post_destination_country' => $destination['country'],
            '_ranau_russian_post_destination_address' => $destination['address'],
            '_ranau_russian_post_customer_address' => (string) ($selection['customer_address'] ?? ''),
            '_ranau_russian_post_normalized_address' => (string) ($selection['normalized_address'] ?? ''),
            '_ranau_russian_post_office_id' => (string) ($selection['id'] ?? ''),
            '_ranau_russian_post_office_name' => (string) ($selection['title'] ?? ''),
            '_ranau_russian_post_office_address' => (string) ($selection['address'] ?? ''),
            '_ranau_russian_post_office_latitude' => (string) ($selection['lat'] ?? ''),
            '_ranau_russian_post_office_longitude' => (string) ($selection['lon'] ?? ''),
            '_ranau_russian_post_object' => (string) $object,
            '_ranau_russian_post_carrier_price' => (string) ($quote['price'] ?? ''),
            '_ranau_russian_post_customer_price' => (string) ($quote['price'] ?? ''),
            '_ranau_russian_post_no_fulfillment' => 'yes',
        ) as $key => $value) {
            if ($key === '_ranau_russian_post_carrier_price' && $value === '') {
                continue;
            }
            $order->update_meta_data($key, $value);
        }
        if ($method === self::DOMESTIC_METHOD && !empty($selection['postcode'])) {
            $order->set_shipping_postcode((string) $selection['postcode']);
            $order->set_shipping_address_1((string) ($selection['customer_address'] ?? $selection['address'] ?? ''));
            if (!empty($selection['city'])) {
                $order->set_shipping_city((string) $selection['city']);
            }
            if (!empty($selection['state'])) {
                $order->set_shipping_state((string) $selection['state']);
            }
            $order->set_shipping_country('RU');
        }
    }

    private function destination_metadata(\WC_Order $order, string $method, array $selection): array
    {
        if ($method !== 'ranau_russian_post_world') {
            return array(
                'postcode' => (string) ($selection['postcode'] ?? ''),
                'country' => 'RU',
                'address' => (string) ($selection['address'] ?? ''),
            );
        }

        $address = array_filter(array(
            method_exists($order, 'get_shipping_address_1') ? (string) $order->get_shipping_address_1() : '',
            method_exists($order, 'get_shipping_address_2') ? (string) $order->get_shipping_address_2() : '',
            method_exists($order, 'get_shipping_city') ? (string) $order->get_shipping_city() : '',
            method_exists($order, 'get_shipping_state') ? (string) $order->get_shipping_state() : '',
            method_exists($order, 'get_shipping_postcode') ? (string) $order->get_shipping_postcode() : '',
            method_exists($order, 'get_shipping_country') ? (string) $order->get_shipping_country() : '',
        ), static fn(string $part): bool => $part !== '');

        return array(
            'postcode' => method_exists($order, 'get_shipping_postcode') ? (string) $order->get_shipping_postcode() : '',
            'country' => method_exists($order, 'get_shipping_country') ? strtoupper((string) $order->get_shipping_country()) : '',
            'address' => implode(', ', $address),
        );
    }

    public function clear_processed_order_session($order_id, array $posted_data = array(), ?\WC_Order $order = null): void
    {
        if (!$order && function_exists('wc_get_order')) {
            $loaded = wc_get_order($order_id);
            $order = $loaded instanceof \WC_Order ? $loaded : null;
        }
        $this->clear_order_session($order);
    }

    public function clear_processed_store_api_order_session(\WC_Order $order): void
    {
        $this->clear_order_session($order);
    }

    private function clear_order_session(?\WC_Order $order): void
    {
        $method = $this->selected_method_id($order);
        if (!isset(self::METHODS[$method])) {
            return;
        }
        $this->set_session($method . '_selection', array());
        $this->set_session($method . '_quote', array());
        $this->set_session($method . '_quotes', array());
    }

    private function selection_error(string $method, ?\WC_Order $order = null): string
    {
        if (!isset(self::METHODS[$method])) {
            return '';
        }
        $selection = $this->session_array($method . '_selection');
        $object = $this->selected_object($order);
        $quote = $method === 'ranau_russian_post_world'
            ? (array) ($this->session_array($method . '_quotes')[$object] ?? array())
            : $this->session_array($method . '_quote');
        if ($method === 'ranau_russian_post_world') {
            if (empty($selection['committed']) || empty($selection['postcode']) || empty($quote['ok']) || !isset($quote['price'])) {
                return __('Выберите доступный международный тариф Почты России и дождитесь расчета стоимости.', 'ranau-russian-post-for-woocommerce');
            }
            return '';
        }
        if (empty($selection['committed']) || empty($selection['postcode']) || empty($quote['ok'])) {
            if (($selection['delivery_mode'] ?? '') === self::MODE_ADDRESS) {
                return __('Укажите адрес доставки Почтой России и дождитесь расчета стоимости.', 'ranau-russian-post-for-woocommerce');
            }
            return __('Выберите отделение Почты России и дождитесь расчета стоимости.', 'ranau-russian-post-for-woocommerce');
        }
        return '';
    }

    private function selected_method_id(?\WC_Order $order = null): string
    {
        $order_method = $this->selected_order_method_id($order);
        if ($order_method !== '') {
            return $order_method;
        }
        foreach ($this->chosen_shipping_methods() as $method) {
            $id = $this->base_method_id((string) $method);
            if (isset(self::METHODS[$id])) {
                return $id;
            }
        }
        return '';
    }

    private function selected_order_method_id(?\WC_Order $order): string
    {
        if (!$order || !method_exists($order, 'get_shipping_methods')) {
            return '';
        }
        foreach ((array) $order->get_shipping_methods() as $item) {
            if (!is_object($item) || !method_exists($item, 'get_method_id')) {
                continue;
            }
            $id = $this->base_method_id((string) $item->get_method_id());
            if (isset(self::METHODS[$id])) {
                return $id;
            }
            if (method_exists($item, 'get_rate_id')) {
                $id = $this->base_method_id((string) $item->get_rate_id());
                if (isset(self::METHODS[$id])) {
                    return $id;
                }
            }
        }
        return '';
    }

    private function selected_object(?\WC_Order $order = null): int
    {
        $order_object = $this->selected_order_object($order);
        if ($order_object > 0) {
            return $order_object;
        }
        $chosen = (string) current($this->chosen_shipping_methods());
        if (preg_match('/_(5011|4031|7031)$/', $chosen, $matches)) {
            return (int) $matches[1];
        }
        $method = $this->base_method_id($chosen);
        if ($method === self::DOMESTIC_METHOD) {
            $quote = $this->session_array($method . '_quote');
            return (int) ($quote['object'] ?? self::METHODS[$method]);
        }
        return (int) (self::METHODS[$method] ?? 0);
    }

    private function selected_order_object(?\WC_Order $order): int
    {
        if (!$order || !method_exists($order, 'get_shipping_methods')) {
            return 0;
        }
        foreach ((array) $order->get_shipping_methods() as $item) {
            if (!is_object($item)) {
                continue;
            }
            foreach (array('get_rate_id', 'get_method_id') as $method) {
                if (method_exists($item, $method) && preg_match('/_(5011|4031|7031)$/', (string) $item->{$method}(), $matches)) {
                    return (int) $matches[1];
                }
            }
            if (method_exists($item, 'get_meta')) {
                $object = (int) $item->get_meta('ranau_russian_post_object', true);
                if ($object > 0) {
                    return $object;
                }
            }
        }
        return 0;
    }

    private function chosen_shipping_methods(): array
    {
        if (!function_exists('WC') || !WC()->session) {
            return array();
        }
        return (array) WC()->session->get('chosen_shipping_methods', array());
    }

    private function base_method_id(string $method): string
    {
        return explode(':', $method)[0];
    }

    private function is_totals_request(\WP_REST_Request $request): bool
    {
        $value = $request->get_param('__experimental_calc_totals');
        if ($value === null && method_exists($request, 'get_json_params')) {
            $params = $this->normalize_array($request->get_json_params());
            $value = $params['__experimental_calc_totals'] ?? null;
        }
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    private function sanitize_method(string $method): string
    {
        return isset(self::METHODS[$method]) ? $method : self::DOMESTIC_METHOD;
    }

    private function normalize_array($value): array
    {
        return is_array($value) ? $value : array();
    }

    /** @param array<string, mixed> $selection
     *  @param array<string, mixed> $quote
     *  @return array<string, mixed>
     */
    private function begin_provider_state(string $method, array $selection, array $quote): array
    {
        $rate_id = $this->selected_full_rate_id($method);
        $fingerprint = $this->package_fingerprint();

        return $this->provider_state_store($rate_id)->beginPending(
            $this->context_key($selection, $fingerprint),
            $fingerprint,
            $selection,
            $quote
        );
    }

    /** @param array<string, mixed> $data
     *  @return array<string, mixed>
     */
    private function commit_provider_state(array $data): array
    {
        $rate_id = sanitize_text_field((string) ($data['rate_id'] ?? ''));
        $method = $this->base_method_id($rate_id);
        if (!isset(self::METHODS[$method])) {
            throw new DomainException('invalid_rate');
        }

        $selected_rate_id = $this->selected_full_rate_id($method);
        $selection = $this->session_array($method . '_selection');
        $fingerprint = $this->package_fingerprint();
        $committed = $this->provider_state_store($rate_id)->commit(
            $data,
            $selected_rate_id,
            $this->context_key($selection, $fingerprint),
            $fingerprint
        );

        $selection = (array) ($committed['selection'] ?? array());
        $selection['committed'] = true;
        $this->set_session($method . '_selection', $selection);
        $this->set_session($method . '_quote', (array) ($committed['quote'] ?? array()));
        $this->clear_shipping_cache();
        $this->persist_session();

        return $committed;
    }

    private function invalidate_provider_state(string $method): void
    {
        $rate_id = $this->selected_full_rate_id($method);
        if ($rate_id !== '') {
            $this->provider_state_store($rate_id)->invalidate();
        }
    }

    private function provider_state_store(string $rate_id): ProviderStateStore
    {
        $rate_id = trim($rate_id);
        if ($rate_id === '') {
            throw new DomainException('missing_rate_id');
        }
        $key = 'ranau_russian_post_state_' . md5($rate_id);

        return new ProviderStateStore(
            $rate_id,
            function () use ($key): array {
                return $this->session_array($key);
            },
            function (array $value) use ($key): void {
                $this->set_session($key, $value);
                $this->persist_session();
            },
            function () use ($key): void {
                if (function_exists('WC') && WC()->session) {
                    WC()->session->__unset($key);
                    $this->persist_session();
                }
            }
        );
    }

    private function selected_full_rate_id(string $method): string
    {
        foreach ($this->chosen_shipping_methods() as $rate_id) {
            $rate_id = sanitize_text_field((string) $rate_id);
            if ($this->base_method_id($rate_id) === $method) {
                return $rate_id;
            }
        }

        return '';
    }

    /** @param array<string, mixed> $selection */
    private function context_key(array $selection, string $fingerprint): string
    {
        $customer = function_exists('WC') ? WC()->customer : null;
        $parts = array(
            'RU',
            (string) ($selection['state'] ?? ($customer && method_exists($customer, 'get_shipping_state') ? $customer->get_shipping_state() : '')),
            (string) ($selection['city'] ?? ($customer && method_exists($customer, 'get_shipping_city') ? $customer->get_shipping_city() : '')),
            (string) ($selection['postcode'] ?? ($customer && method_exists($customer, 'get_shipping_postcode') ? $customer->get_shipping_postcode() : '')),
            $fingerprint,
        );

        return implode('|', array_map(static function ($value): string {
            return strtolower(trim((string) preg_replace('/\s+/u', ' ', (string) $value)));
        }, $parts));
    }

    private function package_fingerprint(): string
    {
        $package = (new PackageResolver())->resolve_cart();

        return hash('sha256', (string) wp_json_encode($package));
    }

    private function session_array(string $key): array
    {
        if (!function_exists('WC') || !WC()->session) {
            return array();
        }
        $value = WC()->session->get($key, array());
        return is_array($value) ? $value : array();
    }

    private function set_session(string $key, array $value): void
    {
        if (function_exists('WC') && WC()->session) {
            WC()->session->set($key, $value);
        }
    }

    private function clear_shipping_cache(): void
    {
        if (!function_exists('WC') || !WC()->session) {
            return;
        }
        foreach (array_keys((array) WC()->session->get_session_data()) as $key) {
            if (strpos((string) $key, 'shipping_for_package_') === 0) {
                WC()->session->__unset($key);
            }
        }
    }

    private function persist_session(): void
    {
        if (function_exists('WC') && WC()->session && method_exists(WC()->session, 'save_data')) {
            WC()->session->save_data();
        }
    }

    private function ensure_cart_loaded(): void
    {
        if (!function_exists('WC') || !function_exists('wc_load_cart')) {
            return;
        }
        if (!WC()->session || !WC()->cart) {
            wc_load_cart();
        }
    }

    private function yandex_map_api_key(): string
    {
        $settings = get_option('woocommerce_official_cdek_settings', array());
        return is_array($settings) ? sanitize_text_field((string) ($settings['yandex_map_api_key'] ?? $settings['yandex_maps_key'] ?? '')) : '';
    }

    private function asset_version(string $relative): string
    {
        $path = RANAU_RUSSIAN_POST_DIR . $relative;
        return file_exists($path) ? (string) filemtime($path) : RANAU_RUSSIAN_POST_VERSION;
    }
}
