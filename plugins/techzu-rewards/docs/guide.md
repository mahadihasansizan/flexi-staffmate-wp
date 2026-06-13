# elegantbliss Rewards System Guide

This plugin implements the Elegant Bliss rewards programme for WooCommerce and Elementor.

## Core client rules

- Customers earn **1 Bliss Point for every S$1 spent** on eligible paid product value.
- Points are calculated from the final paid eligible product amount after product-level discounts. Shipping, cancelled orders, refunded items and non-eligible items do not earn points.
- Points expire after the configured expiry period. Default: **12 months** from the date earned.
- Reward vouchers default to a continuous conversion: **150 Bliss Points = S$5 off**. The same conversion continues automatically, so 300 points = S$10, 450 points = S$15, 600 points = S$20, and so on.
- The public programme page can still show fixed display rows such as 150/300/450 points. Admins can add, edit, disable or delete those rows.
- Only one reward voucher, birthday discount, WooCommerce coupon, sale discount or promotional code can be used per order when non-stacking is enabled.

## Membership tiers

Default tiers:

| Tier | Qualification | Birthday perk |
| --- | --- | --- |
| Bronze Bliss | Customer account created | 5% birthday discount |
| Silver Bliss | Spend S$300 within 12 months | 10% birthday discount |
| Gold Bliss | Spend S$600 within 12 months | 15% birthday discount |

The tier system is automatic. A new customer is assigned the first/default tier when their account is created. After each qualifying order or refund, the plugin recalculates the customer spend inside the rolling month window and updates the tier when needed. Admins can also manually override a customer's tier.

## Birthday discounts

Customers can save their birthday in **My Account > Account details**. During the birthday month, the customer can apply the tier birthday discount at checkout if the order meets the configured minimum eligible spend. The default minimum is S$60.

Birthday discount settings are editable from **ElegantBliss Rewards > Settings**.

## Customer emails

The plugin can send customer emails for these reward events:

- account creation / Bronze assignment
- points earned
- points used
- membership tier updated
- manual admin balance adjustment
- points expiring soon
- points expired

Every email type can be enabled or disabled. Email subjects, intro text, footer text and reminder days are editable from **ElegantBliss Rewards > Settings > Customer email notifications**.

Available merge tags:

`{site_name}`, `{first_name}`, `{display_name}`, `{points}`, `{points_label}`, `{balance}`, `{tier}`, `{old_tier}`, `{order_number}`, `{expiry_days}`

## Admin controls

Go to **ElegantBliss Rewards > Customers** to search customers and control:

- exact point balance
- birthday date
- manual tier override
- recent point history

The same controls are also available on the WordPress user profile screen for admins with WooCommerce management capability.

## My Account rewards dashboard

Customers see a **Rewards** tab inside WooCommerce My Account. It includes:

- current Bliss Points balance
- current membership tier
- how to earn and use points
- tier progress toward the next tier
- birthday perk status
- available reward vouchers
- point expiry table
- reward history

## Public display and Elementor

Shortcodes:

- `[tz_rewards_program]` - full public rewards programme page matching the client screenshot style.
- `[tz_rewards_dashboard]` - logged-in customer dashboard.
- `[tz_rewards_balance]` - simple balance output.
- `[tz_rewards_checkout_controls]` - reward and birthday controls for custom checkout layouts.

Elementor support:

- Use Elementor's shortcode widget with any shortcode above.
- When Elementor is active, the plugin also registers an **ElegantBliss Rewards** widget.

## WooCommerce order lifecycle

- Points are awarded when the order reaches the configured earning statuses. Default: Processing and Completed.
- Earned points are reversed if the order is cancelled, failed or refunded.
- Partial refunds reverse the corresponding earned points.
- Redeemed points are deducted during checkout order creation.
- Redeemed points are restored if the order is cancelled, failed or refunded.

## Developer hooks

Useful filters and actions:

- `tz_rewards_calculated_points`
- `tz_rewards_available_redemptions`
- `tz_rewards_eligible_subtotal`
- `tz_rewards_customer_tier_spend`
- `tz_rewards_cart_eligible_product_total`
- `tz_rewards_points_awarded`
- `tz_rewards_points_redeemed`
- `tz_rewards_points_expired`
- `tz_rewards_customer_tier_assigned`
- `tz_rewards_customer_tier_updated`
- `tz_rewards_points_manually_adjusted`
