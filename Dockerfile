FROM php:8.3-apache

# Enable rewrite + install PDO MySQL
RUN a2enmod rewrite \
  && docker-php-ext-install pdo pdo_mysql

# Install system tools Composer needs (git + unzip) + CA certs
RUN apt-get update \
  && apt-get install -y --no-install-recommends git unzip ca-certificates \
  && rm -rf /var/lib/apt/lists/*

# Optional but good: enable PHP zip extension (helps Composer with dist zips)
RUN apt-get update \
  && apt-get install -y --no-install-recommends libzip-dev \
  && docker-php-ext-install zip \
  && rm -rf /var/lib/apt/lists/*

RUN apt-get update \
  && apt-get install -y --no-install-recommends libcurl4-openssl-dev \
  && docker-php-ext-install curl \
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

EXPOSE 80
