FROM php:8.2-apache

# PDO MySQL + mbstring (needed by the IMAP library)
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends unzip libonig-dev; \
    docker-php-ext-install pdo_mysql mbstring; \
    rm -rf /var/lib/apt/lists/*

# Serve from /public and allow .htaccess
RUN set -eux; \
    sed -ri 's!DocumentRoot /var/www/html!DocumentRoot /var/www/html/public!' /etc/apache2/sites-available/000-default.conf; \
    printf '<Directory /var/www/html/public>\n    AllowOverride All\n    Require all granted\n</Directory>\n' > /etc/apache2/conf-available/docroot.conf; \
    a2enconf docroot; \
    a2enmod rewrite headers

# PHP dependencies (webklex/php-imap) via Composer, installed outside the web root
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /var/www/html
COPY composer.json composer.lock /var/www/html/
RUN composer install --no-dev --no-interaction --prefer-dist --no-progress

COPY public/ /var/www/html/public/
COPY lib/ /var/www/html/lib/
RUN chown -R www-data:www-data /var/www/html

EXPOSE 80
