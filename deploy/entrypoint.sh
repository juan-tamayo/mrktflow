#!/bin/sh
# Container entrypoint. The image CMD is the role: app | queue | scheduler | <artisan...>
set -e
cd /var/www/html

mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache 2>/dev/null || true
php artisan storage:link >/dev/null 2>&1 || true

role="${1:-app}"
case "$role" in
  app)
    echo "mrktflow: waiting for database..."
    until php artisan db:show >/dev/null 2>&1; do sleep 2; done
    php artisan migrate --force
    exec supervisord -c /etc/supervisor/app.conf
    ;;
  queue)
    until php artisan db:show >/dev/null 2>&1; do sleep 2; done
    exec supervisord -c /etc/supervisor/queue.conf
    ;;
  scheduler)
    until php artisan db:show >/dev/null 2>&1; do sleep 2; done
    exec php artisan schedule:work
    ;;
  *)
    exec "$@"
    ;;
esac
