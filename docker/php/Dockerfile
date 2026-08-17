# syntax=docker/dockerfile:1

FROM php:8.4-fpm-alpine

# gd is built with webp support on purpose: the whole compression story
# depends on it. pcntl lets queue workers handle restart signals cleanly.
RUN apk add --no-cache \
        freetype \
        git \
        icu-libs \
        libjpeg-turbo \
        libpng \
        libwebp \
        libzip \
        postgresql-libs \
        su-exec \
        unzip \
    && apk add --no-cache --virtual .build-deps \
        $PHPIZE_DEPS \
        freetype-dev \
        icu-dev \
        libjpeg-turbo-dev \
        libpng-dev \
        libwebp-dev \
        libzip-dev \
        postgresql-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" gd intl opcache pcntl pdo_pgsql zip \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del .build-deps

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

ARG USER_ID=1000
ARG GROUP_ID=1000

# The application user mirrors the host uid/gid so that files written inside
# the container stay editable outside of it.
RUN addgroup -g ${GROUP_ID} app \
    && adduser -D -u ${USER_ID} -G app app

COPY docker/php/php.ini /usr/local/etc/php/conf.d/99-app.ini
COPY docker/php/www.conf /usr/local/etc/php-fpm.d/zz-app.conf
COPY docker/php/entrypoint.sh /usr/local/bin/entrypoint

RUN chmod +x /usr/local/bin/entrypoint

WORKDIR /var/www/html

ENTRYPOINT ["entrypoint"]
CMD ["php-fpm", "-F"]
