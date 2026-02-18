FROM php:8.3-apache

# Enable rewrite + install PHP extensions
RUN a2enmod rewrite \
  && apt-get update \
  && apt-get install -y --no-install-recommends \
      git unzip ca-certificates libzip-dev libcurl4-openssl-dev \
  && docker-php-ext-install pdo pdo_mysql zip curl \
  && rm -rf /var/lib/apt/lists/*

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Copy app
WORKDIR /var/www/html
COPY . .

# Install PHP deps (creates vendor/)
RUN composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader

# Permissions
RUN chown -R www-data:www-data /var/www/html

# --- Render PORT fix: bind Apache to $PORT at runtime ---
RUN printf '#!/bin/sh\nset -e\n: "${PORT:=80}"\n# Make Apache listen on Render-provided PORT\nsed -i "s/^Listen .*/Listen ${PORT}/" /etc/apache2/ports.conf\n# Update default vhost to match PORT\nsed -i "s/<VirtualHost \\*:80>/<VirtualHost *:${PORT}>/" /etc/apache2/sites-available/000-default.conf\nexec apache2-foreground\n' > /usr/local/bin/render-start \
  && chmod +x /usr/local/bin/render-start

EXPOSE 80
CMD ["render-start"]
