FROM php:8.3-apache

RUN docker-php-ext-install pdo_mysql

RUN printf '%s\n' \
    '<Directory "/var/www/html/includes">' \
    'Require all denied' \
    '</Directory>' \
    '<Directory "/var/www/html/database">' \
    'Require all denied' \
    '</Directory>' \
    '<Directory "/var/www/html/.git">' \
    'Require all denied' \
    '</Directory>' \
    '<Directory "/var/www/html/.github">' \
    'Require all denied' \
    '</Directory>' \
    '<Directory "/var/www/html/docs">' \
    'Require all denied' \
    '</Directory>' \
    '<Directory "/var/www/html/scripts">' \
    'Require all denied' \
    '</Directory>' \
    '<Directory "/var/www/html/tests">' \
    'Require all denied' \
    '</Directory>' \
    '<Directory "/var/www/html/backups">' \
    'Require all denied' \
    '</Directory>' \
    '<FilesMatch "^\.">' \
    'Require all denied' \
    '</FilesMatch>' \
    > /etc/apache2/conf-available/sportshub-private.conf \
    && a2enconf sportshub-private

# Enforce upload restrictions in Apache configuration. The default document
# directory does not guarantee that per-directory .htaccess rules are enabled.
RUN printf '%s\n' \
    '<Directory "/var/www/html/assets/uploads">' \
    'Options -Indexes -ExecCGI' \
    '<FilesMatch "\.(?:php[0-9]?|phtml|phar|cgi|pl|sh)$">' \
    'Require all denied' \
    '</FilesMatch>' \
    '</Directory>' \
    >> /etc/apache2/conf-available/sportshub-private.conf

# Baseline browser protections; TLS and production rate limits belong at the edge.
RUN a2enmod headers \
    && printf '%s\n' \
    'Header always set X-Content-Type-Options "nosniff"' \
    'Header always set X-Frame-Options "SAMEORIGIN"' \
    'Header always set Referrer-Policy "strict-origin-when-cross-origin"' \
    'ServerTokens Prod' \
    'ServerSignature Off' \
    > /etc/apache2/conf-available/sportshub-security.conf \
    && a2enconf sportshub-security
