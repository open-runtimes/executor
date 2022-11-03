# Executor

TODO:

- Address TODOs in code
- Write proper documentation (readme, contributing, code of conduct)

Execute + Cold Start:

```bash
curl -H "x-appwrite-executor-key: your-secret-key" -H "Content-Type: application/json" -X POST http://localhost:9900/v1/execution -d '{"payload":"Developers are awesome!","variables":{"customVariable":"secretVariable"},"runtimeId":"myruntime","image":"openruntimes/php:v2-8.0","source":"/storage/functions/php.tar.gz","entrypoint":"index.php"}' | jq
```

Execute:

```bash
curl -H "x-appwrite-executor-key: your-secret-key" -H "Content-Type: application/json" -X POST http://localhost:9900/v1/execution -d '{"payload":"Developers are awesome!","variables":{"customVariable":"secretVariable"},"runtimeId":"myruntime"}' | jq
```

