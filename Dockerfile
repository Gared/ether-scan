FROM php:8.5-cli AS php-cli
WORKDIR /app

COPY --from=composer/composer:2-bin /composer /usr/bin/composer

RUN apt-get update \
    && apt-get install -y zip unzip \
    && rm -rf /var/lib/apt/lists/*

COPY . /app
RUN composer install --no-dev -o && rm /usr/bin/composer
