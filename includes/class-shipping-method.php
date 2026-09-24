<?php

declare(strict_types=1);

namespace Ranau\RussianPost;

defined('ABSPATH') || exit;

class ShippingMethod extends \WC_Shipping_Method
{
    private int $object;
    private string $session_key;

    public function __construct($instance_id = 0, string $id = 'ranau_russian_post', int $object = TariffClient::OBJECT_DOMESTIC_OPS)
    {
        $this->id = $id;
        $this->object = $object;
        $this->session_key = 'ranau_russian_post_selection';
        $this->instance_id = absint($instance_id);
        $this->method_title = $this->default_title();
        $this->method_description = __('Расчет тарифов Почты России без создания отправлений.', 'ranau-russian-post-for-woocommerce');
        $this->supports = array('shipping-zones', 'instance-settings');
        $this->init();
    }

    public function init(): void
    {
        $this->instance_form_fields = array(
            'title' => array('title' => __('Название', 'ranau-russian-post-for-woocommerce'), 'type' => 'text', 'default' => $this->default_title()),
        );
        $this->title = (string) $this->get_option('title', $this->default_title());
        add_action('woocommerce_update_options_shipping_' . $this->id, array($this, 'process_admin_options'));
    }

    public function calculate_shipping($package = array()): void
    {
        $country = strtoupper((string) ($package['destination']['country'] ?? ''));
        if ($country !== '' && $country !== 'RU') {
            return;
        }

        if (in_array($this->id, array('ranau_russian_post_ops', 'ranau_russian_post_ems'), true)) {
            return;
        }

        $selection = $this->selection($package);
        $quote = $this->quote($package, $selection);
        $ready = $this->id === 'ranau_russian_post'
            ? (!empty($selection['committed']) && !empty($selection['postcode']) && !empty($quote['ok']) && isset($quote['price']))
            : (!empty($quote['ok']) && isset($quote['price']));
        if ($this->id !== 'ranau_russian_post' && !$ready) {
            return;
        }
        if ($this->id !== 'ranau_russian_post' && $ready) {
            $this->store_session($this->id . '_quote', $quote);
            $this->store_session($this->id . '_selection', $selection);
        }
        $label = $this->title;
        if ($ready && !empty($quote['days'])) {
            /* translators: %d: maximum delivery time in days. */
            $label .= sprintf(__(' (%d дн.)', 'ranau-russian-post-for-woocommerce'), (int) $quote['days']);
        }

        $this->add_rate(array(
            'id' => $this->get_rate_id(),
            'label' => $label,
            'cost' => $ready ? (float) $quote['price'] : 0.0,
            'package' => $package,
            'meta_data' => array(
                'ranau_russian_post_ready' => $ready ? 'yes' : 'no',
                'ranau_russian_post_method' => $this->id,
                'ranau_russian_post_delivery_mode' => (string) ($selection['delivery_mode'] ?? 'ops'),
                'ranau_russian_post_object' => (int) ($quote['object'] ?? $this->object),
                'ranau_russian_post_origin_postcode' => TariffClient::origin_postcode(),
                'ranau_russian_post_destination_postcode' => (string) ($selection['postcode'] ?? ''),
                'ranau_russian_post_carrier_price' => $ready ? (float) $quote['price'] : '',
                'ranau_russian_post_no_fulfillment' => 'yes',
            ),
        ));
    }

    protected function selection(array $package): array
    {
        if ($this->id === 'ranau_russian_post') {
            return $this->session_array($this->session_key);
        }

        $destination = $this->id === 'ranau_russian_post_world'
            ? strtoupper((string) ($package['destination']['country'] ?? ''))
            : preg_replace('/\D+/', '', (string) ($package['destination']['postcode'] ?? ''));

        return array('postcode' => $destination, 'committed' => $destination !== '');
    }

    protected function quote(array $package, array $selection): array
    {
        if ($this->id === 'ranau_russian_post') {
            return $this->session_array('ranau_russian_post_quote');
        }

        return (new TariffClient())->quote((new PackageResolver())->resolve_cart(), (string) ($selection['postcode'] ?? ''), $this->object);
    }

    private function default_title(): string
    {
        if ($this->id === 'ranau_russian_post_world') {
            return __('Почта России международная', 'ranau-russian-post-for-woocommerce');
        }
        return __('Почта России', 'ranau-russian-post-for-woocommerce');
    }

    private function session_array(string $key): array
    {
        if (!function_exists('WC') || !WC()->session) {
            return array();
        }
        $value = WC()->session->get($key, array());
        return is_array($value) ? $value : array();
    }

    protected function store_session(string $key, array $value): void
    {
        if (function_exists('WC') && WC()->session) {
            WC()->session->set($key, $value);
        }
    }
}

final class OpsShippingMethod extends ShippingMethod
{
    public function __construct($instance_id = 0)
    {
        parent::__construct($instance_id, 'ranau_russian_post', TariffClient::OBJECT_DOMESTIC_OPS);
    }
}

final class LegacyOpsShippingMethod extends ShippingMethod
{
    public function __construct($instance_id = 0)
    {
        parent::__construct($instance_id, 'ranau_russian_post', TariffClient::OBJECT_DOMESTIC_OPS);
    }
}

final class EmsShippingMethod extends ShippingMethod
{
    public function __construct($instance_id = 0)
    {
        parent::__construct($instance_id, 'ranau_russian_post_ems', TariffClient::OBJECT_DOMESTIC_EMS);
    }
}

final class WorldShippingMethod extends ShippingMethod
{
    public function __construct($instance_id = 0)
    {
        parent::__construct($instance_id, 'ranau_russian_post_world', TariffClient::OBJECT_INTERNATIONAL_PARCEL);
    }

    public function calculate_shipping($package = array()): void
    {
        $country = strtoupper((string) ($package['destination']['country'] ?? ''));
        if ($country === '' || $country === 'RU') {
            return;
        }

        $selection = $this->selection((array) $package);
        $quotes = array();
        foreach (array(
            TariffClient::OBJECT_INTERNATIONAL_SMALL_PACKET => __('мелкий пакет', 'ranau-russian-post-for-woocommerce'),
            TariffClient::OBJECT_INTERNATIONAL_PARCEL => __('посылка', 'ranau-russian-post-for-woocommerce'),
            TariffClient::OBJECT_INTERNATIONAL_EMS => __('EMS', 'ranau-russian-post-for-woocommerce'),
        ) as $object => $title) {
            $quote = (new TariffClient())->quote((new PackageResolver())->resolve_cart(), (string) ($selection['postcode'] ?? ''), (int) $object);
            if (empty($quote['ok']) || !isset($quote['price'])) {
                continue;
            }
            $quotes[(int) $object] = $quote;
            /* translators: %d: maximum delivery time in days. */
            $delivery_time = !empty($quote['days']) ? sprintf(__(' (%d дн.)', 'ranau-russian-post-for-woocommerce'), (int) $quote['days']) : '';
            $this->add_rate(array(
                'id' => $this->get_rate_id() . '_' . $object,
                'label' => $this->title . ' - ' . $title . $delivery_time,
                'cost' => (float) $quote['price'],
                'package' => $package,
                'meta_data' => array(
                    'ranau_russian_post_ready' => 'yes',
                    'ranau_russian_post_method' => $this->id,
                    'ranau_russian_post_object' => (int) $object,
                    'ranau_russian_post_origin_postcode' => TariffClient::origin_postcode(),
                    'ranau_russian_post_carrier_price' => (float) $quote['price'],
                    'ranau_russian_post_no_fulfillment' => 'yes',
                ),
            ));
        }
        $this->store_session($this->id . '_selection', $selection);
        $this->store_session($this->id . '_quotes', $quotes);
    }
}
