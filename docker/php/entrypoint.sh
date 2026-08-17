#!/bin/sh
set -e

cd /var/www/html

as_app() {
    if [ "$(id -u)" = "0" ]; then
        su-exec app "$@"
    else
        "$@"
    fi
}

if [ ! -f .env ]; then
    cp .env.example .env
fi

if [ ! -d vendor ]; then
    echo "==> installing composer dependencies"
    as_app composer install --no-interaction --prefer-dist --no-progress
fi

if ! grep -q '^APP_KEY=base64:' .env; then
    echo "==> generating application key"
    as_app php artisan key:generate --force
fi

# Only the web container migrates; the worker and the scheduler just wait for
# the schema to be there.
if [ "${RUN_MIGRATIONS}" = "true" ]; then
    echo "==> running migrations"
    as_app php artisan migrate --force
fi

# php-fpm keeps its master process as root so it can drop each worker to the
# application user; everything else runs unprivileged from the start.
case "$1" in
    php-fpm)
        exec "$@"
        ;;
    *)
        if [ "$(id -u)" = "0" ]; then
            exec su-exec app "$@"
        fi
        exec "$@"
        ;;
esac
