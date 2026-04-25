# ╔══════════════════════════════════════════════════════════════════════════════╗
# ║  Dockerfile — Multi-stage production build                                  ║
# ║  PHP 8.4-FPM Alpine — hardened, non-root, minimal attack surface           ║
# ║  PostgreSQL + Filament + Livewire variant                                   ║
# ╚══════════════════════════════════════════════════════════════════════════════╝

# ─── Stage 1: Composer dependencies ─────────────────────────────────────────
FROM composer:2.8 AS composer

WORKDIR /app

COPY composer.json composer.lock ./

RUN composer install \
    --ignore-platform-reqs \
    --no-interaction \
    --no-dev \
    --no-progress \
    --no-scripts \
    --prefer-dist \
    --optimize-autoloader

# ─── Stage 2: Node/Vite assets ───────────────────────────────────────────────
FROM node:20-alpine AS assets

ARG VITE_APP_NAME
ARG VITE_APP_ENV
ARG VITE_REVERB_APP_KEY
ARG VITE_REVERB_HOST
ARG VITE_REVERB_PORT
ARG VITE_REVERB_SCHEME

ENV VITE_APP_NAME=${VITE_APP_NAME}
ENV VITE_APP_ENV=${VITE_APP_ENV}
ENV VITE_REVERB_APP_KEY=${VITE_REVERB_APP_KEY}
ENV VITE_REVERB_HOST=${VITE_REVERB_HOST}
ENV VITE_REVERB_PORT=${VITE_REVERB_PORT}
ENV VITE_REVERB_SCHEME=${VITE_REVERB_SCHEME}

WORKDIR /app

COPY --from=composer /app/vendor/ /app/vendor/

COPY package*.json ./
RUN npm ci --ignore-scripts

COPY . .

RUN npm run build

# ─── Stage 3: Production runtime ─────────────────────────────────────────────
FROM php:8.4-fpm-alpine AS production

ARG IMAGE_TITLE="laravel-app"
ARG IMAGE_SOURCE=""
ARG IMAGE_REVISION=""
ARG BUILD_MODE="unknown"
LABEL org.opencontainers.image.title="${IMAGE_TITLE}"
LABEL org.opencontainers.image.source="${IMAGE_SOURCE}"
LABEL org.opencontainers.image.revision="${IMAGE_REVISION}"
LABEL org.opencontainers.image.description="Reusable Laravel deployment-kit runtime image"
LABEL build.mode="${BUILD_MODE}"

# ── System dependencies ───────────────────────────────────────────────────────
RUN apk add --no-cache \
    # Runtime
    postgresql-client \
    libpq \
    fcgi \
    libzip \
    icu \
    oniguruma \
    libpng \
    libjpeg-turbo \
    libwebp \
    freetype \
    # Build deps (removed in same layer)
    && apk add --no-cache --virtual .build-deps \
        $PHPIZE_DEPS \
        postgresql-dev \
        libzip-dev \
        icu-dev \
        oniguruma-dev \
        libpng-dev \
        libjpeg-turbo-dev \
        libwebp-dev \
        freetype-dev \
    # PHP extensions — PostgreSQL + Filament
    && docker-php-ext-configure gd \
        --with-freetype \
        --with-jpeg \
        --with-webp \
    && docker-php-ext-install \
        pdo_pgsql \
        pgsql \
        zip \
        mbstring \
        intl \
        opcache \
        pcntl \
        bcmath \
        exif \
        gd \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del .build-deps \
    && rm -rf /var/cache/apk/* /tmp/pear

# ── PHP configuration ─────────────────────────────────────────────────────────
COPY deployment/config/php.ini /usr/local/etc/php/conf.d/99-production.ini
COPY deployment/config/www.conf /usr/local/etc/php-fpm.d/www.conf

# ── Non-root user ─────────────────────────────────────────────────────────────
RUN addgroup -g 1000 -S appgroup 2>/dev/null || true \
    && adduser -u 1000 -S -G appgroup -s /bin/sh appuser 2>/dev/null || true

# ── Application files ─────────────────────────────────────────────────────────
WORKDIR /var/www/html

COPY --from=composer --chown=appuser:appgroup /app/vendor ./vendor
COPY --from=assets   --chown=appuser:appgroup /app/public/build ./public/build
COPY                 --chown=appuser:appgroup . .

# ── Writable directories ───────────────────────────────────────────────────────
RUN mkdir -p \
        storage/app/public \
        storage/framework/cache/data \
        storage/framework/sessions \
        storage/framework/testing \
        storage/framework/views \
        storage/logs \
        bootstrap/cache

# ── Cache warmup ──────────────────────────────────────────────────────────────
RUN php artisan optimize:clear --no-ansi 2>/dev/null || true
RUN php artisan route:cache --no-ansi 2>/dev/null || true \
    && php artisan view:cache  --no-ansi 2>/dev/null || true

# ── Fix permissions (Must run AFTER cache warmup in case root created logs) ───
RUN chown -R appuser:appgroup storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

USER appuser

EXPOSE 9000

CMD ["php-fpm", "--nodaemonize"]
