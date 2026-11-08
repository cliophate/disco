FROM php:8.4.27-cli-bookworm

RUN apt-get update \
    && apt-get install -y --no-install-recommends libjpeg62-turbo-dev libonig-dev libpng-dev libpq-dev libwebp-dev libzip-dev \
    && docker-php-ext-configure gd --with-jpeg --with-webp \
    && docker-php-ext-install gd mbstring pcntl pdo_pgsql zip \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2@sha256:af98f42dfff7c68ba8d53c2164fd9fde1087b7d449514baa38c418b1f6bc4bac /usr/bin/composer /usr/bin/composer

WORKDIR /app
