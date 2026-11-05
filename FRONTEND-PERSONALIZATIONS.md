# Frontend personalizations

This feature is opt-in and independent of the native loyalty shop. No plugin version
is published until a real Loyalty Engage storefront render has been verified.

## Configuration

In the plugin's **Frontend personalizations** card, configure a frontend tenant UUID
or an external official loader tag such as:

```html
<script type="module" async src="https://le-cloud.app/f/t/804bd81b-a77e-42ee-a70c-53e62eadf4ae.js"></script>
```

The official inline bootstrap (`((a,b,c,d,e,f)=>{...})(window,document,"script","UUID");`)
is also accepted, including whitespace and single/double quote variations. Its full
known structure is validated; arbitrary inline scripts are not accepted or executed.
Only the UUID is persisted. Other hosts, modified scripts, event handlers and additional
HTML are rejected server-side. This tenant is not the API Client ID. Configuration
inherits from global settings unless overridden for the sales channel. An empty rules
array explicitly disables inherited rules.

Each rule has a unique lowercase key, Public ID, enabled switch, manual/automatic
placement, page scope, selected CMS page IDs, personalized switch and cart-value switch.
The rule editor supports multiple rules without storing arbitrary scripts or HTML.
System configuration uses Shopware's existing administration permissions.

Automatic targets appear before page content on product detail, cart, checkout
register/confirm, or explicitly selected CMS pages. Checkout finish is intentionally
excluded. For precise placement, add the **Loyalty Engage personalization** CMS block
and copy the rule's key into its element configuration. Repeated placements receive
separate DOM targets. Manual rules are not constrained by the automatic page scope.

Allow each storefront, staging and preview domain in Loyalty Engage > Developer >
Frontend settings > Allowed domains. In Loyalty Engage, use **replace content** for
personalizations whose context changes. Public IDs and the loader are not authorization.
Any consent or CSP requirement must also be configured for the customer's storefront;
the integration does not bypass either.

## Context, Prices And Privacy

The initial HTML contains only public configuration, targets and the PDP product ID.
Session-specific context is fetched from `/loyalty/personalizations/context` via
same-origin XHR with private/no-store headers and disabled Shopware HTTP caching.
Only requested active rules receive context. Authenticated non-guest customers use
SHA-256 of the trimmed, lowercase UTF-8 email. Genuinely anonymous rules never send
an identifier. Hashes remain personal data and are not written to debug output.

Explicitly select the Loyalty Engage organization currency before enabling cartValue.
Missing/invalid currency or unsupported page context omits cartValue, rather than
guessing. Conversion uses Shopware's configured currency factors, rounded to two
decimals; keep these exchange rates aligned with the organization.

- PDP: current selected product/variant unit price, with applicable quantity tier.
- Cart/checkout: sum of product unit prices times quantity, minus promotion line
  totals, clamped to zero. No delivery/shipping charges.
- Tax basis matches the existing PurchaseSubscriber: Shopware's calculated selling
  prices, without explicitly subtracting VAT. Tax-free/net storefront contexts keep
  their own Shopware basis. No change to order awarding is made here.

The official asynchronous module is loaded once when an enabled target is present
and visible. Queue events are deduplicated per actual DOM node and context. A new
node at the same placement is triggered again. Relevant Shopware plugin events,
quantity changes, browser history and DOM mounts refresh context. Custom storefronts
can dispatch `document.dispatchEvent(new Event('loyaltyengage:context-changed'))`
after their own navigation/cart changes; dynamically replaced page fragments must
also replace the public configuration marker with the new scope/product settings.

Optional debug output includes key, target, loader start/failure, queue push and
context-change status only. Network/context failure skips the trigger; it does not
break shopping or expose raw server errors. Conflicting tenant loaders require a
full page reload instead of mixing tenants.

## Verification

```sh
php tests/personalizations-smoke.php /path/to/shopware/vendor/autoload.php
php tests/personalizations-context.php /path/to/shopware/vendor/autoload.php
php tests/personalizations-templates.php /path/to/shopware/vendor/autoload.php
node tests/personalizations-storefront.cjs /path/to/node_modules
node tests/personalizations-admin.cjs
```

The Node test requires jsdom 26 or later. PHP checks cover normalization, rejecting
scripts, rule validation, persistence normalization, sales-channel inheritance,
guest identifiers and currency conversion. Context tests cover PDP quantity tiers,
cart/checkout discounts, shipping
exclusion, organization currency, authenticated/guest context and no-store headers.
DOM tests cover single loader, duplicate events, repeated/remounted targets,
disabled/out-of-scope rules, guest/authenticated
context updates and network failure. They do not prove a real remote render.

Before release, test a real tenant/Public ID on an allowed local or staging domain:
verify the loader AND personalization request succeed AND visible HTML appears.
Test PDP variants/quantity pricing, cart and checkout after discounts, currencies,
guest/login/logout, two customers with shared cache, CMS placement and automatic
scopes. Check missing targets/invalid IDs, blocked domain, CSP and network failure.
Do not treat a successful queue push or cached loader as proof of a rendered result.

Local HTTP integration (disposable test database only):

```sh
php tests/personalizations-integration.php /var/www/html SALES_CHANNEL_ID --confirm-local
```

This creates temporary CMS pages and two local test customers, tests scope placement
and session isolation via real HTTP, and restores its settings/deletes its fixtures
on normal completion or a PHP exception. Do not kill the test or stop Docker mid-run;
after a hard interruption, check its temporary QA records and restore the test rules.

The same real storefront checks can run from the host via the local Admin API, without
`docker exec` or a particular PHP installation:

```sh
SHOPWARE_QA_PASSWORD=LOCAL_ADMIN_PASSWORD node tests/personalizations-http.cjs SALES_CHANNEL_ID --confirm-local
```

This accepts only localhost:8081 or shopware.test:8081. It clears the local cache,
creates isolated CMS/customer/product fixtures, tests real storefront cookie sessions
and non-empty-cart checkout, and restores its original rules/currency before deleting
its own fixtures. No browser JavaScript runs and no external loyalty API is contacted.
An optional `SHOPWARE_QA_BROWSER=1` pause exposes the temporary CMS URL for a separately
authorized manual browser check; it times out and cleans up after three minutes.

### Release Verification, 2026-11-05

- Passed: configuration/context smoke tests, Twig scope and CMS-slot tests, DOM queue
  and dynamic-mount regressions, existing loyalty-shop/feed/SRS smoke tests.
- Passed: storefront and administration production builds on the local Shopware
  6.5.3.0 source snapshot (PHP 8.2.35); service-container and template lint.
- Real remote render observed: Public ID `test-1`, visible text `Dit is een test`
  on a product page at `shopware.test:8081`, one official loader and no browser errors.
- Reviewed the official Shopware 6.7.8.2 selector source and added its `value` /
  `update:value` binding alongside the 6.5 `ids` / `change` binding. A complete
  Shopware 6.7 runtime test has not been performed for this feature.
- Passed after review fixes: real HTTP suite via the local Admin API. Product, manual
  CMS, repeated slots, selected landing-page placement, empty/non-empty cart, checkout
  registration/confirmation, exact cartValue, two authenticated identities and public
  HTML isolation all passed. Original settings and own fixtures were restored/removed.
- Fixed during real testing: CMS-based pages override `base_main_inner`, so the
  integration uses the stable `base_main` block. Landing pages use their nested CMS
  page ID for both scope matching and the frontend bootstrap.
- Recompiled the existing local theme through the Admin API and repeated the real
  product-page render after hardening: visible content, one loader, no browser errors.
- Pending before publication: full Shopware 6.7 runtime verification and code-owner
  approval. An additional real-provider multi-target CMS browser check requires
  explicit authorization; server-rendered CMS targets and client lifecycle tests pass.
  Docker CLI management remains unresponsive; no other containers were restarted.
