FROM php:8.3-cli-trixie
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends git unzip mariadb-client; \
    rm -rf /var/lib/apt/lists/*
RUN set -eux; \
    docker-php-ext-install -j"$(nproc)" pdo_mysql; \
    php -r 'foreach (["pdo_mysql", "pdo_sqlite", "mbstring", "dom"] as $ext) { if (!extension_loaded($ext)) { fwrite(STDERR, "Missing PHP extension: $ext\n"); exit(1); } }'
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
WORKDIR /build
COPY composer.json composer.lock ./
RUN composer install --no-interaction --prefer-dist --no-scripts --no-autoloader \
 && composer dump-autoload --optimize --no-scripts
WORKDIR /workspace
