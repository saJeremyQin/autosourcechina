FROM composer:2 AS plugins

# Plugins and themes are installed from composer.lock (wpackagist), not committed.
WORKDIR /build
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --ignore-platform-reqs

FROM wordpress:7.1.3-php8.2-fpm AS app

# Install system dependencies
RUN apt-get update && apt-get install -y \
    libfreetype6-dev \
    libjpeg62-turbo-dev \
    libpng-dev \
    libzip-dev \
    unzip \
    git \
    && rm -rf /var/lib/apt/lists/*

# Configure and install PHP extensions
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
        gd \
        mysqli \
        pdo_mysql \
        zip

# Install PHP Redis extension
RUN pecl install redis \
    && docker-php-ext-enable redis

RUN { \
        echo 'opcache.memory_consumption=128'; \
        echo 'opcache.interned_strings_buffer=8'; \
        echo 'opcache.max_accelerated_files=4000'; \
        echo 'opcache.revalidate_freq=2'; \
        echo 'opcache.fast_shutdown=1'; \
    } > /usr/local/etc/php/conf.d/opcache-recommended.ini

# The official image copies /usr/src/wordpress to /var/www/html at startup,
# so project code must be added there to be included.
COPY wp-content/ /usr/src/wordpress/wp-content/
COPY --from=plugins --chown=www-data:www-data /build/wp-content/ /usr/src/wordpress/wp-content/

WORKDIR /var/www/html

# Stateless nginx image: static files are baked in from the app stage (same
# WordPress version and wp-content), so no shared volume is needed. Uploads live in S3.
FROM nginx:alpine AS nginx

COPY --from=app /usr/src/wordpress /var/www/html
COPY nginx/ecs.conf /etc/nginx/conf.d/default.conf