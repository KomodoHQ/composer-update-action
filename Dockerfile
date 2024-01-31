FROM php:7.4-cli

# php
RUN apt-get update
RUN apt-get install -yq git libzip-dev unzip build-essential libssl-dev zlib1g-dev libpng-dev libjpeg-dev libfreetype6-dev
RUN docker-php-ext-install zip
RUN docker-php-ext-install -j$(nproc) zip

RUN docker-php-ext-configure gd --with-freetype-dir=/usr/include/ --with-jpeg-dir=/usr/include/ \
    && docker-php-ext-install gd

# composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /root
COPY ./update /root

RUN composer install --no-dev --no-interaction --no-progress --no-scripts

COPY entrypoint.sh /entrypoint.sh

ENTRYPOINT ["/entrypoint.sh"]
