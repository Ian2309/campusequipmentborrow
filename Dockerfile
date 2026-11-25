# PHP 8.3 + Apache for Render. Database and Storage settings come from environment variables (see README).
FROM php:8.3-apache

RUN apt-get update && apt-get install -y --no-install-recommends libpq-dev libpng-dev libjpeg-dev \
 && docker-php-ext-configure gd --with-jpeg \
 && docker-php-ext-install pdo_pgsql gd \
 && a2enmod headers rewrite \
 && sed -i 's/AllowOverride None/AllowOverride All/' /etc/apache2/apache2.conf \
 && rm -rf /var/lib/apt/lists/*

# Render tells the app which port to use through $PORT (10000 by default).
ENV PORT=10000
RUN sed -i 's/Listen 80/Listen ${PORT}/' /etc/apache2/ports.conf \
 && sed -i 's/<VirtualHost \*:80>/<VirtualHost *:${PORT}>/' /etc/apache2/sites-available/000-default.conf

RUN printf "output_buffering=4096\nlog_errors=On\n" > /usr/local/etc/php/conf.d/app.ini
COPY . /var/www/html/
RUN mkdir -p /var/www/html/uploads/equipment /var/www/html/backups \
 && chown -R www-data:www-data /var/www/html/uploads /var/www/html/backups

EXPOSE 10000
