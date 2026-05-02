# Задаем базовый образ с PHP 8.2 и Apache
FROM php:8.2-apache

# Устанавливаем необходимые зависимости и модуль SQLite3
RUN apt-get update && apt-get install -y sqlite3 libsqlite3-dev && docker-php-ext-install sqlite3

# Копируем все файлы с кодом вашего бота в рабочую папку веб-сервера
COPY . /var/www/html/

# (Опционально) Настраиваем права доступа
RUN chown -R www-data:www-data /var/www/html && chmod -R 755 /var/www/html

# Сообщаем Docker, что контейнер слушает 80-й порт
EXPOSE 80
