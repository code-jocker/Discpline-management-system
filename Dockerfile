FROM php:8.2-apache

# Install system dependencies
RUN apt-get update \
 && apt-get install -y --no-install-recommends \
        libzip-dev \
        libpng-dev \
        libjpeg-dev \
        libicu-dev \
        libcurl4-openssl-dev \
        curl \
        unzip \
        supervisor \
 && rm -rf /var/lib/apt/lists/*

# Install PHP extensions
RUN docker-php-ext-install \
        pdo_mysql \
        mbstring \
        zip \
        intl \
        opcache \
        curl

# Enable Apache modules
RUN a2enmod rewrite headers expires deflate

# PHP production config overrides
COPY deploy/php.ini /usr/local/etc/php/conf.d/sbms.ini

# Apache virtual host
COPY deploy/apache-docker.conf /etc/apache2/sites-available/000-default.conf

WORKDIR /var/www/html

# Copy application code
COPY . /var/www/html

# Set correct permissions
RUN chown -R www-data:www-data /var/www/html/storage \
 && chmod -R 775 /var/www/html/storage \
 && mkdir -p /var/www/html/storage/uploads \
             /var/www/html/storage/reports \
             /var/www/html/storage/ratelimits \
 && chown -R www-data:www-data /var/www/html/storage

EXPOSE 80
