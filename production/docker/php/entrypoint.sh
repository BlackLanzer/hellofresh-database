#!/bin/sh
set -e

# Only the php-fpm container should run migrations and warm caches; horizon and
# the scheduler share this image and would otherwise race against it.
ROLE="${CONTAINER_ROLE:-app}"

echo "Waiting for database..."
until php artisan db:show --no-interaction > /dev/null 2>&1; do
    sleep 2
done
echo "Database is ready."

if [ "$ROLE" = "app" ]; then
    php artisan package:discover --ansi

    php artisan storage:link --force --ansi

    php artisan migrate --force --ansi

    # Seeds countries via updateOrCreate, safe to repeat on every boot.
    php artisan db:seed --class=CountrySeeder --force --ansi

    php artisan config:cache --ansi
    php artisan route:cache --ansi
    php artisan view:cache --ansi
    php artisan event:cache --ansi

    echo "Bootstrap complete."
else
    # Workers still need the cached config that the app container wrote.
    php artisan package:discover --ansi
    echo "Worker ready (role: $ROLE)."
fi

exec "$@"
