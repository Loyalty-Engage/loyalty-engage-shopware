# Local Shopware From Source

Isolated, localhost-only test environment. The source directory is read-only and its
vendor/config/public files are copied into a new named application volume on first
start. Source `.env`, old caches, database dumps and unrelated plugins are not copied.
The current LoyaltyEngage working directory is mounted directly as the sole plugin.

```sh
docker compose -f tools/local-shopware/compose.yaml up -d --build
```

Default source: the existing Shopware `src` directory five levels above this compose
file. Override with an absolute `SHOPWARE_SOURCE` path if needed. This uses the
installed vendor version, not the intended version range from composer.json.
Do not run composer update as part of restoring this source snapshot.

New database/application volumes are named with the `loyalty-shopware-source` prefix.
No old volumes are reused or deleted. Only HTTP is published, on 127.0.0.1:8081;
the database is private to the compose network. Outgoing mail uses a null transport.
The hardcoded credentials and app secret are for local development only.
The separate Node 18.17.1/npm 9 builder matches this legacy source's declared build
requirements. It is a short-lived tooling container, not the web runtime.
The local Messenger override intentionally omits old plugin-specific routes so a
fresh database can boot before the plugin has been activated.

After first start, provision the fresh database (once):

```sh
docker compose -f tools/local-shopware/compose.yaml exec shopware php bin/console system:install --basic-setup --force
docker compose -f tools/local-shopware/compose.yaml exec shopware php bin/console plugin:refresh
docker compose -f tools/local-shopware/compose.yaml exec shopware php bin/console plugin:install --activate LoyaltyEngage
```

Use Shopware's console commands to create a local administrator, assign the storefront
domain/theme and install assets. No real Loyalty Engage API credentials are configured.
Do not expose this legacy Shopware source snapshot on the internet or use it as a
production deployment. Verify PHP patches independently of Shopware/plugin versions.

The basic setup creates administrator `admin` with local password `shopware`.
Administration: <http://localhost:8081/admin>. Storefront: <http://localhost:8081>.
The configured test hostname is `shopware.test` (HTTP on port 8081; port 80 remains
reserved for the separate Magento environment). Add `127.0.0.1 shopware.test` to
the host computer's hosts file. The existing localhost domain remains available.
Use `shopware.test` in Loyalty Engage's frontend Allowed domains settings.
Primary storefront: <http://shopware.test:8081>.
For this source snapshot PHP 8.2.35 was verified after building the image.

Build assets after activating the plugin:

```sh
docker compose -f tools/local-shopware/compose.yaml exec --user www-data shopware php bin/console bundle:dump
docker compose -f tools/local-shopware/compose.yaml exec --user www-data shopware php bin/console feature:dump
docker compose -f tools/local-shopware/compose.yaml run --rm assets npm --prefix vendor/shopware/administration/Resources/app/administration install --no-audit --legacy-peer-deps
docker compose -f tools/local-shopware/compose.yaml run --rm assets npm --prefix vendor/shopware/administration/Resources/app/administration run build
docker compose -f tools/local-shopware/compose.yaml run --rm assets npm --prefix vendor/shopware/storefront/Resources/app/storefront run production
docker compose -f tools/local-shopware/compose.yaml exec --user www-data shopware php bin/console assets:install
docker compose -f tools/local-shopware/compose.yaml exec --user www-data shopware php bin/console theme:compile
```

Run subsequent console commands as `www-data` to keep cache and public asset ownership
consistent. Initial root provisioning may require a one-time ownership correction
inside the new container (`var`, `public`, `files` and `config/jwt` only).

Start/stop without removing data:

```sh
docker compose -f tools/local-shopware/compose.yaml stop
docker compose -f tools/local-shopware/compose.yaml start
```
