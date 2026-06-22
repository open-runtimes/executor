# Install PHP libraries
FROM composer:2.8 AS composer

WORKDIR /usr/local/src/

COPY composer.lock composer.json ./

RUN composer install --ignore-platform-reqs --optimize-autoloader \
    --no-plugins --no-scripts --prefer-dist

# Executor
# Swoole + PHP 8.4 base (utopia-php/client requires PHP >= 8.4).
FROM phpswoole/swoole:6.2.1-php8.4-alpine AS final

# The executor shells out to the Docker CLI to manage runtime containers.
RUN apk add --no-cache docker-cli

WORKDIR /usr/local/

ARG OPR_EXECUTOR_VERSION
ENV OPR_EXECUTOR_VERSION=$OPR_EXECUTOR_VERSION

# Dependencies first (changes less frequently)
COPY --from=composer /usr/local/src/vendor /usr/local/vendor

# Source code last (changes more frequently)
COPY ./app /usr/local/app
COPY ./src /usr/local/src

HEALTHCHECK --interval=30s --timeout=15s --start-period=60s --retries=3 CMD curl -sf http://127.0.0.1:80/v1/health

CMD [ "php", "app/http.php" ]
