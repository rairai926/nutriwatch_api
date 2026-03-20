FROM php:8.3-apache

# Enable Apache rewrite
RUN a2enmod rewrite

# Install system dependencies + PHP extensions FIRST
RUN apt-get update \
  && apt-get install -y --no-install-recommends \
      git unzip ca-certificates \
      libzip-dev \
      libpng-dev libjpeg62-turbo-dev libfreetype6-dev \
      libcurl4-openssl-dev \
  \
  # Configure GD properly
  && docker-php-ext-configure gd --with-freetype --with-jpeg \
  \
  # Install PHP extensions
  && docker-php-ext-install \
      pdo \
      pdo_mysql \
      zip \
      curl \
      gd \
  \
  && rm -rf /var/lib/apt/lists/*

# Verify extensions BEFORE composer (IMPORTANT)
RUN php -m | grep -E "gd|zip"

# Avoid Apache warning
RUN echo "ServerName localhost" >> /etc/apache2/apache2.conf

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Copy composer files
COPY composer.json composer.lock* ./

# Install dependencies (NOW gd + zip exist)
RUN composer install \
    --no-dev \
    --prefer-dist \
    --no-interaction \
    --optimize-autoloader

# Verify PhpSpreadsheet (CRITICAL DEBUG)
RUN php -r "require '/var/www/html/vendor/autoload.php'; echo class_exists('PhpOffice\\\\PhpSpreadsheet\\\\IOFactory') ? 'PHPSPREADSHEET OK\n' : 'PHPSPREADSHEET MISSING\n';"

# Copy app files
COPY . .

# Permissions
RUN chown -R www-data:www-data /var/www/html

# Render start script
RUN printf '%s\n' \
'#!/bin/sh' \
'set -e' \
'PORT=${PORT:-10000}' \
'echo "Using PORT=$PORT"' \
'sed -i "s/Listen 80/Listen ${PORT}/" /etc/apache2/ports.conf' \
'sed -i "s/<VirtualHost \\*:80>/<VirtualHost *:${PORT}>/" /etc/apache2/sites-available/000-default.conf' \
'exec apache2-foreground' \
> /usr/local/bin/render-start && chmod +x /usr/local/bin/render-start

EXPOSE 80
CMD ["/usr/local/bin/render-start"]