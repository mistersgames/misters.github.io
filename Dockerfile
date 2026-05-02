FROM php:8.2-apache

# Устанавливаем SQLite3 расширение
RUN apt-get update && apt-get install -y sqlite3 libsqlite3-dev && docker-php-ext-install sqlite3

# Копируем весь код бота
COPY . /var/www/html/

# Права доступа
RUN chown -R www-data:www-data /var/www/html && chmod -R 755 /var/www/html

# Включаем модуль rewrite (не обязательно)
RUN a2enmod rewrite
