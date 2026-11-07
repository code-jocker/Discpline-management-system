FROM php:8.2-apache

# Build only the extensions the app uses. curl is already enabled in the
# base image; mbstring needs libonig, zip needs libzip, gd needs libpng/libjpeg.
RUN apt-get update \
 && apt-get install -y --no-install-recommends \
        libzip-dev \
        libonig-dev \
        libpng-dev \
        libjpeg62-turbo-dev \
 && docker-php-ext-configure gd --with-jpeg \
 && docker-php-ext-install -j"$(nproc)" \
        pdo_mysql \
        mbstring \
        zip \
        gd \
        opcache \
 && rm -rf /var/lib/apt/lists/*

# Enable Apache modules
RUN a2enmod rewrite headers expires deflate

# PHP production config overrides
COPY deploy/php.ini /usr/local/etc/php/conf.d/sbms.ini

# Apache virtual host
COPY deploy/apache-docker.conf /etc/apache2/sites-available/000-default.conf

# Render routes traffic to $PORT (default 10000), not 80.
ENV PORT=10000
RUN sed -i 's/^Listen 80$/Listen ${PORT}/' /etc/apache2/ports.conf

WORKDIR /var/www/html

# Copy application code
COPY . /var/www/html

# Set correct permissions
RUN mkdir -p /var/www/html/storage \
             /var/www/html/storage/uploads \
             /var/www/html/storage/reports \
             /var/www/html/storage/ratelimits \
 && chown -R www-data:www-data /var/www/html/storage \
 && chmod -R 775 /var/www/html/storage

EXPOSE 10000
