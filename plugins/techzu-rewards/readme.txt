=== Techzu Rewards for WooCommerce ===
Contributors: techzu
Tags: woocommerce, rewards, loyalty, points, vouchers, membership, birthday, elementor
Requires at least: 6.5
Requires PHP: 7.4
Stable tag: 2.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Complete WooCommerce loyalty programme with points, fixed reward vouchers, membership tiers, birthday perks, point expiry, customer controls, Elementor support, and a built-in Guide app.

== Description ==

Techzu Rewards for WooCommerce implements the full Elegant Bliss Rewards style programme shown in the client screenshots.

Core features:

* Earn 1 Bliss Point per S$1 or any configurable points-per-currency rule.
* Points are based on final paid eligible product amount, excluding delivery fees and refunded/cancelled items.
* Fixed redemption tiers, editable from admin. Defaults: 150 points = S$5 off, 300 points = S$10 off, 450 points = S$15 off.
* Membership tiers with rolling spend qualification. Defaults: Bronze Bliss, Silver Bliss and Gold Bliss.
* Birthday discounts by tier. Defaults: Bronze 5%, Silver 10%, Gold 15%.
* Birthday discount month validation, minimum spend, one-use-per-birthday-month tracking, and optional auto-apply.
* One reward/discount/coupon per order non-stacking control.
* 12-month point expiry with point lots, FIFO redemption, and customer-visible expiry table.
* Refund/cancel/fail handling for earned points, redeemed points, and birthday usage.
* My Account Rewards tab with balance, tier progress, birthday perk, vouchers, point expiry, and history.
* Admin customer control app for exact point balance, birthday, manual tier override, and recent history.
* Public programme page shortcode matching the screenshot layout.
* Elementor support through shortcodes and a native Techzu Rewards widget when Elementor is active.
* WooCommerce HPOS compatibility declaration.
* Built-in Guide admin page and docs folder.

== Installation ==

1. Make sure WooCommerce is installed and active.
2. Upload the plugin zip through Plugins > Add New > Upload Plugin.
3. Activate Techzu Rewards for WooCommerce.
4. Go to Techzu Rewards > Settings.
5. Review the default Elegant Bliss rules and edit any labels, tiers, FAQs, terms, colours or visibility options.
6. Add `[tz_rewards_program]` to a WordPress page or Elementor page to show the public programme page.

== Shortcodes ==

* `[tz_rewards_program]` - Public rewards programme page.
* `[tz_rewards_dashboard]` - Logged-in customer rewards dashboard.
* `[tz_rewards_balance]` - Simple balance text.
* `[tz_rewards_checkout_controls]` - Reward voucher and birthday discount controls for custom/Elementor cart or checkout layouts.

== Admin Guide ==

Open Techzu Rewards > Guide for shipped documentation inside WordPress. A markdown copy is also included in `/docs/guide.md`.

== Data and uninstall ==

Customer reward data is preserved on uninstall by default. To remove data, define `TECHZU_REWARDS_REMOVE_DATA` as `true` before uninstalling.

== Hooks ==

Actions:

* `tz_rewards_redemption_applied`
* `tz_rewards_points_redeemed`
* `tz_rewards_points_restored`
* `tz_rewards_points_awarded`
* `tz_rewards_points_reversed`

Filters:

* `tz_rewards_calculated_points`
* `tz_rewards_available_redemptions`
* `tz_rewards_eligible_subtotal`
* `tz_rewards_cart_eligible_product_total`
* `tz_rewards_customer_tier_spend`
* `tz_rewards_tier_order_eligible_total`
* `tz_rewards_refund_eligible_total`

== Changelog ==

= 2.1.0 =
* Added checkout controls shortcode and native Elementor checkout-control view.
* Updated shipped guide documentation.

= 2.0.0 =
* Added membership tiers, birthday discounts, point expiry lots, My Account Rewards endpoint, customer admin controls, public programme page, Guide app, Elementor widget, non-stacking rules, and expanded refund handling.

= 1.0.0 =
* Initial modular release.
