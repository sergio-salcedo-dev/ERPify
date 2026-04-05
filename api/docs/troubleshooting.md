# Troubleshooting

## Editing Permissions on Linux

If you work on Linux and cannot edit some of the project files right after
the first installation, you can run the following command
to set yourself as owner of the project files that were created by the Docker container:

From the **monorepo root** (where `compose.yaml` lives):

```console
docker compose -f compose.yaml -f compose.override.yaml run --rm php chown -R $(id -u):$(id -g) /app
```

## TLS/HTTPS Issues

See the [TLS section](tls.md) for more details.

## Production Issues

### How To Properly Build Fresh Images for Production Use

Remember that, by default, if you run `docker compose up --wait` from the **repo root**,
only the files `compose.yaml` and `compose.override.yaml` will be used.
See ["How Compose works"](https://docs.docker.com/compose/intro/compose-application-model)
and ["Merge Compose files"](https://docs.docker.com/compose/how-tos/multiple-compose-files/merge).

If you need to build images for production environment, you have to use the following
command:

```console
# from monorepo root
docker compose -f compose.yaml -f compose.prod.yaml build --pull --no-cache
```

### Building Dev and Prod Images

Dev and prod images use distinct image names (`app-php-dev` and `app-php-prod`),
so they won't conflict with each other.

To build and start the dev image (from **repo root**):

```console
docker compose -f compose.yaml -f compose.override.yaml up --wait
```

To build and start the prod image:

```console
docker compose -f compose.yaml -f compose.prod.yaml build --pull --no-cache
docker compose -f compose.yaml -f compose.prod.yaml up --wait
```

> [!WARNING]
>
> The order of `-f` arguments matters.
