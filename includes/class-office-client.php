<?php

declare(strict_types=1);

namespace Ranau\RussianPost;

defined('ABSPATH') || exit;

final class OfficeClient
{
    private const LIST_URL = 'https://tariff.pochta.ru/v2/dictionary/postoffice';
    private const EXACT_URL = 'https://tariff.pochta.ru/v2/dictionary/postoffice/';
    private const OVERPASS_URL = 'https://overpass-api.de/api/interpreter';

    public function search(array $query): array
    {
        $postcode = preg_replace('/\D+/', '', (string) ($query['postcode'] ?? ''));
        $offices = array();

        if (preg_match('/^\d{6}$/', $postcode)) {
            $office = $this->by_postcode($postcode);
            if (!empty($office['ok'])) {
                $offices[] = $office['office'];
            }
        }

        $lat = isset($query['lat']) ? (float) $query['lat'] : 0.0;
        $lon = isset($query['lon']) ? (float) $query['lon'] : 0.0;
        if ($lat && $lon) {
            $candidates = $this->nearby($lat, $lon);
            $validated = $this->by_postcodes(array_column($candidates, 'postcode'));
            foreach ($candidates as $candidate) {
                $index = (string) ($candidate['postcode'] ?? '');
                if (isset($validated[$index])) {
                    // Official data deliberately wins over discovery coordinates and labels.
                    $offices[] = array_merge($candidate, $validated[$index]);
                }
            }
        }

        $offices = array_values($this->dedupe($offices));
        if ($lat && $lon) {
            usort($offices, static function (array $left, array $right) use ($lat, $lon): int {
                $left_distance = self::distance_squared($lat, $lon, (float) ($left['lat'] ?? 0), (float) ($left['lon'] ?? 0));
                $right_distance = self::distance_squared($lat, $lon, (float) ($right['lat'] ?? 0), (float) ($right['lon'] ?? 0));
                return $left_distance <=> $right_distance;
            });
            $offices = array_slice($offices, 0, 12);
        }

        return $offices;
    }

    public function by_postcode(string $postcode): array
    {
        $postcode = preg_replace('/\D+/', '', $postcode);
        if (!preg_match('/^\d{6}$/', $postcode)) {
            return $this->error('postcode_invalid', __('Индекс ОПС должен состоять из 6 цифр.', 'ranau-russian-post-for-woocommerce'));
        }

        $cache_key = 'ranau_russian_post_office_' . $postcode;
        $cached = get_transient($cache_key);
        if (is_array($cached)) {
            return $cached;
        }

        $response = wp_remote_get(self::EXACT_URL . rawurlencode($postcode), array('timeout' => 10));
        if (is_wp_error($response)) {
            return $this->error('office_unavailable', $response->get_error_message());
        }

        if ((int) wp_remote_retrieve_response_code($response) < 200 || (int) wp_remote_retrieve_response_code($response) >= 300) {
            return $this->error('office_unavailable', __('Официальный справочник ОПС временно недоступен.', 'ranau-russian-post-for-woocommerce'));
        }

        $body = json_decode((string) wp_remote_retrieve_body($response), true);
        $row = is_array($body) && isset($body[0]) ? $body[0] : (is_array($body['postoffice'][0] ?? null) ? $body['postoffice'][0] : $body);
        $office = $this->normalize_office($row);
        if ($office === null) {
            return $this->error('office_not_found', __('ОПС не найдено в официальном справочнике Почты России.', 'ranau-russian-post-for-woocommerce'));
        }
        $result = array('ok' => true, 'office' => $office);
        set_transient($cache_key, $result, DAY_IN_SECONDS);

        return $result;
    }

    private function nearby(float $lat, float $lon): array
    {
        // A coarse city-area center is sufficient for discovery and avoids sending exact coordinates.
        $lat = round(max(-90.0, min(90.0, $lat)), 2);
        $lon = round(max(-180.0, min(180.0, $lon)), 2);
        $cache_key = 'ranau_russian_post_nearby_' . md5($lat . ':' . $lon);
        $cached = get_transient($cache_key);
        if (is_array($cached)) {
            return $cached;
        }

        $radius = 5000;
        $overpass = '[out:json][timeout:8];('
            . 'nwr["amenity"="post_office"]["operator:wikidata"="Q1502763"](around:' . $radius . ',' . $lat . ',' . $lon . ');'
            . 'nwr["amenity"="post_office"]["brand:wikidata"="Q1502763"](around:' . $radius . ',' . $lat . ',' . $lon . ');'
            . ');out center tags 30;';
        $response = wp_remote_post(self::OVERPASS_URL, array('timeout' => 10, 'body' => array('data' => $overpass)));
        if (is_wp_error($response)) {
            return array();
        }

        $body = json_decode((string) wp_remote_retrieve_body($response), true);
        $offices = array();
        foreach ((array) ($body['elements'] ?? array()) as $element) {
            $tags = is_array($element['tags'] ?? null) ? $element['tags'] : array();
            $postcode = preg_replace('/\D+/', '', (string) ($tags['addr:postcode'] ?? $tags['postal_code'] ?? $tags['ref'] ?? ''));
            if (!preg_match('/^\d{6}$/', $postcode)) {
                continue;
            }
            $offices[] = array(
                'postcode' => $postcode,
                'lat' => isset($element['lat']) ? (string) $element['lat'] : (string) ($element['center']['lat'] ?? ''),
                'lon' => isset($element['lon']) ? (string) $element['lon'] : (string) ($element['center']['lon'] ?? ''),
            );
        }

        $offices = array_values($this->dedupe($offices));
        set_transient($cache_key, $offices, 6 * HOUR_IN_SECONDS);

        return $offices;
    }

    private function by_postcodes(array $postcodes): array
    {
        $postcodes = array_values(array_unique(array_filter(array_map(static function ($postcode): string {
            return preg_replace('/\D+/', '', (string) $postcode);
        }, $postcodes), static function (string $postcode): bool {
            return (bool) preg_match('/^\d{6}$/', $postcode);
        })));
        $postcodes = array_slice($postcodes, 0, 30);
        $offices = array();
        $missing = array();

        foreach ($postcodes as $postcode) {
            $cached = get_transient('ranau_russian_post_office_' . $postcode);
            if (is_array($cached) && !empty($cached['ok']) && is_array($cached['office'] ?? null)) {
                $offices[$postcode] = $cached['office'];
            } else {
                $missing[] = $postcode;
            }
        }

        foreach (array_chunk($missing, 10) as $chunk) {
            $url = add_query_arg(array('json' => 1, 'id' => implode(',', $chunk)), self::LIST_URL);
            $response = wp_remote_get($url, array('timeout' => 10));
            if (is_wp_error($response) || (int) wp_remote_retrieve_response_code($response) < 200 || (int) wp_remote_retrieve_response_code($response) >= 300) {
                continue;
            }
            $body = json_decode((string) wp_remote_retrieve_body($response), true);
            foreach ((array) ($body['postoffice'] ?? array()) as $row) {
                $office = $this->normalize_office($row);
                if ($office === null) {
                    continue;
                }
                $postcode = (string) $office['postcode'];
                $offices[$postcode] = $office;
                set_transient('ranau_russian_post_office_' . $postcode, array('ok' => true, 'office' => $office), DAY_IN_SECONDS);
            }
        }

        return $offices;
    }

    private function normalize_office($row): ?array
    {
        if (!is_array($row) || empty($row['index']) || !empty($row['closed']) || !empty($row['work-closed']) || !empty($row['invalid'])) {
            return null;
        }

        return array(
            'id' => sanitize_text_field((string) $row['index']),
            'postcode' => sanitize_text_field((string) $row['index']),
            'title' => sanitize_text_field((string) ($row['name'] ?? ('ОПС ' . $row['index']))),
            'address' => sanitize_text_field((string) ($row['address'] ?? '')),
            'lat' => isset($row['geo.lat']) ? (string) $row['geo.lat'] : '',
            'lon' => isset($row['geo.long']) ? (string) $row['geo.long'] : '',
            'type' => !empty($row['pvz']) ? 'pvz' : 'ops',
            'pvz' => !empty($row['pvz']),
            'partner' => !empty($row['partner']),
            'source' => 'russian_post_official_postoffice',
        );
    }

    private function dedupe(array $offices): array
    {
        $result = array();
        foreach ($offices as $office) {
            $postcode = (string) ($office['postcode'] ?? '');
            if ($postcode !== '') {
                $result[$postcode] = $office;
            }
        }

        return $result;
    }

    private static function distance_squared(float $origin_lat, float $origin_lon, float $lat, float $lon): float
    {
        if (!$lat || !$lon) {
            return PHP_FLOAT_MAX;
        }

        $lat_scale = cos(deg2rad($origin_lat));
        return (($lat - $origin_lat) ** 2) + ((($lon - $origin_lon) * $lat_scale) ** 2);
    }

    private function error(string $code, string $message): array
    {
        return array('ok' => false, 'code' => $code, 'message' => $message);
    }
}
