# syntax=docker/dockerfile:1.7
#
# 10MG Health lending API: PHP 8.3 + Apache (mod_php) serving Laravel's public/ directory.
#
#   docker compose build
#   docker compose up -d          # uses .env.docker (database credentials, app key)
#
FROM php:8.3-apache-bookworm

ARG INSTALL_DEV_DEPENDENCIES=true

ENV APACHE_DOCUMENT_ROOT=/var/www/html/public \
    PORT=8080 \
    COMPOSER_ALLOW_SUPERUSER=1

# System libraries + PHP extensions the app needs (MySQL, bcmath for money maths, intl, zip, Redis, OPcache).
RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip libzip-dev libicu-dev \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql bcmath intl zip opcache \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && a2enmod rewrite headers \
    && apt-get purge -y --auto-remove \
    && rm -rf /var/lib/apt/lists/* /tmp/pear

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY docker/php/app.ini /usr/local/etc/php/conf.d/zz-app.ini
COPY docker/apache/000-default.conf /etc/apache2/sites-available/000-default.conf

WORKDIR /var/www/html

# Dependencies first so this layer is cached until composer.lock changes.
# Dev dependencies are installed by default because the demo seeders use Faker;
# build with --build-arg INSTALL_DEV_DEPENDENCIES=false for a lean production image.
COPY composer.json composer.lock ./
RUN if [ "$INSTALL_DEV_DEPENDENCIES" = "true" ]; then NO_DEV=""; else NO_DEV="--no-dev"; fi \
    && composer install $NO_DEV --no-scripts --no-autoloader --prefer-dist --no-interaction --no-progress

COPY . .
RUN if [ "$INSTALL_DEV_DEPENDENCIES" = "true" ]; then NO_DEV=""; else NO_DEV="--no-dev"; fi \
    && composer dump-autoload --optimize $NO_DEV \
    && php artisan package:discover --ansi \
    && rm -f bootstrap/cache/config.php bootstrap/cache/routes-v7.php \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod +x docker/entrypoint.sh

EXPOSE 8080

HEALTHCHECK --interval=30s --timeout=5s --start-period=40s --retries=3 \
    CMD php -r 'exit(@file_get_contents("http://127.0.0.1:".(getenv("PORT") ?: 8080)."/up") === false ? 1 : 0);'

ENTRYPOINT ["docker/entrypoint.sh"]
CMD ["apache2-foreground"]
