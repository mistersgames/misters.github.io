FROM php:8.2-apache-bullseye

RUN apt-get update && apt-get install -y sqlite3 libsqlite3-dev && docker-php-ext-install sqlite3

COPY . /var/www/html/

RUN chown -R www-data:www-data /var/www/html && chmod -R 755 /var/www/html

EXPOSE 80
