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

# Restart queue worker
pkill -f "queue:work" || true
nohup $PHP artisan queue:work redis --sleep=3 --tries=3 --daemon > $APP/storage/logs/queue.log 2>&1 &

# Restart Reverb
pkill -f "reverb:start" || true
nohup $PHP artisan reverb:start --host=0.0.0.0 --port=8080 > $APP/storage/logs/reverb.log 2>&1 &
