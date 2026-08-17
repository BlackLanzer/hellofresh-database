FROM php:8.4-fpm-alpine

# Install system dependencies + Node.js
RUN apk add --no-cache \
    postgresql-dev \
    libpng-dev \
    libjpeg-turbo-dev \
    freetype-dev \
    libwebp-dev \
    libzip-dev \
    oniguruma-dev \
    icu-dev \
    git \
    curl \
    unzip \
    linux-headers \
    autoconf \
    g++ \
    make \
    nodejs \
    npm \
    && npm install -g pnpm@10

# Configure and install PHP extensions
RUN docker-php-ext-configure gd \
    --with-freetype \
    --with-jpeg \
    --with-webp \
    && docker-php-ext-install -j"$(nproc)" \
    pdo \
    pdo_pgsql \
    pgsql \
    gd \
    zip \
    bcmath \
    mbstring \
    intl \
    pcntl \
    exif \
    opcache

# Install phpredis
RUN pecl install redis \
    && docker-php-ext-enable redis

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Copy application source
COPY . .

# Install PHP dependencies (skip scripts — services like Redis aren't available at build time)
RUN composer install --no-interaction --optimize-autoloader --no-scripts

# Build frontend assets
RUN pnpm install --frozen-lockfile && pnpm run build

# Set correct permissions
RUN chown -R www-data:www-data storage bootstrap/cache

# Entrypoint
COPY docker/php/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 9000

ENTRYPOINT ["entrypoint.sh"]
CMD ["php-fpm"]
