FROM composer:2.0 as composer

WORKDIR /usr/local/src/

COPY composer.lock /usr/local/src/
COPY composer.json /usr/local/src/

RUN composer install --ignore-platform-reqs --optimize-autoloader \
    --no-plugins --no-scripts --prefer-dist
    
FROM phpswoole/swoole:php8.0-alpine

WORKDIR /usr/local/

# Add Source Code
COPY ./app /usr/local/app
COPY ./src /usr/local/src
COPY ./tests /usr/local/tests

COPY --from=composer /usr/local/src/vendor /usr/local/vendor

EXPOSE 80

CMD [ "php", "app/http.php", "-dopcache.preload=opcache.preload=/usr/src/code/app/preload.php" ]