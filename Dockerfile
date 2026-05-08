FROM php:8.2-cli

# Устанавливаем pdo_sqlite (если нужно)
RUN apt-get update && apt-get install -y sqlite3 libsqlite3-dev && docker-php-ext-install pdo_sqlite

WORKDIR /app
COPY . /app/

# Запускаем встроенный сервер на порту 3000 (Bothost ждёт 3000)
CMD ["php", "-S", "0.0.0.0:3000", "-t", "/app"]
