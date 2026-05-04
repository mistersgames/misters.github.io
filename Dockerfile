FROM php:8.2-apache

# Установка системных зависимостей и SQLite3 для PHP
RUN apt-get update && apt-get install -y \
    sqlite3 \
    libsqlite3-dev \
    && docker-php-ext-install sqlite3 \
    && docker-php-ext-enable sqlite3 \
    && a2enmod rewrite

# Копируем весь код бота
COPY . /var/www/html/

# Права доступа (apache должен иметь возможность писать в БД и логи)
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html \
    && chmod 777 /var/www/html/data 2>/dev/null || true

# Переменная окружения (если нужно, замените на ваш токен, но лучше через bothost)
# ENV BOT_TOKEN=your_token_here

EXPOSE 80
