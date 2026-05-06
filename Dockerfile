FROM php:8.2-apache

# Устанавливаем системные пакеты и включаем PDO SQLite
RUN apt-get update && apt-get install -y sqlite3 libsqlite3-dev \
    && docker-php-ext-install pdo_sqlite \
    && docker-php-ext-enable pdo_sqlite \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

COPY . /var/www/html/

RUN chown -R www-data:www-data /var/www/html && chmod -R 755 /var/www/html

EXPOSE 80
