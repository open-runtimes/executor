# Install PHP libraries
FROM composer:2.8@sha256:5248900ab8b5f7f880c2d62180e40960cd87f60149ec9a1abfd62ac72a02577c AS composer

WORKDIR /usr/local/src/

COPY composer.lock composer.json ./

RUN composer install --ignore-platform-reqs --optimize-autoloader \
    --no-plugins --no-scripts --prefer-dist

# Executor
FROM appwrite/utopia-base:php-8.5-2.1.7@sha256:aa123546fa6cdd6e710107a79b977274f373b08b6186cabb6c16f1937fe1db05 AS final

RUN apk add --no-cache docker-cli

WORKDIR /usr/local

ARG OPR_EXECUTOR_VERSION
ENV OPR_EXECUTOR_VERSION=$OPR_EXECUTOR_VERSION

# Dependencies first (changes less frequently)
COPY --from=composer /usr/local/src/vendor /usr/local/vendor

# Source code last (changes more frequently)
COPY ./app /usr/local/app
COPY ./src /usr/local/src

HEALTHCHECK --interval=30s --timeout=15s --start-period=60s --retries=3 CMD curl -sf http://127.0.0.1:80/v1/health

CMD [ "php", "app/http.php" ]
