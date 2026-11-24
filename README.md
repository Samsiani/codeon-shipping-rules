# CodeOn Shipping Rules for WooCommerce

Free GPL table-rate shipping in the CodeOn family. [Download the latest install ZIP](https://github.com/Samsiani/codeon-shipping-rules/releases/latest). See [the plugin readme](readme.txt) for installation and the merchant workflow.

## Build

Run `composer install --no-dev --optimize-autoloader` and `python3 tools/package.py dist` from the source directory. The install ZIP includes production dependencies. The separately generated source ZIP includes the patched GPL framework snapshot and the Composer lockfile. The framework patch adds a hub manifest preference for loading the commercial catalog, preserving the existing default for other CodeOn products.

Run `php tests/Unit/run.php` for engine assertions. Use `php -l` and `node --check` to verify PHP and JavaScript syntax. Run `wp eval-file tests/Integration/rest-lifecycle.php --user=<sandbox-admin>` against a local or development WordPress site with WooCommerce and this plugin active. The fixture creates and removes its own shipping zone and methods.

## Configuration

- Configure and publish a native method against its WooCommerce zone instance. The zone’s native enabled flag controls storefront availability.
- Use the Simulator before publishing. Scenario values use the shop display precision and the current shop weight and dimension units. The simulator shows the shipping price before WooCommerce performs the destination-specific shipping tax handoff.
- JSON carries the complete configuration. CSV carries individual tier rows with the documented column headers below. The spreadsheet representation omits method-level settings and refuses multi-condition and surcharge rules.
- Maintain separate price rules by checkout currency where tariffs differ. A supported currency switcher may supply the current WooCommerce currency and amounts.

### Tier CSV

Required columns are `title` and `base`. Optional columns are `label`, `condition`, `operator`, `min`, `max`, `min_exclusive`, `max_exclusive`, `values`, `field`, `action`, and `scope`. Use `|` between selection values and `1` for an exclusive boundary.

```csv
title,label,condition,min,max,base,action
Standard courier,Up to 2 kg,weight,0,2,7,stop
Standard courier,Up to 10 kg,weight,2.001,10,12,stop
```

## Integration

- `codeon_shipping_rules_destination` can supply normalized destination values for the current WooCommerce package.
- `codeon_shipping_rules_product_facts` can supply catalog facts to the evaluator. Use values that are safe to use for pricing. Keep custom values consistent between the simulator and the cart.
- `codeon_shipping_rules_supported_rate_methods` can add a WooCommerce shipping method ID to the provider bridge. Verify currency, rate ID, tax, expiry and order metadata handling with the specific provider.
- `codeon_shipping_rules_text` can localize merchant-entered shipping titles, descriptions, free labels and delivery time. Rule matching and rate identifiers stay independent of translated labels.

Native rate calculation uses WooCommerce and the immutable published configuration. It does not make an HTTP request. CodeOn Core can enrich matching Georgian destinations when the shipping package has a resolvable location. A courier integration uses rates and fulfils shipments through the installed provider’s own plugin.

## Data retention

Deactivation preserves published rules, drafts, and history. Uninstallation also retains settings by default. To deliberately clear CodeOn Shipping Rules data on uninstall, set `CODEON_SHIPPING_RULES_DELETE_DATA` to `true` in `wp-config.php` before deleting the plugin. Export anything you want to keep first.
