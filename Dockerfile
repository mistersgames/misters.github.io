FROM php:8.2-apache

RUN apt-get update && apt-get install -y php8.2-sqlite3 && apt-get clean

COPY . /var/www/html/

RUN chown -R www-data:www-data /var/www/html && chmod -R 755 /var/www/html

EXPOSE 80
