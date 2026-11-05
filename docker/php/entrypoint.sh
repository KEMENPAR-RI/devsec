#!/bin/sh
set -e

cd /var/www/html
rm -f /tmp/ready

# Named volume may start empty: recreate the skeleton
mkdir -p storage/app/public storage/app/private storage/framework/cache/data \
    storage/framework/sessions storage/framework/views storage/logs

if [ -z "$APP_KEY" ]; then
    echo "entrypoint: APP_KEY is empty, set it in .env" >&2
    exit 1
fi

if [ "$CONTAINER_ROLE" = "app" ]; then
    if [ "$RUN_MIGRATIONS" = "true" ]; then
        php artisan migrate --force --no-interaction
    fi

    php artisan view:cache --quiet
fi

# bootstrap/cache is per container, so every role caches its own config/routes
php artisan config:cache --quiet
php artisan route:cache --quiet
php artisan event:cache --quiet

touch /tmp/ready
exec "$@"
