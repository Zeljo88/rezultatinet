FROM php:8.3-cli
RUN apt-get update \
 && apt-get install -y --no-install-recommends git unzip mariadb-client libonig-dev libzip-dev libxml2-dev \
 && docker-php-ext-install pdo_mysql pdo_sqlite mbstring dom \
 && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
WORKDIR /build
COPY composer.json composer.lock ./
RUN composer install --no-interaction --prefer-dist --no-scripts --no-autoloader \
 && composer dump-autoload --optimize --no-scripts
WORKDIR /workspace
