FROM php:8.2-apache

# PDO MySQL driver for the database
RUN docker-php-ext-install pdo_mysql

# Serve from /public and allow .htaccess
RUN set -eux; \
    sed -ri 's!DocumentRoot /var/www/html!DocumentRoot /var/www/html/public!' /etc/apache2/sites-available/000-default.conf; \
    printf '<Directory /var/www/html/public>\n    AllowOverride All\n    Require all granted\n</Directory>\n' > /etc/apache2/conf-available/docroot.conf; \
    a2enconf docroot; \
    a2enmod rewrite headers

COPY public/ /var/www/html/public/
COPY lib/ /var/www/html/lib/
RUN chown -R www-data:www-data /var/www/html

EXPOSE 80
