# PHP 8.4-FPM for Laravel API (CBR Currency) — matches composer.lock (Symfony 8 / PHP 8.4)
# Extensions: pdo_pgsql, redis, xml, iconv, and Laravel requirements
FROM php:8.4-fpm-bookworm

# Install system deps and PHP extensions
RUN apt-get update && apt-get install -y --no-install-recommends \
    libpq-dev \
    libxml2-dev \
    libzip-dev \
    unzip \
    && docker-php-ext-configure pdo_pgsql \
    && docker-php-ext-install -j$(nproc) \
    pdo \
    pdo_pgsql \
    bcmath \
    opcache \
    pcntl \
    xml \
    zip \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# iconv is usually bundled in PHP; ensure it's enabled
RUN docker-php-ext-enable iconv 2>/dev/null || true

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER=1

WORKDIR /var/www/html

# Copy application code (vendor excluded via .dockerignore)
COPY . .

# Install dependencies; --no-dev for production image
RUN composer install --no-dev --optimize-autoloader --no-interaction

# Entrypoint: run composer install when vendor is missing (e.g. bind-mounted code)
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

# Ensure storage and cache are writable
RUN chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache \
    && chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache

EXPOSE 9000

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["php-fpm"]
