<?php

declare(strict_types=1);

namespace Ranau\RussianPost;

defined('ABSPATH') || exit;

final class TariffClient
{
    public const DEFAULT_ORIGIN_POSTCODE = '';
    public const OBJECT_DOMESTIC_OPS = 27030;
    public const OBJECT_DOMESTIC_EMS = 7030;
    public const OBJECT_INTERNATIONAL_SMALL_PACKET = 5011;
    public const OBJECT_INTERNATIONAL_PARCEL = 4031;
    public const OBJECT_INTERNATIONAL_EMS = 7031;

    private const BASE_URL = 'https://tariff.pochta.ru/tariff/v2/calculate';
    private const COUNTRY_URL = 'https://tariff.pochta.ru/v2/dictionary/country';

    public function quote(array $package, string $to, int $object): array
    {
        if (empty($package['ok'])) {
            return $package;
        }

        $destination = strtoupper(trim($to));
        if (!$this->valid_destination($destination, $object)) {
            return $this->error('destination_required', __('Укажите корректный индекс или страну доставки.', 'ranau-russian-post-for-woocommerce'));
        }

        $origin = self::origin_postcode();
        if (!preg_match('/^\d{6}$/', $origin)) {
            return $this->error('origin_postcode_missing', __('Укажите шестизначный индекс отправителя в настройках магазина WooCommerce.', 'ranau-russian-post-for-woocommerce'));
        }

        $query = array(
            'json' => 1,
            'object' => $object,
            'from' => $origin,
            'weight' => (int) $package['weight'],
            'sumoc' => (int) ($package['value'] ?? 1),
        );
        if ($object === self::OBJECT_DOMESTIC_OPS) {
            $pack = (int) ($package['standard_pack'] ?? 0);
            if (!in_array($pack, array(10, 20, 30, 40), true)) {
                return $this->error(
                    'standard_package_unsupported',
                    __('Заказ не помещается в стандартную коробку Почты России S, M, L или XL.', 'ranau-russian-post-for-woocommerce')
                );
            }
            $query['pack'] = $pack;
        }
        if ($this->is_international($object)) {
            $country_id = $this->country_id($destination);
            if ($country_id <= 0) {
                return $this->error('country_unavailable', __('Страна не найдена в справочнике Почты России.', 'ranau-russian-post-for-woocommerce'));
            }
            $query['country'] = $country_id;
        } else {
            $query['to'] = $destination;
        }

        $cache_key = 'ranau_russian_post_tariff_' . md5(wp_json_encode($query));
        $cached = get_transient($cache_key);
        if (is_array($cached)) {
            return $cached;
        }

        $response = wp_remote_get(add_query_arg($query, self::BASE_URL), array('timeout' => 10));
        if (is_wp_error($response)) {
            return $this->error('tariff_unavailable', $response->get_error_message());
        }

        $body = json_decode((string) wp_remote_retrieve_body($response), true);
        if (!is_array($body) || !empty($body['errors'])) {
            return $this->error('tariff_rejected', $this->error_text($body));
        }

        $amount = $this->amount($body);
        if ($amount === null) {
            return $this->error('tariff_price_missing', __('Почта России не вернула стоимость доставки.', 'ranau-russian-post-for-woocommerce'));
        }

        $quote = array(
            'ok' => true,
            'object' => $object,
            'origin_postcode' => $origin,
            'destination' => $destination,
            'price' => $amount,
            'days' => isset($body['delivery-time']['max-days']) ? (int) $body['delivery-time']['max-days'] : 0,
        );
        set_transient($cache_key, $quote, 15 * MINUTE_IN_SECONDS);

        return $quote;
    }

    public static function origin_postcode(): string
    {
        $postcode = '';
        if (function_exists('WC') && WC()->countries) {
            $postcode = (string) WC()->countries->get_base_postcode();
        }

        $postcode = (string) apply_filters('ranau_russian_post_origin_postcode', $postcode);

        return (string) preg_replace('/\D+/', '', $postcode);
    }

    private function valid_destination(string $to, int $object): bool
    {
        if ($this->is_international($object)) {
            return (bool) preg_match('/^[A-Z]{2}$/', $to);
        }

        return (bool) preg_match('/^\d{6}$/', $to);
    }

    private function is_international(int $object): bool
    {
        return in_array($object, array(self::OBJECT_INTERNATIONAL_SMALL_PACKET, self::OBJECT_INTERNATIONAL_PARCEL, self::OBJECT_INTERNATIONAL_EMS), true);
    }

    private function country_id(string $alpha2): int
    {
        $cache_key = 'ranau_russian_post_country_' . strtolower($alpha2);
        $cached = get_transient($cache_key);
        if (is_numeric($cached)) {
            return (int) $cached;
        }

        $url = self::COUNTRY_URL . '/' . rawurlencode($alpha2);
        $response = wp_remote_get(add_query_arg(array('json' => 1), $url), array('timeout' => 10));
        if (is_wp_error($response)) {
            return 0;
        }

        $body = json_decode((string) wp_remote_retrieve_body($response), true);
        foreach ((array) ($body['country'] ?? $body['countries'] ?? $body) as $country) {
            if (!is_array($country)) {
                continue;
            }
            $code = strtoupper((string) ($country['alpha2'] ?? $country['alpha-2'] ?? $country['code2'] ?? ''));
            foreach ((array) ($country['altnames'] ?? array()) as $altname) {
                if (is_array($altname) && (int) ($altname['type'] ?? 0) === 2 && !empty($altname['name'])) {
                    $code = strtoupper((string) $altname['name']);
                }
            }
            $id = (int) ($country['id'] ?? $country['country'] ?? $country['code'] ?? 0);
            if ($code === $alpha2 && $id > 0) {
                set_transient($cache_key, $id, WEEK_IN_SECONDS);
                return $id;
            }
        }

        return 0;
    }

    private function amount(array $body): ?float
    {
        foreach (array('paynds', 'pay', 'ground-rate-with-vat', 'avia-rate-with-vat', 'notice-rate-with-vat') as $key) {
            if (isset($body[$key]) && is_numeric($body[$key])) {
                return round(((float) $body[$key]) / 100, 2);
            }
        }

        return null;
    }

    private function error_text($body): string
    {
        if (is_array($body) && !empty($body['errors'][0]['msg'])) {
            return sanitize_text_field((string) $body['errors'][0]['msg']);
        }

        return __('Не удалось рассчитать тариф Почты России.', 'ranau-russian-post-for-woocommerce');
    }

    private function error(string $code, string $message): array
    {
        return array('ok' => false, 'code' => $code, 'message' => $message);
    }
}
