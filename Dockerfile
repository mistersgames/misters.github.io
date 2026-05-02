FROM php:8.2-cli

# Установка SQLite3
RUN apt-get update && apt-get install -y sqlite3 libsqlite3-dev && docker-php-ext-install sqlite3

# Рабочая папка
WORKDIR /app

# Копируем все файлы бота
COPY . /app/

# Запускаем встроенный PHP-сервер на порту 3000 (bothost ждёт именно его)
CMD ["php", "-S", "0.0.0.0:3000", "-t", "/app"]
