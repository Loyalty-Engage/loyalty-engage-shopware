# Loyalty Engage 2.7.0

## New Features

- Native loyalty shop with Shopware product-listing presentation, points prices,
  eligibility checks, stock validation and reusable CMS placement.
- Loyalty Engage NDJSON/JSON assortment source, channel-isolated cache, explicit
  refresh command and presentation for vouchers without a Shopware product.
- Protected outgoing product feed when Shopware manages the assortment; optional
  tiers and configurable organization currency.
- Frontend personalizations: per-channel tenant and multiple rules, automatic
  product/cart/checkout/selected-CMS placement and a reusable manual CMS element.
- Official external and inline loader snippets normalize to a tenant UUID. The
  integration generates the official loader URL and never stores executable scripts.
- Private session-based customer identifiers and optional product/cart value,
  currency conversion, dynamic refresh, per-target deduplication and safe debug logs.
- Optional SRS voucher creation through the production bridge using the sales-channel
  Loyalty Engage Basic Auth credentials, with a required SRS tenant.

## Fixes And Safety

- Explicit Twig injection for the native catalog controller on Shopware 6.5.
- Frontend context is fetched separately with private/no-store headers; customer
  identifiers and secrets are not inserted into shared personalization bootstrap HTML.
- Invalid personalization settings return HTTP 400 rather than a server error.
- Replaced targets and duplicate placements retain separate identities; stale
  context requests are cancelled and independent public rules survive context failure.
- Shopware 6.5/6.7 administration selector bindings are supported.

## Upgrade Notes

- Existing integrations remain unchanged unless the optional new features are enabled.
- Run plugin migrations, clear caches, rebuild administration/storefront assets and
  compile the active theme using the target Shopware installation's build tools.
- Configure frontend Allowed domains in Loyalty Engage, including each test domain.
- Select organization currency before using cartValue. Price basis follows the
  existing purchase export: products after promotion discounts, no shipping, and
  no additional VAT subtraction. On product pages the selected unit/tier price is used.
- Use `replace content` in Loyalty Engage when personalizations update their context.
- A Loyalty Engage source feed does not create missing physical Shopware products.
- No automatic migration from Premsoft or other third-party plugins is included.
- PHP runtime security updates are a hosting responsibility, not part of this plugin.

See FRONTEND-PERSONALIZATIONS.md, LOYALTY-SHOP.md and SRS-VOUCHERS.md for configuration
and the current verification checklist. Publish only after the outstanding checks
and the required code-owner approval have been completed.
