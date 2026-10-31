FROM php:8.4-fpm-alpine

# Build-time dependencies are removed again at the end of this layer to keep
# the production image small; only the runtime libraries survive.
RUN apk add --no-cache \
    postgresql-libs \
    libpng \
    libjpeg-turbo \
    freetype \
    libwebp \
    libzip \
    oniguruma \
    icu-libs \
    curl \
    && apk add --no-cache --virtual .build-deps \
    postgresql-dev \
    libpng-dev \
    libjpeg-turbo-dev \
    freetype-dev \
    libwebp-dev \
    libzip-dev \
    oniguruma-dev \
    icu-dev \
    linux-headers \
    autoconf \
    g++ \
    make \
    && docker-php-ext-configure gd \
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
    opcache \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del .build-deps

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Dependency manifests are copied first so their layer is reused whenever only
# application code changes.
COPY composer.json composer.lock ./
RUN composer install --no-interaction --no-dev --optimize-autoloader --no-scripts --no-autoloader

COPY package.json pnpm-lock.yaml ./
RUN apk add --no-cache --virtual .node-deps nodejs npm \
    && npm install -g pnpm@10 \
    && pnpm install --frozen-lockfile

COPY . .

RUN composer dump-autoload --no-interaction --no-dev --optimize \
    && pnpm run build \
    && rm -rf node_modules \
    && npm uninstall -g pnpm \
    && apk del .node-deps

COPY production/docker/php/php.ini /usr/local/etc/php/conf.d/zz-app.ini
COPY production/docker/php/entrypoint.sh /usr/local/bin/entrypoint.sh

RUN chmod +x /usr/local/bin/entrypoint.sh \
    && chown -R www-data:www-data storage bootstrap/cache

EXPOSE 9000

ENTRYPOINT ["entrypoint.sh"]
CMD ["php-fpm"]
