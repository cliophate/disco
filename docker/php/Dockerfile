FROM php:8.5-cli-bookworm

RUN apt-get update \
    && apt-get install -y --no-install-recommends libjpeg62-turbo-dev libonig-dev libpng-dev libpq-dev libwebp-dev libzip-dev \
    && docker-php-ext-configure gd --with-jpeg --with-webp \
    && docker-php-ext-install gd mbstring pcntl pdo_pgsql zip \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app
