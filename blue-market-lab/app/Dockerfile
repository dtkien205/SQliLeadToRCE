FROM php:8.2-fpm

RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        libpq-dev \
        libsqlite3-dev; \
    docker-php-ext-install -j"$(nproc)" pdo pdo_pgsql pgsql pdo_sqlite; \
    apt-mark manual libpq5 libsqlite3-0; \
    apt-get purge -y --auto-remove libpq-dev libsqlite3-dev; \
    rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html
