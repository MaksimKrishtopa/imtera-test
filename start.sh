#!/bin/sh
# Debug: print all relevant env vars
echo "=== START.SH RUNNING ==="
echo "SERVICE_ROLE=[${SERVICE_ROLE}]"
echo "PORT=[${PORT}]"
echo "========================"

if [ "${SERVICE_ROLE}" = "worker" ]; then
    echo ">>> MODE: WORKER - starting queue:work"
    exec php artisan queue:work --timeout=360 --tries=1 --sleep=3
else
    echo ">>> MODE: WEB SERVER"
    php artisan migrate --force
    php artisan db:seed --force
    php artisan config:clear
    exec php artisan serve --host=0.0.0.0 --port="${PORT}"
fi
