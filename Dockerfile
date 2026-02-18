FROM php:8.3-apache

# Enable rewrite + install extensions
RUN a2enmod rewrite \
  && apt-get update \
  && apt-get install -y --no-install-recommends \
      git unzip ca-certificates libzip-dev libcurl4-openssl-dev \
  && docker-php-ext-install pdo pdo_mysql zip curl \
  && rm -rf /var/lib/apt/lists/*

# Avoid Apache ServerName warning
RUN echo "ServerName localhost" >> /etc/apache2/apache2.conf

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html
COPY . .

RUN composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader

RUN chown -R www-data:www-data /var/www/html

# Create startup script properly
RUN echo '#!/bin/sh' > /usr/local/bin/render-start && \
    echo 'set -e' >> /usr/local/bin/render-start && \
    echo 'PORT=${PORT:-10000}' >> /usr/local/bin/render-start && \
    echo 'echo "Using PORT=$PORT"' >> /usr/local/bin/render-start && \
    echo 'sed -i "s/^Listen .*/Listen ${PORT}/" /etc/apache2/ports.conf' >> /usr/local/bin/render-start && \
    echo 'sed -i "s/<VirtualHost \*:80>/<VirtualHost *:${PORT}>/" /etc/apache2/sites-available/000-default.conf' >> /usr/local/bin/render-start && \
    echo 'exec apache2-foreground' >> /usr/local/bin/render-start && \
    chmod +x /usr/local/bin/render-start

EXPOSE 80
CMD ["/usr/local/bin/render-start"]
