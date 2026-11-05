FROM php:8.2-apache-bookworm

RUN apt-get update && apt-get install -y --no-install-recommends \
    libicu-dev libzip-dev libpng-dev libjpeg62-turbo-dev libfreetype6-dev \
    libonig-dev libxml2-dev libcurl4-openssl-dev unzip git \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j2 intl pdo_mysql zip gd mbstring bcmath curl opcache \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/*

COPY apache.conf /etc/apache2/sites-available/000-default.conf
COPY php.ini /usr/local/etc/php/conf.d/shopware-local.ini
COPY start.sh /usr/local/bin/shopware-source-start
RUN chmod +x /usr/local/bin/shopware-source-start
WORKDIR /var/www/html
CMD ["shopware-source-start"]
