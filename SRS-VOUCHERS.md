# SRS voucher creation

Enable **Use SRS for discount code creation** in the plugin configuration for
the appropriate sales channel. Set **SRS Tenant ID**, **Client ID** and
**Client Secret**. Sales-channel values take precedence over global values;
an explicitly disabled channel does not inherit an enabled global switch.
Missing tenant or credentials blocks voucher creation before any HTTP request.

The existing authenticated storefront endpoint `/loyaltyshop/discount/claim`
continues to use the logged-in Shopware customer's email. Browser scripts do
not receive credentials and do not need to choose the backend.

When enabled, discount product purchases POST to
`https://prod.bridge.loyaltyengage.tech/api/srs/v1/create-voucher` with the
same Basic Auth credentials as Loyalty Engage. The JSON fields are `tenant`,
`email` and `voucher_type`. The product SKU must be the voucher_type configured
for that tenant. No voucher type is inferred from browser-supplied amounts.

A successful bridge response must contain `success: true`, a barcode/code,
and a positive voucher value. `barcode` and `value` are normalized to
`discountCode` and `discountAmount` for the existing Shopware promotion flow.
Bridge failures never fall back to the direct API. If the response is incomplete
or the request times out, check whether a voucher was created before retrying.

With the creation switch disabled, purchases use the existing direct Loyalty
Engage endpoint. The separate **SRS Discount Sync** switch still controls cart
status checks. Status and redemption use the production bridge and channel
configuration, retaining their existing platform-key authentication. These
endpoints still require **SRS Platform Key**; it is not used for creation.

The Coef reference documentation and implementation disagree on voucher_type
formatting. Validate the exact SKU and response contract with a test tenant
before enabling creation in production.

Run the isolated request checks without external API calls:

```sh
php tests/srs-voucher-smoke.php /path/to/shopware/vendor/autoload.php
```
