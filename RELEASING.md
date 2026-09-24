# Release and WordPress.org submission

This repository is the public source of the plugin. The WordPress.org release artifact is a generated ZIP that excludes source-only CI, tests, and contributor documentation through `.distignore`.

## Preflight

1. Confirm that `Version` in the main plugin file and `Stable tag` in `readme.txt` are identical.
2. Run PHP syntax checks and every test in `tests/`.
3. Run the official WordPress Plugin Check action or the Plugin Check CLI against the staged release directory.
4. Inspect `readme.txt`, especially external-service, privacy, installation, support, and trademark disclosures.
5. Confirm that the package contains no credentials, store-specific values, legacy product identifiers, development fixtures, or fulfillment behavior outside the documented scope.
6. Test activation, Checkout Blocks, classic checkout, HPOS, cart totals, checkout validation, and order metadata on a clean WooCommerce site.

## Build

From this repository root:

```sh
wp package install wp-cli/dist-archive-command:@stable
slug="$(basename "$PWD")"
version="$(sed -n 's/^[[:space:]*]*Version:[[:space:]]*\([^[:space:]]*\).*$/\1/p' "$slug.php" | head -n 1)"
wp dist-archive . "./$slug-$version.zip"
unzip -t "./$slug-$version.zip"
shasum -a 256 "./$slug-$version.zip"
```

Pushing a signed or annotated `v<version>` tag also runs the release workflow and publishes the same source-filtered ZIP to GitHub Releases.

## First WordPress.org submission

1. Sign in at https://wordpress.org/plugins/developers/add/.
2. Submit the generated ZIP and use the repository URL as the source URL.
3. Answer reviewer questions with the exact data flow documented in `readme.txt`; do not imply that the plugin is official or endorsed by a carrier.
4. After approval, check out the assigned WordPress.org SVN repository.
5. Copy the release contents to `trunk/` and the matching version directory under `tags/`; keep directory artwork under the SVN `assets/` directory, not inside the plugin ZIP.
6. Re-run Plugin Check on the exact SVN-ready tree before committing it.

WordPress.org approval and the final directory slug are controlled by the WordPress.org review team. Do not publish to SVN before approval.
