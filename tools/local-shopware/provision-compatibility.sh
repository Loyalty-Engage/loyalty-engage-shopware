#!/bin/sh
set -eu
test "$APP_URL" = 'http://localhost:8082'
cd /var/www/html
if [ ! -f /tmp/composer.phar ]; then
    curl --fail --silent --show-error --location https://getcomposer.org/composer-stable.phar -o /tmp/composer.phar
fi
if [ ! -f composer.json ]; then
    php /tmp/composer.phar create-project shopware/production:6.7.16.0 /tmp/shopware67 --no-install --no-scripts --no-interaction
    cp -a /tmp/shopware67/. /var/www/html/
fi
php /tmp/composer.phar install --no-dev --no-interaction --prefer-dist
mkdir -p var/cache var/log files public/theme config/jwt
chown -R www-data:www-data var public files config/jwt
