FROM php:8.2-apache

# Устанавливаем системные зависимости и PECL-расширение sqlite3
RUN apt-get update && apt-get install -y sqlite3 libsqlite3-dev \
    && pecl install sqlite3 \
    && docker-php-ext-enable sqlite3 \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

COPY . /var/www/html/

RUN chown -R www-data:www-data /var/www/html && chmod -R 755 /var/www/html

EXPOSE 80
