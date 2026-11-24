=== CodeOn Shipping Rules for WooCommerce ===
Contributors: samsiani
Tags: woocommerce, shipping, table rates, delivery, weight
Requires at least: 6.8
Tested up to: 7.1
Requires PHP: 8.1
Requires Plugins: woocommerce
Stable tag: 1.0.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Create table-rate shipping methods, test real examples, and publish changes with a revision history.

== Description ==

CodeOn Shipping Rules calculates and displays shipping options with local, merchant-defined rules. The plugin is free and works with WooCommerce on its own.

* Set flat, value-based, weight-based, and product-specific rates inside WooCommerce shipping zones.
* Match quantity, lines, stock, shipping class, dimensions, customer role, destination and schedule.
* Combine conditions, fixed rates, surcharges, percentages, minimums, maximums, stop and hide actions.
* Offer free shipping by amount, item quantity, valid WooCommerce coupon, or both. Show progress in classic checkout or with the Free Shipping Progress block.
* Simulate a rate with an example cart and see which rules contributed to the quote.
* Save a draft, publish after reviewing it, and roll back to a retained revision.
* Transfer full configurations with JSON and simple pricing tiers with CSV. Preview Flexible Shipping imports before creating any method.
* Adjust eligible Flat Rate and QuickShipper rates. Live quotes and shipment services are supplied by the installed provider.
* Use classic or block checkout and WooCommerce High-Performance Order Storage.

== Installation ==

1. Install and activate WooCommerce.
2. Upload the CodeOn Shipping Rules ZIP in Plugins → Add New Plugin → Upload Plugin.
3. Open CodeOn → Shipping Rules. Choose an existing WooCommerce shipping zone and add a method.
4. Add at least one rule, save as a draft and run the simulator.
5. Publish the draft, then enable the method. Verify the result using a destination in that zone.

== Frequently Asked Questions ==

= Do I need a CodeOn account? =

Install and use the whole plugin directly. The native quote evaluates locally using WooCommerce data.

= Can I apply rules to courier rates? =

Yes. Open a supported Flat Rate or installed QuickShipper instance from the methods list and publish an adjustment. Verify a representative quote before using it on a live store. The rate provider supplies the original quote and remains responsible for provider billing, fallbacks and fulfilment.

= How does free-shipping progress work in Checkout Blocks? =

Insert the Free Shipping Progress block in the Cart or Checkout page. It reads eligible rate progress from the WooCommerce Store API. You can also use [codeon_shipping_progress] in a compatible layout.

= How do I transfer shipping rules? =

Export JSON for a full configuration. Use CSV for simple individual tiers with one condition per row. Select Flexible Shipping in Tools to inspect a reference export and review unresolved conditions or product references.

= What happens to settings when I uninstall? =

Rules and revisions are retained so you can recover the configuration by reinstalling. Export your settings before removing them permanently.

== Changelog ==

= 1.0.1 =
* First public GitHub release.

= 1.0.0 =
* Initial release of the native WooCommerce method, rule editor, simulator, revision tools, imports and free shipping progress block.
