FROM php:8.3-cli-bookworm
RUN apt-get update && apt-get install -y --no-install-recommends git unzip python3 libonig-dev libxml2-dev libsqlite3-dev \
 && docker-php-ext-install -j2 pdo_mysql pdo_sqlite mbstring dom xml xmlwriter pcntl posix \
 && rm -rf /var/lib/apt/lists/* \
 && useradd -m -u 1000 ci
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
WORKDIR /build
COPY composer.json composer.lock ./
RUN composer install --no-interaction --prefer-dist --no-scripts --no-plugins --no-autoloader
USER ci
WORKDIR /workspace
