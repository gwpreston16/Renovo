# syntax=docker/dockerfile:1

# ---------------------------------------------------------------------------
# Renovo application image (php-fpm).
#
# Both database drivers are compiled in, because the same image must be able
# to run against PostgreSQL or MySQL/MariaDB — which engine is in use is a
# runtime setting, not a build-time one.
#
# gd is here for avatars, which are re-encoded on upload rather than stored as
# they arrived. The runtime libraries stay in the image and only the headers
# are thrown away with .build-deps, so the extension it produces still has
# something to link against.
# ---------------------------------------------------------------------------
FROM php:8.4-fpm-alpine AS base

RUN apk add --no-cache \
        icu-libs \
        libjpeg-turbo \
        libpng \
        libpq \
        libwebp \
        libzip \
        oniguruma \
    && apk add --no-cache --virtual .build-deps \
        $PHPIZE_DEPS \
        icu-dev \
        jpeg-dev \
        libpng-dev \
        libwebp-dev \
        libzip-dev \
        postgresql-dev \
    && docker-php-ext-configure intl \
    && docker-php-ext-configure gd --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" \
        gd \
        intl \
        opcache \
        pdo_mysql \
        pdo_pgsql \
        zip \
    && apk del .build-deps

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY docker/php/php.ini /usr/local/etc/php/conf.d/99-app.ini

# ---------------------------------------------------------------------------
# Dependencies are installed in their own layer so that changing application
# code does not invalidate the Composer cache.
# ---------------------------------------------------------------------------
FROM base AS vendor

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --no-scripts --prefer-dist \
    && composer clear-cache

# ---------------------------------------------------------------------------
# The front-end assets.
#
# Tailwind is compiled, the JavaScript bundled, the icon sprite built and the
# webfonts vendored here, at build time, so the running container fetches
# nothing: an instance on a LAN or behind a VPN may have no route to a CDN at
# all. This stage is the only place in the image's life where the network is
# used for JavaScript — the same bargain the vendor stage makes with Packagist.
#
# templates/ is copied because Tailwind scans it for the class names it has to
# generate; a build without it would emit a stylesheet missing every utility
# the application actually uses.
# ---------------------------------------------------------------------------
FROM node:22-alpine AS assets

WORKDIR /app

COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund

COPY vite.config.js ./
COPY assets ./assets
COPY templates ./templates
COPY src/Application/Twig ./src/Application/Twig

RUN npm run build

# ---------------------------------------------------------------------------
FROM base AS app

COPY --from=vendor /var/www/html/vendor ./vendor
COPY . .

# After `COPY . .`, so a host build cannot survive into the image: .dockerignore
# keeps public/build out of the context, and this is the only thing that puts it
# back. What ships is always what this image's own assets stage produced.
COPY --from=assets /app/public/build ./public/build

# var/attachments and var/imports are created here rather than left to the
# application so their mode is set once, at build time. Both hold user files
# that are never web-served — invoices and receipts in the first, in-progress
# uploads in the second — so they are 0750 rather than the 0755 a cache wants.
RUN composer dump-autoload --no-dev --optimize --no-interaction \
    && mkdir -p var/cache var/log var/attachments var/avatars var/imports public/assets/logos \
    && chmod 750 var/attachments var/avatars var/imports \
    && chown -R www-data:www-data var public/assets/logos

# The short commit id an edge or development image was built from, shown
# beside the version as "1.7.0 (a1b2c3d)". A release image is built without it
# and shows the version alone. Declared last so a new commit id costs this one
# layer and not the vendor or assets stages.
ARG RENOVO_BUILD=""
ENV RENOVO_BUILD=${RENOVO_BUILD}

USER www-data

EXPOSE 9000

CMD ["php-fpm"]

# ---------------------------------------------------------------------------
# The web server.
#
# nginx carries the web root in its own image rather than reading it from the
# host, because the built assets only exist inside the image: `npm run build`
# ran in the assets stage, and `public/build/` is generated and therefore not in
# git. A plain nginx image pointed at a fresh clone would find no stylesheet at
# all, and — since the app resolves its stylesheet through the build manifest —
# no page either.
#
# So the two containers that have to agree about the web root are given the
# same one: this copies it out of the app stage, assets and all. Nothing is
# fetched at run time, on any network.
#
# Development mounts the working tree over the top of this (see
# docker-compose.dev.yml), which is what makes editing a stylesheet show up
# without a rebuild.
# ---------------------------------------------------------------------------
FROM nginx:1.27-alpine AS web

COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf
COPY --from=app /var/www/html/public /var/www/html/public
