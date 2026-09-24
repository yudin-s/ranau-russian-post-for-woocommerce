# Contributing

Bug reports and focused pull requests are welcome.

Before opening a pull request:

1. Reproduce the issue on a supported WordPress, WooCommerce, and PHP version.
2. Keep carrier state, Store API namespaces, options, sessions, and order metadata isolated to this plugin.
3. Do not add telemetry, a required Ranau service, store-specific identifiers, credentials, or fulfillment operations outside the documented plugin scope.
4. Add or update contract tests for behavior changes.
5. Run PHP syntax checks, the tests under `tests/`, and WordPress Plugin Check.
6. Update `readme.txt` and the version only when preparing an actual release.

By contributing code, you agree to license the contribution under GPL-2.0-or-later.
