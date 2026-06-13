=== elegantbliss Rewards System ===
Contributors: techzu
Tags: woocommerce, rewards, loyalty, points, vouchers, membership, birthday, elementor
Requires at least: 6.5
Requires PHP: 7.4
Stable tag: 2.2.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Complete WooCommerce rewards system for Elegant Bliss with Bliss Points, continuous vouchers, membership tiers, birthday perks, expiry emails, customer controls, Elementor support, and a built-in Guide app.

== Description ==

elegantbliss Rewards System implements the Elegant Bliss rewards programme shown in the client screenshots.

Core features:

* Earn 1 Bliss Point per S$1 spent on eligible paid product amount.
* Points are based on final paid eligible product amount, excluding shipping, cancelled orders, refunded items and non-eligible items.
* Continuous voucher conversion. Default: every 150 Bliss Points = S$5 off, so 300 = S$10, 450 = S$15, 600 = S$20, and so on.
* Optional fixed redemption-tier mode, with editable add/edit/delete display rows.
* Automatic membership tiers with rolling 12-month spend qualification. Defaults: Bronze Bliss, Silver Bliss and Gold Bliss.
* Bronze/default tier assignment when a customer account is created.
* Automatic Silver/Gold tier updates when spend reaches S$300/S$600 within 12 months.
* Birthday discounts by tier. Defaults: Bronze 5%, Silver 10%, Gold 15%.
* Birthday discount month validation, minimum spend, one-use-per-birthday-month tracking, and optional auto-apply.
* One reward/discount/coupon per order non-stacking control.
* 12-month point expiry with point lots, FIFO redemption, customer-visible expiry table, and expiry-soon email reminders.
* Customer emails for account creation, points earned, points used, membership tier update, manual admin balance updates, points expiring soon and points expired.
* Refund/cancel/fail handling for earned points, redeemed points, and birthday usage.
* My Account Rewards tab with balance, how-to-earn guidance, tier progress, birthday perk, vouchers, point expiry, and history.
* Admin customer control app for exact point balance, birthday, manual tier override, and recent history.
* Public programme page shortcode matching the screenshot layout.
* Elementor support through shortcodes and a native ElegantBliss Rewards widget when Elementor is active.
* WooCommerce HPOS compatibility declaration.
* Built-in Guide admin page and docs folder.

== Installation ==

1. Make sure WooCommerce is installed and active.
2. Upload the plugin zip through Plugins > Add New > Upload Plugin.
3. Activate elegantbliss Rewards System.
4. Go to ElegantBliss Rewards > Settings.
5. Review the default Elegant Bliss rules and edit any labels, tiers, FAQs, terms, colours, emails or visibility options.
6. Add `[tz_rewards_program]` to a WordPress page or Elementor page to show the public programme page.

== Shortcodes ==

* `[tz_rewards_program]` - Public rewards programme page.
* `[tz_rewards_dashboard]` - Logged-in customer rewards dashboard.
* `[tz_rewards_balance]` - Simple balance text.
* `[tz_rewards_checkout_controls]` - Reward voucher and birthday discount controls for custom/Elementor cart or checkout layouts.

== Admin Guide ==

Open ElegantBliss Rewards > Guide for shipped documentation inside WordPress. A markdown copy is also included in `/docs/guide.md`.

== Data and uninstall ==

Customer reward data is preserved on uninstall by default. To remove data, define `TECHZU_REWARDS_REMOVE_DATA` as `true` before uninstalling.

== Hooks ==

Actions:

* `tz_rewards_redemption_applied`
* `tz_rewards_points_redeemed`
* `tz_rewards_points_restored`
* `tz_rewards_points_awarded`
* `tz_rewards_points_reversed`
* `tz_rewards_points_expired`
* `tz_rewards_customer_tier_assigned`
* `tz_rewards_customer_tier_updated`
* `tz_rewards_points_manually_adjusted`

Filters:

* `tz_rewards_calculated_points`
* `tz_rewards_available_redemptions`
* `tz_rewards_eligible_subtotal`
* `tz_rewards_cart_eligible_product_total`
* `tz_rewards_customer_tier_spend`
* `tz_rewards_tier_order_eligible_total`
* `tz_rewards_refund_eligible_total`

== Changelog ==

= 2.2.1 =
* Changed reward redemption to create real WooCommerce coupons instead of auto-applied cart fees.
* Generated reward coupons are assigned to the converting customer and restricted to that customer's account/email.
* Checkout reward card is hidden until the customer has enough points to convert or saved reward coupon codes.
* Customers can convert points to saved coupon codes from My Account > Rewards and checkout, then apply codes manually.

= 2.2.0 =
* Renamed plugin display name to elegantbliss Rewards System.
* Added continuous redemption mode: every 150 Bliss Points = S$5 off, continuing for larger balances.
* Added Bronze/default tier assignment on account creation and tier-change syncing for membership emails.
* Added customer notification emails for joining, earning points, using points, tier updates, manual admin point updates, points expiring soon and points expired.
* Added editable email subject, intro and footer settings.
* Added My Account how-to-earn section.
* Updated Guide documentation.

= 2.1.0 =
* Added checkout controls shortcode and native Elementor checkout-control view.
* Updated shipped guide documentation.

= 2.0.0 =
* Added membership tiers, birthday discounts, point expiry lots, My Account Rewards endpoint, customer admin controls, public programme page, Guide app, Elementor widget, non-stacking rules, and expanded refund handling.

= 1.0.0 =
* Initial modular release.
