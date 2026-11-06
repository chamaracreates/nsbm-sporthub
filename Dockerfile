FROM php:8.3-apache

RUN docker-php-ext-install pdo_mysql

RUN printf '%s\n' \
    '<Directory "/var/www/html/includes">' \
    'Require all denied' \
    '</Directory>' \
    '<Directory "/var/www/html/database">' \
    'Require all denied' \
    '</Directory>' \
    '<FilesMatch "^\.">' \
    'Require all denied' \
    '</FilesMatch>' \
    > /etc/apache2/conf-available/sportshub-private.conf \
    && a2enconf sportshub-private
