# Install PHP libraries
FROM composer:2.0 as composer

WORKDIR /usr/local/src/

COPY composer.lock /usr/local/src/
COPY composer.json /usr/local/src/

RUN composer install --ignore-platform-reqs --optimize-autoloader \
    --no-plugins --no-scripts --prefer-dist

# Prepare generic compiler
FROM php:8.3.7-cli-alpine3.19 as compile

ENV PHP_SWOOLE_VERSION=v5.1.2

RUN \
  apk add --no-cache --virtual .deps \
  make \
  automake \
  autoconf \
  gcc \
  g++ \
  git \
  openssl-dev \
  curl-dev
  
RUN docker-php-ext-install sockets

# Compile Swoole
FROM compile AS swoole
RUN \
  git clone --depth 1 --branch $PHP_SWOOLE_VERSION https://github.com/swoole/swoole-src.git && \
  cd swoole-src && \
  phpize && \
  ./configure --enable-sockets --enable-http2 --enable-openssl --enable-swoole-curl && \
  make && make install && \
  cd ..

# Executor
FROM php:8.3.7-cli-alpine3.19 as final

ARG OPR_EXECUTOR_VERSION
ENV OPR_EXECUTOR_VERSION=$OPR_EXECUTOR_VERSION

LABEL maintainer="team@appwrite.io"

ENV DOCKER_CONFIG=${DOCKER_CONFIG:-$HOME/.docker}
ENV DOCKER_API_VERSION=1.43

RUN \
  apk update \
  && apk add --no-cache --virtual .deps \
  make \
  automake \
  autoconf \
  curl-dev \
  gcc \
  g++ \
  linux-headers \
  && apk add --no-cache \
  libstdc++ \
  docker-cli \
  && docker-php-ext-install sockets \
  && apk del .deps \
  && rm -rf /var/cache/apk/*

WORKDIR /usr/local/

# Source code
COPY ./app /usr/local/app
COPY ./src /usr/local/src

# Extensions and libraries
COPY --from=composer /usr/local/src/vendor /usr/local/vendor
COPY --from=swoole /usr/local/lib/php/extensions/no-debug-non-zts-20230831/swoole.so /usr/local/lib/php/extensions/no-debug-non-zts-20230831/

RUN echo extension=swoole.so >> /usr/local/etc/php/conf.d/swoole.ini

EXPOSE 80

HEALTHCHECK --interval=30s --timeout=15s --start-period=60s --retries=3 CMD curl -s -H "Authorization: Bearer ${OPR_EXECUTOR_SECRET}" --fail http://127.0.0.1:80/v1/health

CMD [ "php", "app/http.php" ]