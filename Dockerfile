# Используем стабильную версию PHP с официального образа
FROM php:8.2-cli

# Устанавливаем необходимые для SQLite3 пакеты и само расширение
RUN apt-get update && apt-get install -y sqlite3 libsqlite3-dev && docker-php-ext-install sqlite3

# Задаем рабочую директорию
WORKDIR /app

# Копируем все файлы из контекста сборки в образ
COPY . .

# Запускаем встроенный PHP-сервер, который и будет слушать порт 3000
CMD ["php", "-S", "0.0.0.0:3000", "-t", "/app"]
