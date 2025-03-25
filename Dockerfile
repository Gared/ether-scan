FROM composer:2 AS composer
COPY . /app
RUN composer install --no-dev -o

FROM php:8.4-cli AS php-cli
COPY --from=composer /app /app
WORKDIR /app
