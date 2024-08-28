
echo 'PHP Packaging...'

cp -r $(pwd)/tests/resources/functions/php-fn $(pwd)/tests/resources/functions/packages/php-fn

docker run --rm -v $(pwd)/tests/resources/functions/packages/php-fn:/app -w /app composer:2.0 composer install --ignore-platform-reqs

docker run --rm -v $(pwd)/tests/resources/functions/packages/php-fn:/app -w /app appwrite/env-php-8.0:1.0.0 tar -zcvf code.tar.gz .

mv $(pwd)/tests/resources/functions/packages/php-fn/code.tar.gz $(pwd)/tests/resources/functions/php-fn.tar.gz

rm -r $(pwd)/tests/resources/functions/packages/php-fn
