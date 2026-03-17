#!/bin/bash
COMPOSER="/var/www/vhosts/rezultati.net/.phpenv/shims/composer"
NPM="/var/www/vhosts/rezultati.net/.nodenv/shims/npm"
PHP="/usr/bin/php"
APP="/var/www/vhosts/rezultati.net/httpdocs"
ENV_BACKUP="/var/www/vhosts/rezultati.net/.env.production"

# Restore .env if missing
if [ ! -f "$APP/.env" ]; then
    cp $ENV_BACKUP $APP/.env
    echo ".env restored"
fi

cd $APP
$COMPOSER install --no-dev --optimize-autoloader
$NPM install
$NPM run build
$PHP artisan migrate --force
$PHP artisan config:cache
$PHP artisan route:cache
$PHP artisan view:cache
echo "Deploy complete!"
