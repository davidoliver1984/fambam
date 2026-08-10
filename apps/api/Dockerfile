FROM composer:2.8.9 AS composer

FROM php:8.4.5-cli-alpine

RUN apk add --no-cache \
        $PHPIZE_DEPS \
        curl \
        imagemagick \
        imagemagick-heic \
        imagemagick-jpeg \
        imagemagick-tiff \
        imagemagick-webp \
        libpq-dev \
        libzip-dev \
        unzip \
    && docker-php-ext-install pdo_pgsql pcntl zip \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del $PHPIZE_DEPS

COPY --from=composer /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY composer.json composer.lock ./
RUN composer install \
    --no-dev \
    --no-interaction \
    --no-progress \
    --no-scripts \
    --prefer-dist

COPY . .

RUN composer dump-autoload --classmap-authoritative --no-dev \
    && chown -R www-data:www-data storage bootstrap/cache

USER www-data

EXPOSE 8080

CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8080"]
