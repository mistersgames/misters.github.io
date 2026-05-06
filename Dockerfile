FROM php:8.2-apache

# Устанавливаем pdo_sqlite
RUN apt-get update && apt-get install -y sqlite3 libsqlite3-dev \
    && docker-php-ext-install pdo_sqlite \
    && docker-php-ext-enable pdo_sqlite

# Логи ошибок Apache (для видимости в логах bothost)
RUN echo "ErrorLog /dev/stderr" >> /etc/apache2/apache2.conf

COPY . /var/www/html/
RUN chown -R www-data:www-data /var/www/html && chmod -R 755 /var/www/html

EXPOSE 80
