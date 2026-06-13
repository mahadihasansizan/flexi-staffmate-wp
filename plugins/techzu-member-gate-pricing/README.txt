=== Techzu Member Gate Pricing ===
Contributors: techzu
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
License: GPLv2 or later
Tags: membership, login gate, woocommerce, pricing, discounts

Techzu Member Gate Pricing adds a frontend login gate and membership pricing rules for WooCommerce.

== Features ==
* Creates a Member Login page on activation with shortcode [tmgmp_login_form].
* Redirects logged-out visitors to the login page before entering the website.
* Admin can create unlimited membership levels.
* Each global level can use percentage-off, fixed-amount-off, no-discount, or fixed-final-price pricing.
* Admin can assign a membership level to each WordPress user.
* Admin can set user-specific pricing overrides.
* Admin can set product-specific membership pricing rules per product and per membership level.
* Product-specific rules override user-specific rules; user-specific rules override global level rules.
* Supports simple products and variations using WooCommerce price and cart hooks.

== Installation ==
1. Upload the ZIP in WordPress Admin > Plugins > Add New > Upload Plugin.
2. Activate the plugin.
3. Go to Settings > Member Gate Pricing.
4. Configure login gate behavior and membership levels.
5. Edit users to assign membership levels.
6. Edit WooCommerce products to add product-specific membership pricing rules.

== Pricing Rule Priority ==
1. Product-specific rule for the user's membership level.
2. User-specific pricing override.
3. Global membership level rule.

== Notes ==
WooCommerce must be active for pricing features. The login gate works without WooCommerce.
