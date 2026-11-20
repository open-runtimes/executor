# Install PHP libraries
FROM composer:2.10@sha256:2cf275428757e5435a667bfc0d038b14520ca02be2b1b70d44a05c8c19e2de48 AS composer

WORKDIR /usr/local/src/

COPY composer.lock composer.json ./

RUN composer install --ignore-platform-reqs --optimize-autoloader \
    --no-plugins --no-scripts --prefer-dist

# Executor
FROM appwrite/utopia-base:php-8.5-2.1.9@sha256:7ecff3433b58355c926c92ad9c06ff01613d8f14a792bef31ded4eb553c5fc97 AS final

RUN apk upgrade --no-cache
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
