# API server image for a test or small deploy (Railway, Render, any Docker host).
# By default one container runs the web server, the queue worker (emails, texts) and the scheduler
# (unpaid online orders expire), since uploaded photos live on one disk. See docs/DEPLOY-TEST.md.
# Packages are downloaded in the Composer image (it has unzip); only the vendor folder is kept.
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-scripts --no-autoloader --prefer-dist --no-progress --ignore-platform-reqs

FROM php:8.3-apache

RUN apt-get update \
 && apt-get install -y --no-install-recommends libicu-dev libjpeg62-turbo-dev libpng-dev libwebp-dev libfreetype6-dev \
 && docker-php-ext-configure gd --with-jpeg --with-webp --with-freetype \
 && docker-php-ext-install -j"$(nproc)" pdo_mysql gd exif intl opcache \
 && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY docker/php.ini "$PHP_INI_DIR/conf.d/zz-marketplace.ini"
# Named to load after Debian's security.conf, whose ServerTokens it overrides.
COPY docker/apache.conf /etc/apache2/conf-available/zz-marketplace.conf

# Apache serves public/ on $PORT (hosts set PORT; 8080 otherwise).
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public PORT=8080
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
 && sed -ri 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
 && sed -ri 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf \
 && sed -ri 's!^Listen 80$!Listen ${PORT}!' /etc/apache2/ports.conf \
 && sed -ri 's!<VirtualHost \*:80>!<VirtualHost *:${PORT}>!' /etc/apache2/sites-available/000-default.conf \
 && a2enmod rewrite headers && a2enconf zz-marketplace

WORKDIR /var/www/html
COPY --from=vendor /app/vendor ./vendor
COPY . .
RUN composer dump-autoload --optimize --no-dev --no-interaction \
 && ln -sfn ../storage/app/public public/storage \
 && chmod +x docker/start.sh \
 && chown -R www-data:www-data storage bootstrap/cache

# Logs go to the host's log viewer. Override any of these in the host's variables.
ENV APP_ENV=production APP_DEBUG=false LOG_CHANNEL=stderr LOG_LEVEL=info MAIL_LOG_CHANNEL=mail ROLE=all

EXPOSE 8080
CMD ["docker/start.sh"]
