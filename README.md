# Executor

TODO:

- Address TODOs in code
- Write proper documentation (readme, contributing, code of conduct)
- Make example repo with proxy + 2 executors

Build:

```bash
curl -H "authorization: Bearer executor-secret-key" -H "Content-Type: application/json" -X POST http://localhost:9900/v1/runtimes -d '{"remove":true,"runtimeId":"myruntimebuild","image":"openruntimes/php:v2-8.0","source":"/storage/functions/php.tar.gz","destination":"/storage/builds/myruntime","entrypoint":"index.php"}' | jq
```

Execute + Cold Start:

```bash
curl -H "authorization: Bearer executor-secret-key" -H "Content-Type: application/json" -X POST http://localhost:9900/v1/execution -d '{"payload":"Developers are awesome!","variables":{"customVariable":"secretVariable"},"runtimeId":"myruntime","image":"openruntimes/php:v2-8.0","source":"/storage/functions/php.tar.gz","entrypoint":"index.php"}' | jq
```

Execute:

```bash
curl -H "authorization: Bearer executor-secret-key" -H "Content-Type: application/json" -X POST http://localhost:9900/v1/execution -d '{"payload":"Developers are awesome!","variables":{"customVariable":"secretVariable"},"runtimeId":"myruntime"}' | jq
```

