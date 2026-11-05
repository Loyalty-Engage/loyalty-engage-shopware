#!/bin/sh
set -eu

if [ ! -f .source-initialized ]; then
    if [ ! -f /source/vendor/autoload.php ]; then
        echo 'SHOPWARE_SOURCE must point to the Shopware source directory containing vendor/autoload.php.' >&2
        exit 1
    fi
    # Deliberately omit .env, caches, database dumps and unrelated custom plugins.
    cp -a /source/bin /source/config /source/public /source/vendor /source/files .
    cp -a /source/composer.json /source/composer.lock /source/symfony.lock .
    rm -f config/jwt/private.pem config/jwt/public.pem
    touch .source-initialized
fi
mkdir -p var/cache var/log custom/plugins public/bundles public/theme files
chown -R www-data:www-data var public files
exec apache2-foreground
