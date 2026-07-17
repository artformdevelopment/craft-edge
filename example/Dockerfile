FROM php:8.3-fpm-alpine

COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions bcmath gd intl pdo_mysql zip opcache \
    && apk add --no-cache git unzip mysql-client

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Local example only: run as root so bind-mounted project files are writable
# regardless of the host OS / Docker file-sharing driver.
RUN sed -i 's/^user = www-data/user = root/; s/^group = www-data/group = root/' /usr/local/etc/php-fpm.d/www.conf

WORKDIR /app

CMD ["php-fpm", "-R"]

