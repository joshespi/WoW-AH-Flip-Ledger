# src/ is bind-mounted at runtime (docker-compose.yml): this image ships no app code,
# vendor/, node_modules/ or public/build.
FROM php:8.5-fpm

RUN apt-get update && apt-get install -y --no-install-recommends \
        git \
        curl \
        unzip \
        zip \
        libzip-dev \
        libicu-dev \
        libonig-dev \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# pdo_sqlite is built in; phpunit uses it. OPcache config is a Phase 10 job.
RUN docker-php-ext-install pdo_mysql mbstring pcntl bcmath intl zip

COPY docker/php/zz-app.ini /usr/local/etc/php/conf.d/zz-app.ini
COPY docker/php/zzz-laravel.conf /usr/local/etc/php-fpm.d/zzz-laravel.conf
COPY docker/php/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

# Node + npm so `npm run build` runs inside this container (no separate Node service). The
# Debian (glibc) node image is used: the alpine one is musl and won't run on this base.
COPY --from=node:24-slim /usr/local/bin/node /usr/local/bin/node
COPY --from=node:24-slim /usr/local/lib/node_modules/npm /usr/local/lib/node_modules/npm
RUN ln -s ../lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm \
    && ln -s ../lib/node_modules/npm/bin/npx-cli.js /usr/local/bin/npx

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

ENTRYPOINT ["/entrypoint.sh"]
CMD ["php-fpm"]
