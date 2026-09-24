# Ranau Russian Post for WooCommerce

Independent GPL-licensed WooCommerce shipping plugin for Russian Post office pickup, address delivery, and international tariff calculation. It calculates rates and records the selected delivery data on the WooCommerce order; it never creates carrier shipments.

## Development

```bash
node tests/contract.test.js
php -l ranau-russian-post-for-woocommerce.php
```

The bundled browser and PHP delivery-state runtimes are namespace-isolated copies built from `packages/ranau-delivery-state-machine` in the Ranau development workspace. The published plugin has no runtime dependency on that workspace or on a Ranau service.

Source and issues: https://github.com/yudin-s/ranau-russian-post-for-woocommerce

Optional paid support and custom development: https://ranau.uk/
