FROM php:8.3-apache

RUN docker-php-ext-install pdo_mysql \
 && pecl install redis \
 && docker-php-ext-enable redis \
 && a2enmod rewrite \
 && mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

COPY apache.conf /etc/apache2/conf-available/api.conf
RUN a2enconf api

COPY src/ /var/www/html/