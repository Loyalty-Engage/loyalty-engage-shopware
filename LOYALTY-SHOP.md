# Native loyalty shop (feature branch)

This feature adds a Shopware-managed reward catalogue, a Channable NDJSON feed,
a Shopping Experiences CMS block, and a standalone `/loyaltyshop` page. It is
disabled by default. Existing SRS work is included in this branch; see
`SRS-VOUCHERS.md` for the bridge creation flow.

## Configure

1. Run the plugin migrations after installing/updating the branch:
   `bin/console database:migrate LoyaltyEngage --all`.
2. Open the plugin configuration for the relevant sales channel. Enable the native
   shop, set the points name, regular product minimum, reward quantity limit and
   promotion combination policy. Channel settings override globals, including
   an explicitly disabled switch.
3. Generate a random feed token of at least 32 characters. Set the feed currency
   to the default currency of the Loyalty Engage organization. Leaving currency
   empty uses the sales channel default, independently of visitor cookies.
4. Enable tiers only when needed. Configure one `ID|name` per line, lowest tier
   first. IDs are organization-specific and their numeric order is not used as
   a rank. Set each product's minimum tier ID when tiers are enabled.
5. In the product's custom fields, open **Loyalty shop**. Enable it as a reward,
   enter a positive integer coin price and select `physical` or `discount_code`.
   Use leaf variants, assigned and active in the appropriate sales channel.
   For vouchers also set a positive fallback discount value and its type. This
   value is read on the server, never from the browser. API discount values take
   precedence. Physical rewards must be purchasable individually.
6. **Also allow regular purchases** controls whether the product can be ordered
   with money too. If explicitly false, a regular cart line blocks checkout.
   An unset field retains normal purchasing for backwards compatibility.

The generic Shopware custom-field editor displays all fields; tier values are
only enforced/exported when the tier switch is enabled.

## Feed

### Use Loyalty Engage as the assortment source

Select **Loyalty Engage JSON / NDJSON feed** under **Loyalty assortment source**
and enter the organization's private export URL under **Loyalty Engage assortment
feed URL**. It must use HTTPS on `app.loyaltyengage.tech/feed/v1/`. Redirects and
credentials embedded in URLs are rejected. The URL is never exposed in storefront
HTML or logged in transport errors. No Basic Auth credentials are sent to this
read-only source; the separate bridge voucher-creation authentication is unchanged.

The plugin accepts NDJSON, a JSON array or a JSON object containing a `products`
array. Each record needs `sku`, `type` and a positive integer `coin_price`.
Vouchers need either `discount_percentage` or `discount_amount` plus an ISO currency.
Duplicate conflicting SKUs or malformed rows reject the entire refresh. Downloads
are limited to 8 MB, 20,000 rows and 20 seconds.

Feed membership, reward type, point prices and discount values override product
custom fields in this mode. Physical rewards must match active leaf products
assigned to that sales channel by SKU; unmatched physical SKUs stay hidden.
Shopware provides their titles, media and stock. Existing normal-purchase custom
field restrictions only apply to the Shopware-managed assortment mode.

Vouchers without a matching product get a virtual PLP card. Their default title
is derived from the discount amount, and they have no broken product-detail link.
**Presentation for vouchers without a Shopware product** accepts optional JSON:

```json
{"DISCOUNT_PER_10":{"title":"10% korting","image":"https://shop.example/voucher.jpg"}}
```

The optional `description` key is also supported. Feed `title`, `short_description`
and `image_link` can supply defaults. Virtual vouchers are omitted from a
category-restricted CMS element, since they have no Shopware category assignment.
Fixed-currency vouchers cannot be claimed in a different storefront currency.

The example export contains no tier information. Local tier validation only runs
when the source provides `minimum_required_tier_id`; otherwise Loyalty Engage
checks tier eligibility during the real reserve/purchase request. The plugin does
not infer missing tiers from local product custom fields.

Snapshots are cached per channel and URL, by default for 300 seconds (configurable
60-3600). A subsequent visit refreshes an expired snapshot; no separate cron job
is required. CMS pages with this element are not stored in the shared HTTP cache,
so it cannot mask feed changes beyond that duration. A missing, invalid or expired
unreachable feed disables listing and redemption until a valid snapshot is loaded.
There is no fallback to the local assortment or to an expired snapshot. Existing
physical reward carts also block checkout when their feed SKU disappears or the
source cannot be validated. An explicit refresh is available through:

```sh
bin/console loyalty:shop:refresh-feed SALES_CHANNEL_ID
```

The outgoing Shopware product feed returns HTTP 409 in this mode, preventing a
circular assortment synchronization. Switch back to **Shopware product selection**
to resume the original product-custom-field and outgoing-feed workflow.

### Use Shopware as the assortment source

Use a separate product source for each organization's sales channel:

`https://SHOP-DOMAIN/loyaltyshop/feed.ndjson?token=YOUR_RANDOM_TOKEN`

Treat the URL as a secret; do not embed it on a public page. Invalid/missing
tokens or a disabled native shop return HTTP 403 with no cached feed response.
Currency is fixed by plugin configuration. Each configured sales-channel language
produces a row for the same SKU, with only title, description and language varying.
No customer information or API credentials are included.

Rows contain `id`, `title`, `price`, `short_description`, `image_link`, `language`,
`type`, `coin_price` and `minimum_required_tier_id`. Prices are gross catalogue
prices, not customer-specific or graduated prices. Invalid SKUs, missing names,
invalid point prices, missing configured tiers and parent variant containers are
excluded and logged with a reason. Inventory never filters this feed.

With tiers disabled, `minimum_required_tier_id` is null. The supplied Loyalty
Engage documentation states that a minimum tier may be required for purchasing;
confirm the organization's no-tier import behavior before real redemption. This
feature does not invent a universal tier ID.

Products deselected in Shopware disappear from the feed and are deleted by
Loyalty Engage after seven days. They cannot be newly redeemed through the
native shop during that waiting period. A reward already in the cart blocks
checkout if no longer selected, active or assigned to that channel.

## Pages

Build administration and storefront assets using Shopware's build scripts, then
compile the theme. In Shopping Experiences, select the **Loyalty shop** block
from the commerce category. Optionally restrict it to a product category and
combine it with normal text/image/banner blocks. Assign the layout to a landing
page or category. Existing SEO URLs such as `/petpuntenwinkel/` can be preserved
through the customer's category/landing-page setup.

The standalone `/loyaltyshop` route is available immediately for testing.
It shows 24 rewards per page, name/points sorting, points prices and stock-based
availability. The listing inherits Shopware's standard PLP and product-card
templates so the active storefront theme's compatible card/grid overrides apply.
Only price and purchase action are replaced with points and redemption controls;
pagination and sorting retain the loyalty endpoints instead of the category AJAX
filter API. Theme-specific replacements of other card layouts and category
sidebars/filters are not automatically copied. Both versions load login
state and synchronized coin balance from a private, no-store endpoint; personal
balances are not included in shared CMS HTML. The existing customer-data API
updates `le_available_coins` and `le_current_tier`. This is not a new live balance
API: Loyalty Engage remains authoritative at reservation/purchase time.

## Cart safeguards

The native flow derives reward eligibility, product type and discount value on
the server. A per-customer/channel database lock serializes native claims.
Request IDs persist results so an uncertain/repeated request does not spend
points twice. One physical reward per SKU can be present at a time.

Physical lines carry a signature bound to cart, customer, sales channel, SKU,
product, quantity and reservation. The cart processor only makes authorized
native rewards free. The cart validator blocks changed/deselected products,
invalid/revoked reservations, invalid quantities, insufficient regular subtotal,
exceeded limits and forbidden promotion combinations. Shopware still handles
normal product stock and checkout validation. Native removal releases only that
SKU's reservation; release failures block removal. A failed cart add attempts a
matching reservation rollback. Order placement revokes the cart authorization
and retains the existing asynchronous Loyalty Engage purchase integration.

An API timeout can have an unknown remote outcome. Requests left without a
completed response are deliberately not automatically repeated; reconcile them
before starting a new claim. Abandoned-cart expiry and the wider existing
purchase/return retry behavior still need production acceptance testing.

## Local test environment

The existing `shopware` Docker container is used. No real Loyalty Engage source
or credentials are overwritten and test execution does not contact Loyalty Engage.

Create repeatable local-only fixtures:

```sh
bin/console loyalty:shop:demo SALES_CHANNEL_ID --confirm-local
```

This creates three `LE-DEMO-*` products (one out of stock), a reusable CMS layout
and a landing page. It enables the native shop for that channel, resets the
demo conditions and generates a feed token if needed. Do not run it on a live
shop. Fixture SKUs include a channel suffix. Products have no external loyalty
counterpart until a test product source is configured.

Run isolated tests:

```sh
php tests/loyalty-shop-smoke.php /path/to/shopware/vendor/autoload.php
php tests/srs-voucher-smoke.php /path/to/shopware/vendor/autoload.php
php tests/loyalty-feed-smoke.php /path/to/shopware/vendor/autoload.php
```

Run the real-cart integration checks only in a local database with the demo
fixtures and at least one active, non-guest test customer:

```sh
php tests/loyalty-shop-integration.php /path/to/shopware SALES_CHANNEL_ID
```

This uses a fresh cart context with a mocked HTTP client, validates request replay,
free pricing, stock and catalogue restrictions, multilingual feed consistency and
revoked authorization. It leaves no spendable mocked reservation. It never places
an order. Existing customer data is read for context, not changed.

The native integration test temporarily selects the Shopware assortment and
restores the previous source on exit. With the supplied sample source URL and a
local `24-MB01` product available, run both native and feed-mode checks through:

```sh
php tests/loyalty-feed-integration.php /path/to/shopware SALES_CHANNEL_ID
```

Feed-mode checks mock voucher and reservation actions, verify both discount
types, accept a matched physical reward and reject removed/incorrect-type SKUs.
Native cart validation may perform a read-only source GET; no real reward is claimed.

## Before replacing Premsoft

This branch does not migrate an unknown Premsoft schema. Inventory and trial
migration of balances, expiry, reservations, open orders, reward mappings and
codes require a customer export/schema. Verify return/cancellation and app/store
API consumers with that customer's setup. Use one points writer at cutover and
keep a database/configuration backup and rollback procedure. Validate a real test
Loyalty Engage import and reward purchase before disabling Premsoft.
