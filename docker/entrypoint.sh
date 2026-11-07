#!/bin/sh
# Container start-up: point Apache at $PORT, wait for MySQL, cache config/routes,
# optionally migrate/seed, then hand over to the CMD (Apache, or e.g. schedule:work).
set -e

cd /var/www/html
PORT="${PORT:-8080}"

sed -ri "s/^Listen .*/Listen ${PORT}/" /etc/apache2/ports.conf
sed -ri "s/<VirtualHost \*:[0-9]+>/<VirtualHost *:${PORT}>/" /etc/apache2/sites-available/000-default.conf

if [ -z "$APP_KEY" ]; then
    echo "APP_KEY is not set. Generate one with: php artisan key:generate --show" >&2
    exit 1
fi

if [ "${WAIT_FOR_DB:-true}" = "true" ]; then
    echo "Waiting for MySQL at ${DB_HOST}:${DB_PORT:-3306}/${DB_DATABASE} ..."
    attempts=0
    until php -r '
        try {
            new PDO(sprintf("mysql:host=%s;port=%s;dbname=%s", getenv("DB_HOST"), getenv("DB_PORT") ?: 3306, getenv("DB_DATABASE")),
                getenv("DB_USERNAME"), getenv("DB_PASSWORD"), [PDO::ATTR_TIMEOUT => 3]);
        } catch (Throwable $e) { fwrite(STDERR, $e->getMessage().PHP_EOL); exit(1); }' 2>/tmp/db-error; do
        attempts=$((attempts + 1))
        if [ "$attempts" -ge "${DB_WAIT_ATTEMPTS:-30}" ]; then
            echo "MySQL is unreachable: $(cat /tmp/db-error)" >&2
            exit 1
        fi
        sleep 2
    done
    echo "MySQL is reachable."
fi

php artisan config:cache --no-interaction
php artisan route:cache --no-interaction

if [ "${RUN_MIGRATIONS:-false}" = "true" ]; then
    php artisan migrate --force --no-interaction
fi

if [ "${RUN_SEEDERS:-false}" = "true" ]; then
    php artisan db:seed --force --no-interaction
fi

chown -R www-data:www-data storage bootstrap/cache

exec "$@"
