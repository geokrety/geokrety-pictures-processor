FROM php:7.4-apache-buster
LABEL maintainer="GeoKrety Team <contact@geokrety.org>"
ARG TIMEZONE=Europe/Paris

ENV GK_PP_TMP_DIR=/tmp/gk-pictures-processor-tmp

WORKDIR /var/www/geokrety
ENTRYPOINT ["/geokrety-entrypoint.sh"]
CMD ["apache2-foreground"]

COPY docker/files/ /

RUN apt-get update \
    && apt-get install -y \
        unzip \
        libmagickwand-dev \
    && apt-get clean \
    && rm -r /var/lib/apt/lists/* \
    \
    && a2enmod rewrite \
    && pecl install imagick \
    && docker-php-ext-enable imagick \
    \
    && pecl install phalcon \
    && docker-php-ext-enable --ini-name=10-psr.ini psr \
    && docker-php-ext-enable --ini-name=20-phalcon.ini phalcon \
    \
    && curl -sS https://getcomposer.org/installer | php -- --filename=composer --install-dir=/usr/local/bin/ \
    \
    && mkdir ${GK_PP_TMP_DIR} \
    && chown www-data:www-data ${GK_PP_TMP_DIR} \
    && chmod +x /geokrety-entrypoint.sh

COPY --chown=www-data:www-data composer.json /var/www/geokrety/composer.json
COPY --chown=www-data:www-data composer.lock /var/www/geokrety/composer.lock
RUN composer install --no-scripts --no-dev --no-autoloader --no-interaction
COPY src /var/www/geokrety/src
