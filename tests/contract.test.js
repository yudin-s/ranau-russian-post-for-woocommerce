#!/usr/bin/env node
'use strict';

const assert = require('assert');
const fs = require('fs');
const path = require('path');

const root = path.resolve(__dirname, '..');
const php = fs.readFileSync(path.join(root, 'includes', 'class-plugin.php'), 'utf8');
const browser = fs.readFileSync(path.join(root, 'assets', 'js', 'russian-post.js'), 'utf8');
const runtime = fs.readFileSync(path.join(root, 'assets', 'js', 'delivery-runtime.js'), 'utf8');

assert(!/glow[\s_-]?me|goflow/i.test(php + browser), 'Legacy product identifiers must not ship.');
assert(php.includes('ProviderStateStore'), 'The provider-local server state store must be integrated.');
assert(php.includes('woocommerce_store_api_register_update_callback'), 'A provider-owned Store API callback is required.');
assert(browser.includes('createDeliveryStateMachine'), 'The browser adapter must instantiate its local state machine.');
assert(browser.includes('createCheckoutBridge'), 'The browser adapter must own one checkout bridge.');
assert(browser.includes("config.restUrl + '/commit'"), 'Classic checkout must commit server-owned state before refresh.');
assert(runtime.includes('RanauRussianPostDeliveryRuntimeV1'), 'The runtime global must be provider-specific.');

process.stdout.write('Ranau Russian Post contract checks passed.\n');
