FROM php:8.2-apache

# Перенаправляем ошибки Apache в stderr (чтобы видеть их в логах bothost)
RUN echo "ErrorLog /dev/stderr" >> /etc/apache2/apache2.conf

# Копируем файлы
COPY . /var/www/html/

# Даём права (чтобы Apache мог писать при необходимости)
RUN chown -R www-data:www-data /var/www/html && chmod -R 755 /var/www/html

EXPOSE 80
