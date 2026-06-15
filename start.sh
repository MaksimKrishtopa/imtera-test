#!/bin/sh
# Entry point: determines whether to run as web server or queue worker
# based on SERVICE_ROLE environment variable (set per-service in Railway)

if [ "$SERVICE_ROLE" = "worker" ]; then
    echo "Starting queue worker..."
    exec php artisan queue:work --timeout=360 --tries=1 --sleep=3
else
    echo "Starting web server..."
    php artisan migrate --force
    php artisan db:seed --force
    php artisan config:clear
    exec php artisan serve --host=0.0.0.0 --port=$PORT
fi
