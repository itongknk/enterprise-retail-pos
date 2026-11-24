FROM php:8.4-fpm-alpine
RUN apk add --no-cache \
    git \
    curl \
    libpng-dev \
    libzip-dev \
    icu-dev \
    postgresql-dev \
    oniguruma-dev \
    netcat-openbsd \
    && docker-php-ext-install \
        pdo \
        pdo_pgsql \
        pgsql \
        bcmath \
        intl \
        zip \
        opcache

# ติดตั้ง Redis Extension
RUN apk add --no-cache --virtual .build-deps $PHPIZE_DEPS \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del .build-deps

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/backend

RUN addgroup -g 1000 appgroup && adduser -u 1000 -G appgroup -s /bin/sh -D appuser
USER appuser

EXPOSE 9000
CMD ["php-fpm"]