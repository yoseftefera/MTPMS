# ─────────────────────────────────────────────────────────────────────────────
# Stage 1: Base — PHP 8.3 with required extensions
# ─────────────────────────────────────────────────────────────────────────────
FROM php:8.3-fpm-alpine AS base

LABEL maintainer="PMP Team"
LABEL description="Procurement Management Platform — Laravel 12 Backend"

# Install system dependencies
RUN apk add --no-cache \
    bash \
    curl \
    git \
    unzip \
    libzip-dev \
    libpng-dev \
    libjpeg-turbo-dev \
    freetype-dev \
    icu-dev \
    oniguruma-dev \
    libxml2-dev \
    linux-headers \
    $PHPIZE_DEPS

# Install PHP extensions
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
        pdo \
        pdo_mysql \
        mbstring \
        exif \
        pcntl \
        bcmath \
        gd \
        zip \
        intl \
        xml \
        opcache \
        sockets

# Install Redis extension via PECL
RUN pecl install redis \
    && docker-php-ext-enable redis

# Install Composer
COPY --from=composer:2.7 /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /var/www/html

# ─────────────────────────────────────────────────────────────────────────────
# Stage 2: Development
# ─────────────────────────────────────────────────────────────────────────────
FROM base AS development

# Development PHP configuration
RUN cp "$PHP_INI_DIR/php.ini-development" "$PHP_INI_DIR/php.ini"

COPY docker/php/local.ini "$PHP_INI_DIR/conf.d/local.ini"

# Install Xdebug for code coverage
RUN pecl install xdebug \
    && docker-php-ext-enable xdebug

# Copy application files
COPY --chown=www-data:www-data . .

# Install all dependencies (including dev)
RUN composer install --no-interaction --prefer-dist

# Set permissions
RUN chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache \
    && chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache

EXPOSE 9000

CMD ["php-fpm"]

# ─────────────────────────────────────────────────────────────────────────────
# Stage 3: Production
# ─────────────────────────────────────────────────────────────────────────────
FROM base AS production

# Production PHP configuration
RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

COPY docker/php/production.ini "$PHP_INI_DIR/conf.d/production.ini"

# Copy application files
COPY --chown=www-data:www-data . .

# Install production dependencies only
RUN composer install --no-interaction --prefer-dist --optimize-autoloader --no-dev

# Optimize Laravel for production
RUN php artisan config:cache \
    && php artisan route:cache \
    && php artisan view:cache \
    && php artisan event:cache

# Set permissions
RUN chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache \
    && chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache

# Remove development files
RUN rm -rf tests/ .env.example .env.testing

EXPOSE 9000

CMD ["php-fpm"]
