#!/bin/bash
set -e
COMPOSER="/var/www/vhosts/rezultati.net/.phpenv/shims/composer"
NPM="/var/www/vhosts/rezultati.net/.nodenv/shims/npm"
PHP="/usr/bin/php"
APP="/var/www/vhosts/rezultati.net/httpdocs"

cd $APP
$COMPOSER install --no-dev --optimize-autoloader
$NPM install
$NPM run build
$PHP artisan migrate --force
$PHP artisan config:cache
$PHP artisan route:cache
$PHP artisan view:cache
echo "Deploy complete!"
