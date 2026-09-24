<?php

declare(strict_types=1);

namespace Ranau\RussianPost;

defined('ABSPATH') || exit;

final class PackageResolver
{
    public function resolve_cart(): array
    {
        if (!function_exists('WC') || !WC()->cart) {
            return $this->error('cart_unavailable', __('Не удалось получить состав корзины.', 'ranau-russian-post-for-woocommerce'));
        }

        $weight = 0;
        $value = 0;
        $length_cm = 0.0;
        $width_cm = 0.0;
        $height_cm = 0.0;

        foreach ((array) WC()->cart->get_cart() as $item) {
            $product = $item['data'] ?? null;
            if (!$product || !is_object($product)) {
                continue;
            }

            $quantity = max(1, (int) ($item['quantity'] ?? 1));
            $item_weight = method_exists($product, 'get_weight') ? (float) $product->get_weight() : 0.0;
            $length = $this->dimension_cm($product, 'get_length');
            $width = $this->dimension_cm($product, 'get_width');
            $height = $this->dimension_cm($product, 'get_height');
            if ($item_weight <= 0 || $length <= 0 || $width <= 0 || $height <= 0) {
                return $this->error('package_dimensions_missing', __('Для расчета Почты России у одного из товаров не заполнены вес или габариты.', 'ranau-russian-post-for-woocommerce'));
            }

            $weight += (int) round((function_exists('wc_get_weight') ? (float) wc_get_weight($item_weight, 'kg') : $item_weight) * 1000) * $quantity;
            $length_cm = max($length_cm, $length);
            $width_cm = max($width_cm, $width);
            $height_cm += $height * $quantity;
            $line_total = isset($item['line_subtotal']) ? (float) $item['line_subtotal'] : 0.0;
            $value += $line_total;
        }

        if ($weight <= 0) {
            return $this->error('package_empty', __('Корзина не содержит товаров для доставки.', 'ranau-russian-post-for-woocommerce'));
        }

        $dimensions = array($length_cm, $width_cm, $height_cm);
        rsort($dimensions, SORT_NUMERIC);

        return array(
            'ok' => true,
            'weight' => $weight,
            'value' => max(1, (int) round($value * 100)),
            'dimensions_cm' => array_map(static fn(float $dimension): float => round($dimension, 1), $dimensions),
            'standard_pack' => $this->standard_pack($dimensions, $weight),
        );
    }

    private function dimension_cm(object $product, string $getter): float
    {
        $raw = method_exists($product, $getter) ? (float) $product->{$getter}() : 0.0;
        if ($raw <= 0) {
            return 0.0;
        }

        return function_exists('wc_get_dimension') ? (float) wc_get_dimension($raw, 'cm') : $raw;
    }

    private function standard_pack(array $dimensions, int $weight): int
    {
        if ($weight > 10000) {
            return 0;
        }

        $packs = array(
            10 => array(26.3, 17.6, 9.9),
            20 => array(30.3, 23.3, 17.2),
            30 => array(40.3, 29.0, 18.9),
            40 => array(50.3, 36.0, 22.9),
        );
        foreach ($packs as $code => $limits) {
            rsort($limits, SORT_NUMERIC);
            if ($dimensions[0] <= $limits[0] && $dimensions[1] <= $limits[1] && $dimensions[2] <= $limits[2]) {
                return $code;
            }
        }

        return 0;
    }

    private function error(string $code, string $message): array
    {
        return array('ok' => false, 'code' => $code, 'message' => $message);
    }
}
