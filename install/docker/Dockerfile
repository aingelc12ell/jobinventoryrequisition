# ==========================================================
# JIR — PHP 8.4-FPM Application Container
# ==========================================================
FROM php:8.4-fpm-bookworm

LABEL maintainer="JIR Project"
LABEL description="PHP 8.4-FPM with extensions for Job & Inventory Request System"

# ── System dependencies for PHP extensions ─────────────────
RUN apt-get update && apt-get install -y --no-install-recommends \
        libpng-dev \
        libjpeg62-turbo-dev \
        libfreetype6-dev \
        libwebp-dev \
        libzip-dev \
        libicu-dev \
        unzip \
        curl \
    && docker-php-ext-configure gd \
        --with-freetype \
        --with-jpeg \
        --with-webp \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_mysql \
        zip \
        intl \
        gd \
        opcache \
    && apt-get purge -y --auto-remove -o APT::AutoRemove::RecommendsImportant=false \
    && rm -rf /var/lib/apt/lists/*

# ── Composer ───────────────────────────────────────────────
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# ── Custom PHP configuration ──────────────────────────────
COPY php.ini /usr/local/etc/php/conf.d/zz-custom.ini

WORKDIR /var/www/html

# ── Storage directories (created on image, permissions
#    fixed at runtime by entrypoint or compose) ─────────────
RUN mkdir -p \
        storage/cache/twig \
        storage/cache/rate_limit \
        storage/logs \
        storage/uploads \
    && chown -R www-data:www-data storage

EXPOSE 9000
CMD ["php-fpm"]
