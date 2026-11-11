# Loyalty Engage 2.7.1

## Fixes

- Voucher promotions display the API `product.title`, falling back to the SKU.
  A stable SKU identity keeps renamed promotions reusable instead of duplicating them.
- API `discountMinimumOrderValue` is enforced through a Shopware cart amount order
  rule, including cart changes and checkout. Zero removes the effective threshold.
- Voucher redemption and SRS status validation recognize the stable identity as well
  as legacy promotion names. Renamed vouchers are still redeemed and their used
  individual codes removed to prevent reuse.
- Native catalog controller injection works on both Shopware 6.5 and 6.7; it no
  longer unconditionally calls the Twig setter removed in 6.7.

## Upgrade Notes

- Existing promotions synchronize their title and minimum on the next voucher
  claim. Already issued vouchers are not fetched or migrated retroactively.
- API/bridge responses without `discountMinimumOrderValue` preserve existing rules.
- Promotions with manual order rules require review rather than adding an unsafe
  alternative condition: Shopware combines separate order rules with OR.
- Install/update the plugin, clear caches, rebuild administration/storefront assets
  using the target Shopware version's tools, and compile the active theme.
- Updates from 2.6.1 also include the optional features documented in
  RELEASE-NOTES-2.7.0.md. They remain opt-in.

## Verification

Local smoke, integration, checkout, HTTP/CMS/session and build checks passed on
Shopware 6.5.3.0 and 6.7.16.0 with PHP 8.2.35. External provider mutations were
mocked, no real loyalty points were spent, and temporary test data was cleaned up.
See RELEASE-VERIFICATION-2.7.1.md for exact scope, upstream warnings and exclusions.

No Composer security exception is included in the plugin or production settings.
