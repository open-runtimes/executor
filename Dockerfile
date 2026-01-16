# Install PHP libraries
FROM composer:2.8 AS composer

WORKDIR /usr/local/src/

COPY composer.lock composer.json ./

RUN composer install --ignore-platform-reqs --optimize-autoloader \
    --no-plugins --no-scripts --prefer-dist

# Executor
FROM openruntimes/base:0.1.0 AS final

ARG OPR_EXECUTOR_VERSION
ENV OPR_EXECUTOR_VERSION=$OPR_EXECUTOR_VERSION

# Dependencies first (changes less frequently)
COPY --from=composer /usr/local/src/vendor /usr/local/vendor

# Source code last (changes more frequently)
COPY ./app /usr/local/app
COPY ./src /usr/local/src

HEALTHCHECK --interval=30s --timeout=15s --start-period=60s --retries=3 CMD curl -sf http://127.0.0.1:80/v1/health

CMD [ "php", "app/http.php" ]
