FROM composer:2 AS composer-dependencies

WORKDIR /build

COPY composer.json composer.lock ./

RUN composer install \
    --no-dev \
    --no-interaction \
    --no-progress \
    --no-scripts \
    --optimize-autoloader \
    --prefer-dist

FROM php:8.2-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        libcurl4-openssl-dev \
        libonig-dev \
    && docker-php-ext-install -j$(nproc) \
        curl \
        mbstring \
        pdo_mysql \
    && a2enmod rewrite \
    && rm -rf /var/lib/apt/lists/*

COPY docker/apache-civentral.conf /etc/apache2/conf-available/civentral.conf
COPY docker/php-staging.ini /usr/local/etc/php/conf.d/zz-civentral-staging.ini

RUN a2enconf civentral

WORKDIR /var/www/html

COPY . /var/www/html/
COPY --from=composer-dependencies /build/vendor /var/www/html/vendor

EXPOSE 80
