# Release Verification: 2.7.1 Candidate

Verified locally on 2026-11-11. This is a test record, not a published release.
The plugin manifest declares 2.7.1 for review. Publication and tagging follow only
after the release PR has been approved and merged.

## Shopware 6.5.3.0 / PHP 8.2.35

All 14 test scripts passed:

- PHP smoke: loyalty-shop, loyalty-feed, srs-voucher, voucher-promotion,
  personalizations, personalizations-context and personalizations-templates.
- PHP integration: loyalty-shop, loyalty-feed, personalizations and voucher-checkout.
- Node: personalizations-admin, personalizations-storefront (jsdom 26) and
  personalizations-http (real local HTTP requests and two separate customer sessions).

Additional checks passed:

- PHP syntax for all plugin source and PHP tests. Existing optional-before-required
  parameter deprecations remain in LoyaltyCartController and PointsRedemptionController.
- All 16 Twig templates; dependency injection container; git diff whitespace.
- Production storefront and administration builds using Node 18.17.1/npm 9.
- Asset installation, active theme compilation and HTTP 200 on /loyaltyshop.
- Browser inspection at desktop and 390px mobile width; points sorting changes the
  order to 75, 120 and 150 points. The sort submit label truncates in the current
  theme but remains operable; this is a presentation issue, not a failed backend check.

The voucher checkout test places an actual local order through CartOrderRoute. It
checks title, absolute discount, below/above/updated/zero minimum, cart reduction,
stable promotion reuse after renaming, SRS identity recognition, exactly one mocked
redemption, individual-code deletion and failed reuse in another cart. Database
writes are rolled back; external Loyalty Engage mutations are mocked.

The legacy native-shop/feed integration scripts were made self-contained: optional
.env loading, version-compatible kernel boot, a temporary customer, isolated feed
configuration, a fixture physical product and rollback of database writes.

## Shopware 6.7.16.0 / PHP 8.2.35

Passed all seven PHP smoke scripts and all four PHP integration scripts listed
above, plus the Node HTTP suite against port 8082. Framework-independent Node
administration and storefront DOM tests also passed after the compatibility edits.

- Fresh installation, plugin activation and migrations completed successfully.
- Dependency injection lint and all 16 Twig templates passed.
- Production administration (Vite) and storefront (Webpack/Vite) builds passed
  with Node 22.23.4/npm 11.8.0. The final storefront build uses feature:dump output.
- Assets installed and the active theme compiled successfully.
- Real local order placement, minimum updates/zero, renamed voucher redemption,
  SRS recognition, individual-code deletion and reuse rejection all passed.
- Real HTTP product/cart/checkout/CMS scopes, repeated CMS slots, two separate
  authenticated customers and private/no-store context checks passed.
- Browser inspection confirmed the native page at desktop and 390px mobile width.
  The real Vue 3 settings editor supports adding a rule, switching automatic/page
  scope and selecting a CMS page. The unsaved test rule was removed afterwards.

Found and fixed: explicit StorefrontController::setTwig injection is invalid on
6.7. The native catalog controller now uses autowiring/autoconfiguration, which
supports the legacy 6.5 setter and the 6.7 service-subscriber contract. Both versions'
service lint, native page rendering and actual checkout passed after this fix.

Test harnesses now use typed Shopware collections/entity aliases, explicit temporary
personalization tenants with settings restoration, and the correct local Host for
each environment. Assertions were not disabled to accommodate 6.7.

Core build warnings remain: dependency eval usage, large administration chunks and
a Meteor component CSS minification warning. None prevented either build.

### Approved Local Advisory Exception

The official production template initially could not resolve dependencies:

- shopware/core requires mcp/sdk ^0.8.1.
- Available matching version 0.8.1 is affected by PKSA-s52d-n7sx-trsg / GHSA-f4rf-6ghj-mhr6:
  OAuth middleware can bypass request-body limits and exhaust memory.
- Advisory: https://github.com/modelcontextprotocol/php-sdk/security/advisories/GHSA-f4rf-6ghj-mhr6

The user explicitly approved ignoring this single advisory only inside the disposable
localhost test project. No global Composer protection was disabled; neither the
plugin manifest nor source/production configuration contains this exception. The
exception is removed and the isolated 6.7 containers stopped after testing. This
does not endorse using that vulnerable upstream dependency in production.

Docker disk space was reclaimed only from generated test caches and the already
tested 6.5/6.7 node_modules directories. Source, databases and compiled assets were
retained. Reinstall Node dependencies before subsequent builds.

## Release Gates

- Local 6.5 and 6.7 verification gates passed after the controller fix.
- Production Loyalty Engage/bridge mutations and customer-specific themes were not
  exercised; use a dedicated provider sandbox for any live contract validation.
- These checks do not establish compatibility with every version admitted by ^6.5.0.
- Review the diff, set the intended version, prepare target-compatible assets and
  obtain the required GitHub code-owner approval before publishing.
