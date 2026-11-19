# Loyalty Engage 2.7.2 - Tracking Hotfix

Status: unreleased. Held for the broader reconciliation work requested by the
maintainer. Do not treat temporary build directories as final release ZIPs.

## Fix

- Removed an unintended hardcoded Squeezely tracker and its storefront import.
- Removed the legacy Twig reference to the tracking script. Harmless compatibility
  placeholders overwrite old source/template/public files during ZIP overlay updates.
- The plugin no longer initializes a Squeezely queue or injects a Squeezely pixel.
  A shop's own Squeezely integration is left unchanged.
- Distributable storefront bundles are self-contained and use the framework's
  single plugin entry point, avoiding stale shared runtime module dependencies.
- Intentionally configured Loyalty Engage personalizations remain supported. Their
  loader starts only with valid settings and an enabled matching visible target.

## Delivery Reliability (Release Held)

- A database-backed delivery register preserves cart purchases, Purchase events,
  Return events and voucher-redemption reconciliation before their API call.
- Cart purchases and Purchase events retain the original order ID, sales channel
  and payload on retries. This relies on the confirmed Loyalty Engage guarantee
  that only the first request with the same order ID is processed.
- A database connection lock serializes delivery attempts. Timeout, rate-limit
  and server failures retry with backoff, up to 12 attempts. Successful deliveries
  keep a deduplication record. Calls wait until an outer order transaction commits.
- Return and voucher-redemption outcomes without confirmed remote idempotency
  are retained as uncertain, not automatically replayed. A delivery ID prevents
  duplicate local Return operations. New Return events share the original Purchase
  order number, including separate returned deliveries. This is not a complete
  partial-return redesign.
- The existing five-minute order task also retries due deliveries. Production
  scheduled-task execution and its Messenger worker must be running.
- Purchase messages now preserve their sales-channel ID for credential selection.
- The legacy scheduled order fallback sends only loyalty rewards and their actual
  product numbers, not every regular product's Shopware UUID.

Operator commands:

```sh
bin/console loyalty-engage:deliveries
bin/console loyalty-engage:deliveries --retry-due
bin/console loyalty-engage:deliveries --requeue=DELIVERY_ID --retry-due
```

Requeue is restricted to rejected/exhausted idempotent operations, after fixing
their cause. It cannot replay uncertain returns or voucher redemption. Reconcile
those with provider records first. Failed deliveries are logged without raw
provider bodies or customer email addresses. The database register does contain
the original contact/payload; restrict access and include it in the privacy and
backup policy. Do not purge successful deduplication records during queue replay.

## Additional Safety Fixes

- Guests cannot redeem rewards using another contact's email address.
- Legacy and native free products require a server-side, cart-bound proof before
  their price can become zero. Payload or quantity tampering blocks checkout.
- Signed reward removal releases the specific SKU, not a delayed entire cart.
  Old, unscoped delayed cleanup messages are skipped with a reconciliation warning.
- Renamed loyalty vouchers retain SRS validation. Blocked codes now prevent order
  placement, with the cart-error signature supported on Shopware 6.5 and 6.7.
- API credentials are restricted to the exact trusted HTTPS API origin; redirects
  are disabled and request timeouts are bounded.
- Repeated cookie updates no longer attach duplicate voucher/points click handlers.
- Legacy one-euro voucher redemption rejects fractional-euro amounts and invalid
  point-conversion ratios cannot produce a zero/negative denominator.

## Update Instructions

- Use the ZIP matching your Shopware version. Upload and update the existing plugin.
- The delivery-register migration must run before traffic/queue workers use this
  version. Back up the database first and verify both CLI and HTTP cache ownership.
- Existing unsigned loyalty reward cart entries must be removed and re-added.
  Reconcile their old remote reservations first; do not blindly clear all contacts'
  carts. Previously queued unscoped cart cleanup also requires manual reconciliation.
- Compatibility placeholders also cover deployments that overlay existing files;
  do not preserve older copies of these files when installing the hotfix.
- Install assets, rebuild storefront/administration if required by your deployment,
  compile the active theme and clear Shopware, proxy/CDN and browser caches.
- Verify on your own staging domain that only the intended shop-owned Squeezely ID
  is requested. This hotfix does not remove trackers installed by other extensions,
  a tag manager or the active theme, nor undo data potentially sent previously.

## Regression Protection

`tests/tracking-regression.cjs` audits storefront source, generated bundles and Twig
templates. Startup tests assert no default external script, no Squeezely queue or
pixel injection, preservation of a shop-owned pixel, and only the explicitly enabled
Loyalty Engage loader. Release ZIPs must pass the same audit after extraction.

## Local Verification And Remaining Gates

Test environments: Shopware 6.5.3.0 and 6.7.16.0, PHP 8.2.35. Provider mutations
were mocked; no real customer coins were spent. Covered: voucher checkout/name/
minimum value, native and legacy reward authorization, feed mapping, SRS identity,
personalization scopes and authenticated-session isolation, DI/Twig syntax, actual
version-specific JS bundles, and 22 outbox failure/replay/concurrency scenarios per
environment. Local browser pages loaded without console errors or external script
tags after rebuilding the themes. This is not validation of Schijvens' staging.

Remaining release gates:

- Confirm safe replay of an individual Return after an unknown outcome. Separate
  partial returns can share an order ID, so that ID alone is not a replay key.
  For a delivery marked returned, the export uses only that delivery's product
  positions and quantities, with its share of the original order discount. An
  ambiguous partially-returned status without item quantities is not exported.
- Confirm safe voucher-redemption reconciliation (including its SRS side effects)
  before enabling any automatic replay of uncertain outcomes.
- Review deployment handling of unsigned pre-upgrade carts and old queued cleanup
  messages; their reservations are not silently cleared by the hotfix.
- Review remaining legacy points-flow/cart-change behavior and financial timeout
  reconciliation beyond the confirmed order-ID-idempotent purchase endpoints.
- Finish code review, construct final ZIPs from the reviewed commit, verify extracted
  artifacts, and validate on customer staging before publishing/distribution.
