FROM php:8.4-cli-alpine
RUN apk add --no-cache postgresql-dev libzip-dev oniguruma-dev icu-dev unzip git \
    && docker-php-ext-install pdo_pgsql bcmath pcntl intl mbstring zip
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /app
COPY composer.json composer.json
RUN composer install --no-dev --no-interaction --prefer-dist --no-scripts --optimize-autoloader
COPY . .
RUN composer dump-autoload --optimize && chmod +x artisan
EXPOSE 8080
CMD ["php","artisan","serve","--host=0.0.0.0","--port=8080"]
