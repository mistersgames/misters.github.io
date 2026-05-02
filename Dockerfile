FROM php:8.2-cli

# Устанавливаем SQLite3
RUN apt-get update && apt-get install -y sqlite3 libsqlite3-dev && docker-php-ext-install sqlite3

# Копируем все файлы бота в папку /app
COPY . /app

# Рабочая директория
WORKDIR /app

# Запускаем встроенный PHP-сервер, слушаем порт 3000
CMD ["php", "-S", "0.0.0.0:3000", "-t", "/app"]
