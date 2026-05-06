FROM php:8.2-apache

# Очищаем кэш apt и обновляемся с ретраями
RUN apt-get clean && rm -rf /var/lib/apt/lists/* \
    && apt-get update -o Acquire::Retries=3 \
    && apt-get install -y --no-install-recommends sqlite3 libsqlite3-dev \
    && docker-php-ext-install sqlite3 \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

COPY . /var/www/html/

RUN chown -R www-data:www-data /var/www/html && chmod -R 755 /var/www/html

EXPOSE 80
