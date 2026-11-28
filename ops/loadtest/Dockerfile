# Load-test image: php-fpm 8.3 with opcache, production-like (no dev dependencies, cached config).
# Build context is the repository root (see compose.yml); Dockerfile.dockerignore keeps web/, e2e/, docs/, .env and vendor out. Pinned by digest; Dependabot's docker ecosystem keeps it current.
FROM php:8.3-fpm@sha256:f4156d29d5d5926242b563ae311a493b2718154d3030335564a3078638a9793c

RUN apt-get update  && apt-get install -y --no-install-recommends libpq-dev libzip-dev unzip git curl  && docker-php-ext-install pdo_pgsql opcache zip  && rm -rf /var/lib/apt/lists/*  && curl -sS https://getcomposer.org/installer | php -- --quiet --install-dir=/usr/local/bin --filename=composer

COPY ops/loadtest/php.ini /usr/local/etc/php/conf.d/zz-loadtest.ini
COPY ops/loadtest/fpm.conf /usr/local/etc/php-fpm.d/zz-loadtest.conf

WORKDIR /var/www/html
COPY api/composer.json api/composer.lock ./
RUN composer install --no-dev --optimize-autoloader --no-scripts --no-interaction
COPY api/ ./
RUN composer dump-autoload --optimize --no-dev --no-scripts  && mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache  && chown -R www-data:www-data storage bootstrap/cache
