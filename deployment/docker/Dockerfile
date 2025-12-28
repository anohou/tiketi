# =============================================================================
# Multi-Stage Dockerfile for Full-Stack Laravel (PHP 8.3 + Node.js + Vite)
# =============================================================================
# Based on proven working anohou-web-new configuration with frontend build stage
# =============================================================================

# -----------------------------------------------------------------------------
# Stage 1: Frontend Builder (Node.js + Vite)
# -----------------------------------------------------------------------------
FROM node:20-alpine AS frontend-builder

# Build argument for Vite base path (e.g., /billeterie-stg-xxx or /)
ARG APP_BASE_PATH=/

WORKDIR /app

# Install minimal PHP and Composer for vendor dependencies
RUN apk add --no-cache curl php83 php83-phar php83-mbstring php83-openssl \
    && ln -s /usr/bin/php83 /usr/bin/php \
    && curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# Copy composer files and install PHP vendor dependencies
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --prefer-dist --no-interaction --ignore-platform-reqs

# Copy package files for npm dependencies
COPY package*.json ./

# Install all npm dependencies (including devDependencies for build)
RUN npm ci --production=false

# Copy source files for Vite compilation
COPY resources/ resources/
COPY vite.config.js ./
COPY tailwind.config.js* postcss.config.js* jsconfig.json* ./
COPY public/ public/

# Build frontend assets with Vite and base path
# Pass APP_BASE_PATH as VITE_BASE_PATH environment variable for the build
RUN VITE_BASE_PATH="${APP_BASE_PATH}" npm run build && \
    echo "Built with base path: ${APP_BASE_PATH}" && \
    ls -la public/build/

# Verify build output exists
RUN if [ ! -d "public/build" ]; then \
      echo "ERROR: Frontend build failed - public/build directory not found"; \
      exit 1; \
    fi && \
    if [ ! -f "public/build/manifest.json" ]; then \
      echo "ERROR: Frontend build failed - manifest.json not found"; \
      exit 1; \
    fi && \
    echo "✅ Frontend assets compiled successfully" && \
    ls -lah public/build/

# -----------------------------------------------------------------------------
# Stage 2: PHP/Laravel Application (php:8.3-fpm-alpine)
# -----------------------------------------------------------------------------
FROM php:8.3-fpm-alpine

LABEL maintainer="anohou"

ARG WWWGROUP
ARG NODE_VERSION=20
ARG WWWUSER=sail
ARG HOST_UID=1001
ARG HOST_GID=1001
ARG DOCKER_GID=1002

WORKDIR /var/www/html

ENV TZ=UTC
ENV SUPERVISOR_PHP_COMMAND="/usr/local/bin/php -d variables_order=EGPCS /var/www/html/artisan serve --host=0.0.0.0 --port=9000"
ENV SUPERVISOR_PHP_USER="sail"

# Install essential packages
RUN apk add --no-cache \
    bash \
    curl \
    zip \
    unzip \
    git \
    supervisor \
    sqlite \
    mysql-client

# Install Node.js and npm
RUN apk add --no-cache \
    nodejs \
    npm \
    && npm install -g npm

# Install PHP extensions
RUN apk add --no-cache \
    libpng-dev \
    libzip-dev \
    oniguruma-dev \
    icu-dev \
    gettext \
    && docker-php-ext-install pdo_mysql bcmath gd intl zip pcntl

# Configure and install GD with JPEG support
RUN apk add --no-cache \
    libjpeg-turbo-dev \
    && docker-php-ext-configure gd --with-jpeg \
    && docker-php-ext-install gd

# Install PHP mail extensions
RUN apk add --no-cache \
    msmtp \
    msmtp-doc \
    ca-certificates \
    mailx \
    && docker-php-ext-install opcache

# Install Redis PHP extension
RUN apk add --no-cache --virtual .build-deps $PHPIZE_DEPS \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del .build-deps

# Install and configure Nginx
RUN apk add --no-cache nginx \
    && mkdir -p /var/lib/nginx/tmp \
    && mkdir -p /run/nginx

# Install debugging tools
RUN apk add --no-cache \
    busybox-extras \
    net-tools \
    tcpdump \
    strace \
    lsof \
    grep

# Install Composer
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer \
    && chmod +x /usr/local/bin/composer

# Ensure vi is available
RUN ln -sf /bin/busybox /usr/bin/vi

# Add Laravel-specific user and group
RUN addgroup -g $DOCKER_GID -S docker && \
    addgroup -g $HOST_GID -S $WWWUSER && \
    adduser -u $HOST_UID -S $WWWUSER -G $WWWUSER && \
    addgroup $WWWUSER docker

# Set ownership and permissions for Laravel directories
RUN mkdir -p /var/www/html/storage /var/www/html/bootstrap/cache && \
    chown -R $HOST_UID:$HOST_GID /var/www/html && \
    chmod -R 755 /var/www/html/storage /var/www/html/bootstrap/cache

# Create directories for config
RUN mkdir -p /home/sail/config/laravel && \
    chown -R sail:sail /home/sail

# Create directories for Supervisor and runtime
RUN mkdir -p /var/log/supervisor /var/run  && \
    chown -R sail:sail /var/log/supervisor /var/run && \
    chmod -R 755 /var/log/supervisor /var/run && \
    touch /var/run/supervisord.pid && \
    chmod 777 /var/run/supervisord.pid

# Configure Supervisor
RUN mkdir -p /etc/supervisor/conf.d && \
    chown -R sail:sail /etc/supervisor && \
    chmod -R 755 /etc/supervisor

# Set nginx directory permissions (after sail user exists)
RUN chown -R sail:sail /var/lib/nginx /var/log/nginx /run/nginx

# Copy application code
COPY --chown=sail:sail . /var/www/html

# Install Composer dependencies
RUN composer install --no-dev --optimize-autoloader --no-interaction

# Copy built frontend assets from frontend-builder stage (AFTER app code to avoid overwriting)
# Debug: verify the source exists in the builder stage before copying
RUN --mount=from=frontend-builder,source=/app/public/build,target=/tmp/check-build ls -la /tmp/check-build/ || echo "WARNING: No files in frontend-builder public/build"
COPY --from=frontend-builder --chown=sail:sail /app/public/build /var/www/html/public/build

# IMPORTANT: Backup built assets for restoration when volume mounts overwrite them
# When entire project is mounted, the build directory gets overwritten by the empty local version
# This backup allows startup.sh to restore the built assets into the mounted volume
RUN mkdir -p /opt/vite-build-backup && \
    cp -r /var/www/html/public/build/* /opt/vite-build-backup/ 2>/dev/null || true

# Ensure all files are owned by sail (including auto-generated from composer)
RUN chown -R sail:sail /var/www/html && \
    chmod -R 755 /var/www/html/storage /var/www/html/bootstrap/cache

# Copy nginx configuration
COPY deployment/docker/nginx/default.conf /etc/nginx/http.d/default.conf

# Configure PHP-FPM to listen on port 9001
# Disable default www.conf and create our own
RUN mv /usr/local/etc/php-fpm.d/www.conf /usr/local/etc/php-fpm.d/www.conf.disabled && \
    echo "[www]" > /usr/local/etc/php-fpm.d/www.conf && \
    echo "user = sail" >> /usr/local/etc/php-fpm.d/www.conf && \
    echo "group = sail" >> /usr/local/etc/php-fpm.d/www.conf && \
    echo "listen = 127.0.0.1:9001" >> /usr/local/etc/php-fpm.d/www.conf && \
    echo "pm = dynamic" >> /usr/local/etc/php-fpm.d/www.conf && \
    echo "pm.max_children = 50" >> /usr/local/etc/php-fpm.d/www.conf && \
    echo "pm.start_servers = 5" >> /usr/local/etc/php-fpm.d/www.conf && \
    echo "pm.min_spare_servers = 5" >> /usr/local/etc/php-fpm.d/www.conf && \
    echo "pm.max_spare_servers = 35" >> /usr/local/etc/php-fpm.d/www.conf && \
    rm -f /usr/local/etc/php-fpm.d/zz-docker.conf

# Copy supervisord config
COPY deployment/docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf

# Copy scripts
COPY deployment/docker/scripts/startup.sh /usr/local/bin/startup.sh
COPY deployment/docker/scripts/generate-env.sh /usr/local/bin/generate-env.sh

RUN chmod +x /usr/local/bin/startup.sh \
    /usr/local/bin/generate-env.sh

# Install sudo
RUN apk add --no-cache sudo

# Configure sudo access
RUN echo "sail ALL=(ALL) NOPASSWD: /bin/chown, /bin/chmod, /usr/bin/find" >> /etc/sudoers

# Install Python for deployment scripts
RUN apk add --no-cache python3 py3-yaml

USER sail

EXPOSE 9000

ENTRYPOINT ["/bin/bash", "/usr/local/bin/startup.sh"]
