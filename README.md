# Open Runtimes Executor 🤖

![open-runtimes-box-bg-cover](https://user-images.githubusercontent.com/1297371/151676246-0e18f694-dfd7-4bab-b64b-f590fec76ef1.png)

---

[![Discord](https://img.shields.io/discord/937092945713172480?label=discord&style=flat-square)](https://discord.gg/mkZcevnxuf)
[![Build Status](https://github.com/open-runtimes/executor/actions/workflows/tests.yml/badge.svg)](https://github.com/open-runtimes/executor/actions/workflows/tests.yml)
[![Twitter Account](https://img.shields.io/twitter/follow/appwrite?color=00acee&label=twitter&style=flat-square)](https://twitter.com/appwrite)
[![Docker Pulls](https://img.shields.io/docker/pulls/openruntimes/executor?color=f02e65&style=flat-square)](https://hub.docker.com/r/openruntimes/executor)

Executor for [Open Runtimes](https://github.com/open-runtimes/open-runtimes), a runtime environments for serverless cloud computing for multiple coding languages.

Executor is responsible for providing HTTP API for creating and executing Open Runtimes. Executor is stateless and can be scaled horizontally when a load balancer is introduced in front of it. You could use any load balancer but we highly recommend using [Open Runtimes Proxy](https://github.com/open-runtimes/proxy) for it's ease of setup with Open Runtimes Executor.

## Features

* **Flexibility** - Configuring custom image lets you use **any** runtime for your functions.
* **Performance** - Coroutine-style HTTP servers allows asynchronous operations without blocking. We. Run. Fast! ⚡
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
  openruntimes-executor:
    container_name: openruntimes-executor
    hostname: executor
    stop_signal: SIGINT
    image: openruntimes/executor
    networks:
      openruntimes-runtimes:
    ports:
      - 9900:80
    volumes:
      - /var/run/docker.sock:/var/run/docker.sock
      - openruntimes-builds:/storage/builds:rw
      - openruntimes-functions:/storage/functions:rw
      - /tmp:/tmp:rw
      - ./functions:/storage/functions:rw
    environment:
      - OPR_EXECUTOR_ENV
      - OPR_EXECUTOR_RUNTIMES
      - OPR_EXECUTOR_CONNECTION_STORAGE
      - OPR_EXECUTOR_INACTIVE_TRESHOLD
      - OPR_EXECUTOR_MAINTENANCE_INTERVAL
      - OPR_EXECUTOR_NETWORK
      - OPR_EXECUTOR_SECRET
      - OPR_EXECUTOR_LOGGING_PROVIDER
      - OPR_EXECUTOR_LOGGING_CONFIG
      - OPR_EXECUTOR_DOCKER_HUB_USERNAME
      - OPR_EXECUTOR_DOCKER_HUB_PASSWORD
      - OPR_EXECUTOR_RUNTIME_VERSIONS

networks:
  openruntimes-runtimes:
    name: openruntimes-runtimes

volumes:
  openruntimes-builds:
  openruntimes-functions:
```

> Notice we added bind to local `./functions` directory. That is only nessessary for this getting started, since we will be executing our custom function.

3. Create `.env` file:

```
OPR_EXECUTOR_ENV=development
OPR_EXECUTOR_RUNTIMES=php-8.0
OPR_EXECUTOR_CONNECTION_STORAGE=file://localhost
OPR_EXECUTOR_INACTIVE_TRESHOLD=60
OPR_EXECUTOR_MAINTENANCE_INTERVAL=60
OPR_EXECUTOR_NETWORK=openruntimes-runtimes
OPR_EXECUTOR_SECRET=executor-secret-key
OPR_EXECUTOR_LOGGING_PROVIDER=
OPR_EXECUTOR_LOGGING_CONFIG=
OPR_EXECUTOR_DOCKER_HUB_USERNAME=
OPR_EXECUTOR_DOCKER_HUB_PASSWORD=
OPR_EXECUTOR_RUNTIME_VERSIONS=v3
```

> `OPR_EXECUTOR_CONNECTION_STORAGE` takes a DSN string that represents a connection to your storage device. If you would like to use your local filesystem, you can use `file://localhost`. If using S3 or any other provider for storage, use a DSN of the following format `s3://access_key:access_secret@host:port/bucket_name?region=us-east-1`

> For backwards compatibility, executor also supports `OPR_EXECUTOR_STORAGE_*` variables as replacement for `OPR_EXECUTOR_CONNECTION_STORAGE`, as seen in [Appwrite repository](https://github.com/appwrite/appwrite/blob/1.3.8/.env#L26-L46). 

4. Start Docker container:

```bash
docker compose up -d
```

5. Prepare a function we will ask executor to run:

```bash
mkdir -p functions && cd functions && mkdir -p php-function && cd php-function
printf "<?\nreturn function(\$req, \$res) {\n    \$res->json([ 'n' => \mt_rand() / \mt_getrandmax() ]);\n};" > index.php
tar -czf ../my-function.tar.gz .
cd .. && rm -r php-function
```

> This created `my-function.tar.gz` that includes `index.php` with a simple Open Runtimes script.

5. Send a HTTP request to executor server:

```bash
curl -H "authorization: Bearer executor-secret-key" -H "Content-Type: application/json" -X POST http://localhost:9900/v1/runtimes/my-function/execution -d '{"image":"openruntimes/php:v2-8.0","source":"/storage/functions/my-function.tar.gz","entrypoint":"index.php"}'
```

6. Stop Docker containers:

```bash
docker compose down
```

## Endpoints

TODO: Document each endpoint + CURL examples

## Environment variables

TODO: Document each variable

## Contributing

All code contributions - including those of people having commit access - must go through a pull request and be approved by a core developer before being merged. This is to ensure a proper review of all the code.

We truly ❤️ pull requests! If you wish to help, you can learn more about how you can contribute to this project in the [contribution guide](CONTRIBUTING.md).

## Security

For security issues, kindly email us at [security@appwrite.io](mailto:security@appwrite.io) instead of posting a public issue on GitHub.

## Follow Us

Join our growing community around the world! See our official [Blog](https://medium.com/appwrite-io). Follow us on [Twitter](https://twitter.com/appwrite), [Facebook Page](https://www.facebook.com/appwrite.io), [Facebook Group](https://www.facebook.com/groups/appwrite.developers/) , [Dev Community](https://dev.to/appwrite) or join our live [Discord server](https://discord.gg/mkZcevnxuf) for more help, ideas, and discussions.

## License

This repository is available under the [MIT License](./LICENSE).
