<?php

declare(strict_types=1);

namespace Ranau\RussianPost;

defined('ABSPATH') || exit;

final class AddressClient
{
    private const GEOCODE_URL = 'https://geocode-maps.yandex.ru/v1';

    public function confirm(string $address, ?float $lat = null, ?float $lon = null): array
    {
        $address = sanitize_text_field($address);
        $has_coordinates = $lat !== null && $lon !== null;
        if ($has_coordinates && !$this->valid_coordinates($lat, $lon)) {
            return $this->error('coordinates_invalid', __('Координаты адреса некорректны.', 'ranau-russian-post-for-woocommerce'));
        }
        if ($address === '' && ($lat === null || $lon === null)) {
            return $this->error('address_required', __('Укажите адрес доставки.', 'ranau-russian-post-for-woocommerce'));
        }

        $api_key = $this->geocoder_api_key();
        if ($api_key === '') {
            return $this->error('geocoder_key_missing', __('Не настроен ключ Яндекс Геокодера.', 'ranau-russian-post-for-woocommerce'));
        }

        $query = array(
            'apikey' => $api_key,
            'format' => 'json',
            'lang' => 'ru_RU',
            'results' => 1,
        );
        if ($lat !== null && $lon !== null) {
            $query['geocode'] = $lon . ',' . $lat;
        } else {
            $query['geocode'] = $address;
        }

        $response = wp_remote_get(add_query_arg($query, self::GEOCODE_URL), array(
            'timeout' => 8,
            'headers' => array(
                'Referer' => home_url('/'),
                'Origin' => home_url(),
            ),
        ));
        if (is_wp_error($response)) {
            return $this->error('geocoder_unavailable', $response->get_error_message());
        }
        $status = wp_remote_retrieve_response_code($response);
        if ($status < 200 || $status >= 300) {
            return $this->error('geocoder_unavailable', __('Яндекс Геокодер временно недоступен.', 'ranau-russian-post-for-woocommerce'));
        }

        $result = $this->first_geo_object(json_decode((string) wp_remote_retrieve_body($response), true) ?: array());
        if (empty($result)) {
            return $this->error('address_not_found', __('Яндекс Геокодер не нашел адрес доставки.', 'ranau-russian-post-for-woocommerce'));
        }

        if (empty($result['house_level'])) {
            return $this->error('house_required', __('Укажите адрес с номером дома.', 'ranau-russian-post-for-woocommerce'));
        }

        $postcode = preg_replace('/\D+/', '', (string) ($result['postcode'] ?? ''));
        if (!preg_match('/^\d{6}$/', $postcode)) {
            return $this->error('postcode_missing', __('Для адреса не удалось определить шестизначный индекс.', 'ranau-russian-post-for-woocommerce'));
        }

        return array('ok' => true, 'address' => array(
            'address' => (string) ($result['address'] ?: $address),
            'customer_address' => $has_coordinates ? (string) ($result['address'] ?: $address) : ($address !== '' ? $address : (string) ($result['address'] ?? '')),
            'normalized_address' => (string) ($result['address'] ?? ''),
            'postcode' => $postcode,
            'city' => (string) ($result['city'] ?? ''),
            'state' => (string) ($result['state'] ?? ''),
            'lat' => (string) ($result['lat'] ?? ''),
            'lon' => (string) ($result['lon'] ?? ''),
            'source' => 'yandex_geocoder',
            'committed' => true,
        ));
    }

    private function geocoder_api_key(): string
    {
        $settings = get_option('ranau_russian_post_settings', array());
        $value = is_array($settings) ? sanitize_text_field((string) ($settings['yandex_geocoder_api_key'] ?? '')) : '';
        if ($value !== '') {
            return $value;
        }
        return sanitize_text_field((string) apply_filters('ranau_russian_post_yandex_geocoder_api_key', ''));
    }

    private function first_geo_object(array $body): array
    {
        $members = (array) ($body['response']['GeoObjectCollection']['featureMember'] ?? array());
        $geo = (array) ($members[0]['GeoObject'] ?? array());
        if (empty($geo)) {
            return array();
        }
        $meta = (array) ($geo['metaDataProperty']['GeocoderMetaData'] ?? array());
        $address = (array) ($meta['Address'] ?? array());
        $postcode = (string) ($address['postal_code'] ?? $meta['postal_code'] ?? '');
        $point = preg_split('/\s+/', trim((string) ($geo['Point']['pos'] ?? '')));
        $components = (array) ($address['Components'] ?? array());
        $city = '';
        $state = '';
        $has_house = (string) ($meta['kind'] ?? '') === 'house' || (string) ($meta['precision'] ?? '') === 'exact';
        foreach ($components as $component) {
            if (!is_array($component)) {
                continue;
            }
            $kind = (string) ($component['kind'] ?? '');
            $name = sanitize_text_field((string) ($component['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            if ($kind === 'locality' && $city === '') {
                $city = $name;
            }
            if (in_array($kind, array('province', 'area'), true) && $state === '' && stripos($name, 'федеральный округ') === false) {
                $state = $name;
            }
            if ($kind === 'house') {
                $has_house = true;
            }
        }

        return array(
            'address' => sanitize_text_field((string) ($meta['text'] ?? $address['formatted'] ?? $geo['name'] ?? '')),
            'postcode' => $postcode,
            'city' => $city,
            'state' => $state,
            'house_level' => $has_house,
            'lat' => isset($point[1]) ? (float) $point[1] : null,
            'lon' => isset($point[0]) ? (float) $point[0] : null,
        );
    }

    private function error(string $code, string $message): array
    {
        return array('ok' => false, 'code' => $code, 'message' => $message);
    }

    private function valid_coordinates(?float $lat, ?float $lon): bool
    {
        return $lat !== null && $lon !== null && $lat >= -90 && $lat <= 90 && $lon >= -180 && $lon <= 180;
    }
}
