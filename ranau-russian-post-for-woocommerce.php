<?php
/**
 * Plugin Name: Ranau Russian Post for WooCommerce
 * Plugin URI:  https://ranau.uk/wordpress/ranau-russian-post-for-woocommerce/
 * Description: Tariff calculation and OPS selection for Russian Post without shipment creation.
 * Version: 0.1.1
 * Requires at least: 6.9
 * Requires PHP: 7.4
 * Author: Ranau
 * Author URI: https://ranau.uk/
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: ranau-russian-post-for-woocommerce
 * Requires Plugins: woocommerce
 * WC requires at least: 8.9
 * WC tested up to: 10.8
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

define('RANAU_RUSSIAN_POST_VERSION', '0.1.1');
define('RANAU_RUSSIAN_POST_FILE', __FILE__);
define('RANAU_RUSSIAN_POST_DIR', plugin_dir_path(__FILE__));
define('RANAU_RUSSIAN_POST_URL', plugin_dir_url(__FILE__));

require_once RANAU_RUSSIAN_POST_DIR . 'includes/class-package-resolver.php';
require_once RANAU_RUSSIAN_POST_DIR . 'includes/class-tariff-client.php';
require_once RANAU_RUSSIAN_POST_DIR . 'includes/class-office-client.php';
require_once RANAU_RUSSIAN_POST_DIR . 'includes/class-address-client.php';
require_once RANAU_RUSSIAN_POST_DIR . 'includes/internal/ProviderStateStore.php';
require_once RANAU_RUSSIAN_POST_DIR . 'includes/class-plugin.php';

add_action('before_woocommerce_init', static function (): void {
    $features = '\\Automattic\\WooCommerce\\Utilities\\FeaturesUtil';
    if (class_exists($features)) {
        $features::declare_compatibility('custom_order_tables', RANAU_RUSSIAN_POST_FILE, true);
        $features::declare_compatibility('cart_checkout_blocks', RANAU_RUSSIAN_POST_FILE, true);
    }
});

add_action('plugins_loaded', static function (): void {
    if (!class_exists('WooCommerce')) {
        return;
    }

    \Ranau\RussianPost\Plugin::instance()->init();
});
