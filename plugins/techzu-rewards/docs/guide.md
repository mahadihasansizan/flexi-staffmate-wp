# Techzu Rewards Guide

## Overview

Techzu Rewards for WooCommerce is a complete loyalty programme plugin for WooCommerce stores. It was built to match the Elegant Bliss Rewards screenshots: a public programme page, fixed rewards, membership tiers, birthday treats, FAQs, and strict one-reward-per-order logic.

## Default Elegant Bliss Rules

- 1 Bliss Point for every S$1 spent on eligible products.
- 150 Bliss Points unlock S$5 off.
- 300 Bliss Points unlock S$10 off.
- 450 Bliss Points unlock S$15 off.
- Bronze Bliss: account creation, 5% birthday discount.
- Silver Bliss: S$300 eligible spend within 12 months, 10% birthday discount.
- Gold Bliss: S$600 eligible spend within 12 months, 15% birthday discount.
- Birthday discounts require a minimum eligible spend of S$60 by default.
- Points expire 12 months after the earning date by default.
- Only one reward, birthday discount, voucher or promotional code may be used per order by default.

All of these values are editable from Techzu Rewards > Settings.

## Admin Areas

### Settings

Controls every rule and visual setting:

- Programme enabled/disabled.
- Point labels.
- Points per currency unit.
- Minimum spend to earn.
- Expiry months.
- Order statuses that award points.
- Refund/cancel behaviour.
- Non-stacking rule.
- Reward voucher tiers.
- Membership tiers.
- Birthday percentage discounts.
- Public programme page text.
- FAQ items.
- Terms text.
- Colours and display options.

### Customers

Lets admins control customer rewards directly:

- Search users by name or email.
- Set exact point balance.
- Set customer birthday.
- Force a manual tier override or leave tier automatic.
- View recent point history.

The same fields are also available on WordPress user profile screens for WooCommerce managers.

### Guide

Contains the built-in documentation inside WordPress.

## Customer Experience

### My Account

The plugin adds a Rewards tab to WooCommerce My Account. Customers can see:

- Current balance.
- Current membership tier.
- Tier progress.
- Birthday discount status.
- Available reward vouchers.
- Active point lots and expiry dates.
- Reward history.

A birthday field is added to Account details so customers can unlock birthday-month discounts.

### Cart and Checkout

Logged-in customers see:

- Reward voucher panel.
- Birthday perk panel.
- Balance and available vouchers.
- Apply/remove buttons.

When non-stacking is enabled, coupons cannot be combined with reward vouchers or birthday discounts.

## Elementor

Use any Elementor shortcode widget with:

- `[tz_rewards_program]`
- `[tz_rewards_dashboard]`
- `[tz_rewards_balance]`
- `[tz_rewards_checkout_controls]`

When Elementor is active, the plugin also registers a native Techzu Rewards widget with selectable programme, dashboard, balance and checkout-control views.

## WooCommerce Logic

### Points Earning

Points are awarded only when orders reach selected statuses. The eligible total uses WooCommerce product line totals after product discounts. Shipping is excluded. Negative reward/discount fees can be subtracted when enabled.

### Point Expiry

Each earning event creates a point lot with its own expiry date. Redemptions consume the oldest active lots first. The daily maintenance event expires old points, and balances are also refreshed whenever a user balance is loaded.

### Redemption

Reward vouchers are stored in the WooCommerce session and applied as negative cart fees. Points are deducted when checkout creates the order. If the order becomes failed, cancelled or refunded, redeemed points are restored once.

### Refunds and Cancellations

When an earning order becomes cancelled, failed or refunded, remaining earned points are reversed. Partial refunds trigger a refund adjustment based on refunded product lines.

### Birthday Discounts

Birthday discounts are available only during the customer's birthday month. Usage is recorded per user and month. If the related order is failed, cancelled or refunded, usage is restored when the usage marker belongs to that order.

## Testing Checklist

1. Activate WooCommerce and this plugin.
2. Go to Techzu Rewards > Settings and save defaults once.
3. Add `[tz_rewards_program]` to a page and confirm the public page matches the screenshot sections.
4. Create a customer with a birthday in the current month.
5. Place an order and move it to Processing or Completed.
6. Confirm points appear in My Account > Rewards.
7. Confirm point expiry lots and history are visible.
8. Add enough points to unlock a reward tier.
9. Apply a reward voucher at checkout.
10. Try applying a coupon while a reward is active and confirm it is blocked.
11. Remove the reward and apply the birthday discount if eligible.
12. Cancel or refund an order and confirm points/usage are adjusted.


## Shortcode Reference

`[tz_rewards_program]` renders the full public programme page shown in the screenshot.

`[tz_rewards_dashboard]` renders the logged-in customer dashboard that also appears under My Account > Rewards.

`[tz_rewards_balance]` renders only the current user balance.

`[tz_rewards_checkout_controls]` renders the reward voucher and birthday discount controls without a table wrapper. Use this inside Elementor or custom checkout/cart layouts when you do not want to rely on the classic WooCommerce totals-table hooks.
