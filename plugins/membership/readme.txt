=== Membership ===
Contributors: techzu
Tags: membership, roles, login, woocommerce, pricing
Requires at least: 6.3
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 2.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Private login gate, dynamic membership roles, user membership assignment, and WooCommerce member pricing rules.

== Description ==
Membership adds a top-level Membership menu in WordPress admin. It lets administrators create membership levels that automatically become WordPress roles, assign users to those membership roles, protect the frontend behind a login page, and apply WooCommerce pricing per membership level.

Pricing priority:
1. Product-specific membership rule.
2. User-specific pricing override.
3. Global membership level rule.

Pricing rule types:
* No discount
* Percentage off
* Fixed amount off
* Fixed final price

== Installation ==
1. Upload membership.zip from Plugins > Add New > Upload Plugin.
2. Activate Membership.
3. Go to Membership > Levels & Roles and save/synchronize roles.
4. Assign users from Users > Add New or Users > Edit User.
5. Edit WooCommerce products and open the Membership Pricing tab for product-specific rules.

== Shortcodes ==
[membership_login_form]

== Changelog ==
= 2.1.0 =
* Renamed plugin to Membership.
* Added top-level Membership admin menu.
* Added dynamic role synchronization for all enabled membership levels.
* Added add/remove/modify levels and roles.
* Added user membership assignment fields.
* Added user pricing overrides.
* Added WooCommerce product-level membership pricing panel.
* Added login gate and login shortcode.
