FROM php:8.3-apache

# System deps + PHP extensions
RUN a2enmod rewrite \
  && apt-get update \
  && apt-get install -y --no-install-recommends \
      git unzip ca-certificates libzip-dev libcurl4-openssl-dev \
  && docker-php-ext-install pdo pdo_mysql zip curl \
  && rm -rf /var/lib/apt/lists/*

# Avoid "could not reliably determine the server's fully qualified domain name"
RUN echo "ServerName localhost" > /etc/apache2/conf-available/servername.conf \
  && a2enconf servername

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html
COPY . .

RUN composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader

RUN chown -R www-data:www-data /var/www/html

# Render startup script: force Apache to listen on $PORT
RUN set -eux; \
  cat > /usr/local/bin/render-start <<'SH' ; \
#!/bin/sh
set -eu

PORT="${PORT:-10000}"

echo "Using PORT=$PORT"

# Update Apache to listen on the Render port
sed -i "s/^Listen .*/Listen ${PORT}/" /etc/apache2/ports.conf

# Update vhost ports (HTTP + SSL config files just in case)
sed -i "s/<VirtualHost \*:80>/<VirtualHost *:${PORT}>/" /etc/apache2/sites-available/000-default.conf || true
sed -i "s/<VirtualHost _default_:443>/<VirtualHost _default_:${PORT}>/" /etc/apache2/sites-available/default-ssl.conf || true

# Print effective listen/vhost lines for debugging in Render logs
echo "---- ports.conf ----"
grep -n "Listen" /etc/apache2/ports.conf || true
echo "---- 000-default.conf ----"
grep -n "VirtualHost" /etc/apache2/sites-available/000-default.conf || true

exec apache2-foreground
SH
  chmod +x /usr/local/bin/render-start

EXPOSE 80
CMD ["/usr/local/bin/render-start"]
