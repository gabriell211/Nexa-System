FROM php:8.4-cli-alpine
RUN apk add --no-cache postgresql-dev sqlite-dev libzip-dev oniguruma-dev icu-dev unzip git \
    && docker-php-ext-install pdo_pgsql pdo_sqlite bcmath pcntl intl mbstring zip
RUN printf 'upload_max_filesize=52M\npost_max_size=110M\nmax_file_uploads=8\n' > /usr/local/etc/php/conf.d/customer-ticket-media.ini
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist --no-scripts --no-progress --optimize-autoloader
COPY . .
RUN mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && composer dump-autoload --no-dev --optimize && chmod +x artisan \
    && chown -R www-data:www-data storage bootstrap/cache
EXPOSE 8080
USER www-data
CMD ["sh","-c","php artisan nexa:migrate-on-start && exec php artisan serve --host=0.0.0.0 --port=8080"]
