# Open Runtimes Executor 🤖

![open-runtimes-box-bg-cover](https://user-images.githubusercontent.com/1297371/151676246-0e18f694-dfd7-4bab-b64b-f590fec76ef1.png)

---

[![Discord](https://img.shields.io/discord/937092945713172480?label=discord&style=flat-square)](https://discord.gg/mkZcevnxuf)
[![Build Status](https://github.com/open-runtimes/executor/actions/workflows/tests.yml/badge.svg)](https://github.com/open-runtimes/executor/actions/workflows/tests.yml)
[![Twitter Account](https://img.shields.io/twitter/follow/appwrite?color=00acee&label=twitter&style=flat-square)](https://twitter.com/appwrite)
[![Docker Pulls](https://img.shields.io/docker/pulls/openruntimes/executor?color=f02e65&style=flat-square)](https://hub.docker.com/r/openruntimes/executor)

Executor for [Open Runtimes](https://github.com/open-runtimes/open-runtimes), a runtime environments for serverless cloud computing for multiple coding languages.

Executor is responsible for providing HTTP API for building, starting and executing Open Runtimes. Proxy is stateless and can be scaled horizontally when a load balanced is introduced in front of it. You could use any load balancer but we highly recommend using [Open Runtimes Proxy](https://github.com/open-runtimes/proxy) for it's ease of setup with Open Runtimes Executor.

## Features

* **Open Source** - Released under the MIT license, free to use and extend.

## Getting Started

1. Pull Open Runtimes Executor image:

```bash
docker pull openruntimes/executor
```

2. Create `docker-compose.yml` file:

```yml
version: '3'
services:
  openruntimes-proxy:
    image: openruntimes/proxy
    ports:
      - 9800:80
    environment:
      - OPEN_RUNTIMES_PROXY_ALGORITHM
      - OPEN_RUNTIMES_PROXY_EXECUTORS
      - OPEN_RUNTIMES_PROXY_HEALTHCHECK_INTERVAL
      - OPEN_RUNTIMES_PROXY_ENV
      - OPEN_RUNTIMES_PROXY_EXECUTOR_SECRET
      - OPEN_RUNTIMES_PROXY_SECRET
      - OPEN_RUNTIMES_PROXY_LOGGING_PROVIDER
      - OPEN_RUNTIMES_PROXY_LOGGING_CONFIG
      - OPEN_RUNTIMES_PROXY_HEALTHCHECK
  whoami1:
    hostname: whoami1
    image: containous/whoami
  whoami2:
    hostname: whoami2
    image: containous/whoami
```

> We are adding 1 proxy and 2 HTTP servers. Notice only proxy is exported, on a port `9800`.

3. Create `.env` file:

```
OPEN_RUNTIMES_PROXY_ALGORITHM=round-robin
OPEN_RUNTIMES_PROXY_EXECUTORS=whoami1,whoami2
OPEN_RUNTIMES_PROXY_HEALTHCHECK=disabled
OPEN_RUNTIMES_PROXY_SECRET=proxy-secret-key
OPEN_RUNTIMES_PROXY_HEALTHCHECK_INTERVAL=5000
OPEN_RUNTIMES_PROXY_ENV=development
OPEN_RUNTIMES_PROXY_EXECUTOR_SECRET=executor-secret-key
OPEN_RUNTIMES_PROXY_LOGGING_PROVIDER=
OPEN_RUNTIMES_PROXY_LOGGING_CONFIG=
```

> Notice we disabled health check. We recommend keeping it `enabled` and implementing proper health check endpoint

4. Start Docker container:

```bash
docker compose up -d
```

5. Send a HTTP request to proxy server:

```bash
curl -H "authorization: Bearer proxy-secret-key" -X GET http://localhost:9800/
```

Run the command multiple times to see request being proxied between both whoami servers. You can see `Hostname` changing the value.

> Noitce we provided authorization header as configured in `.env` in `OPEN_RUNTIMES_PROXY_SECRET`.

6. Stop Docker containers:

```bash
docker compose down
```

## Environment variables

| Variable name                            | Description                                                                                                                               |
|------------------------------------------|-------------------------------------------------------------------------------------------------------------------------------------------|
| OPEN_RUNTIMES_PROXY_ALGORITHM            | Proxying algorithm. Supports `round-robin`, `random`.                                                                                     |
| OPEN_RUNTIMES_PROXY_EXECUTORS            | Comma-separated hostnames of servers under the proxy.                                                                                     |
| OPEN_RUNTIMES_PROXY_HEALTHCHECK          | Health check by HTTP request to /v1/health. 'enabled' by default. To disable, set to 'disabled'.                                          |
| OPEN_RUNTIMES_PROXY_HEALTHCHECK_INTERVAL | Delay in milliseconds between health checks. 10000 by default. Only relevant if OPEN_RUNTIMES_PROXY_HEALTHCHECK is 'enabled'.             |
| OPEN_RUNTIMES_PROXY_ENV                  | Runtime environment. 'production' or 'development'. Development may expose debug information and is not recommended on production server. |
| OPEN_RUNTIMES_PROXY_SECRET               | Secret that needs to be provided in `Authroization` header when communicating with the to proxy.                                          |
| OPEN_RUNTIMES_PROXY_EXECUTOR_SECRET      | String provided as `authorization` header by proxy when sending request to executor.                                                      |
| OPEN_RUNTIMES_PROXY_LOGGING_PROVIDER     | Logging provider. Supports `sentry`, `appsignal`, `raygun`, `logowl`. Leave empty for no cloud logging.                                   |
| OPEN_RUNTIMES_PROXY_LOGGING_CONFIG       | Logging configuration as requested by `utopia-php/logger`.                                                                                |

## Contributing

All code contributions - including those of people having commit access - must go through a pull request and be approved by a core developer before being merged. This is to ensure a proper review of all the code.

We truly ❤️ pull requests! If you wish to help, you can learn more about how you can contribute to this project in the [contribution guide](CONTRIBUTING.md).

## Security

For security issues, kindly email us at [security@appwrite.io](mailto:security@appwrite.io) instead of posting a public issue on GitHub.

## Follow Us

Join our growing community around the world! See our official [Blog](https://medium.com/appwrite-io). Follow us on [Twitter](https://twitter.com/appwrite), [Facebook Page](https://www.facebook.com/appwrite.io), [Facebook Group](https://www.facebook.com/groups/appwrite.developers/) , [Dev Community](https://dev.to/appwrite) or join our live [Discord server](https://discord.gg/mkZcevnxuf) for more help, ideas, and discussions.

## License

This repository is available under the [MIT License](./LICENSE).



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

