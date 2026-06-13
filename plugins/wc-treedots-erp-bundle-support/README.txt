WooCommerce TreeDots ERP Connector
=================================

Version: 1.0.0

What it does
------------
- Sends WooCommerce orders to TreeDots POST /orders
- Uses Bearer token only
- Sends automatically on thank-you page
- Adds manual order action: Send to TreeDots ERP
- Adds bulk action on orders list
- Stores request, response, HTTP code, ERP order ID, and last error on the order
- Adds product and variation fields for:
  - TreeDots SKU ID
  - TreeDots Actual SKU ID
  - optional TreeDots bundle options JSON

Settings
--------
WooCommerce -> TreeDots ERP

Required settings:
- Enable integration
- API Base URL
- Bearer token

Recommended settings:
- Delivery date meta key
- Delivery time meta key

If delivery date meta key is empty, plugin uses the order created date.
If delivery time meta key is empty, plugin uses 09:00:00.

Required product mapping
------------------------
Every WooCommerce product or variation must have:
- TreeDots SKU ID
- TreeDots Actual SKU ID

Optional bundle JSON format:
[{"bundleOptionId":123,"skuId":456,"actualSkuId":789,"quantity":1}]

Order fields sent
-----------------
- skuItems
- paymentReferenceNumber
- paymentMethod
- deliveryTime
- deliveryDate
- platform = Ecom
- delivery_postal_code
- delivery_address
- contact_email
- contact_number
- contact_name
- company_name
- optional request flags
- optional stripe URLs if configured

Notes
-----
This plugin does not auto-sync /products. Product mapping is manual.
