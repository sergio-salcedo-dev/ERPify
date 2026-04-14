# Using Xdebug

The default development image is shipped with [Xdebug](https://xdebug.org/),
a popular debugger and profiler for PHP.

## Defaults in ERPify (Docker Compose)

The documented default IDE for step debugging is **PhpStorm**. Compose sets **`PHP_IDE_CONFIG=serverName=<name>`** on the `php` service, where **`<name>`** defaults to **`dev`** and can be overridden with **`PHP_IDE_SERVER_NAME`** in **`api/.env`** (same mechanism as **`XDEBUG_MODE`**). That name must match the server entry under **Settings | PHP | Servers** in PhpStorm (see below). Other editors (for example VS Code or Cursor) are unaffected; they use their own launch configuration instead.

Step debugging is **off by default**. The `php` service gets **`XDEBUG_MODE`** from Docker Compose interpolation: **`${XDEBUG_MODE:-off}`** in `compose.dev.yaml`. Values are read from your **shell environment** and from a **`.env` file in the Compose project directory** (the repo root, next to `compose.yaml`, if present). **`api/.env`** is what Symfony uses; `make xdebug-enable` / `make xdebug-disable` edit **`api/.env`** — export those variables in the shell before `docker compose up`, or mirror `XDEBUG_MODE` in a **root** `.env` if you rely on Compose file substitution without exporting.

From the **monorepo root** (parent of `api/`):

| Command | Effect |
|--------|--------|
| **`make xdebug-enable`** | Ensures a `XDEBUG_MODE=` line exists in `api/.env`, sets it to **`develop,debug`**, recreates the `php` container (IDE on port **9003**). |
| **`make xdebug-disable`** | Sets **`XDEBUG_MODE=off`** in `api/.env` when that line exists, then recreates `php`. If there is no line, Compose still defaults to **`off`**. |
| **`make xdebug-verify`** | Prints PHP / Xdebug versions and effective `XDEBUG_MODE` (stack must be running: `make up`). |

You can set **`XDEBUG_MODE`** in **`api/.env`** (start from [`api/.env.example`](../.env.example)) and export it before Compose, use **`make xdebug-enable`**, or run from the **repo root**, e.g. `XDEBUG_MODE=develop,debug docker compose -f compose.yaml -f compose.dev.yaml up`.

If you previously used **`api/.env.xdebug`**, remove that file; it is no longer used.

### Dev Containers

When using [Dev Containers](https://containers.dev/), the devcontainer Compose file at **`.devcontainer/compose.devcontainer.yaml`** (repo root) may set `XDEBUG_MODE` for the in-container IDE. That is separate from the `docker compose` workflow above. Workspace folder in the container is **`/workspace`**; Symfony files are under **`api/`** (and **`/app`**).

### Path mappings (monorepo)

The app is mounted at **`/app`** in the container and lives under **`./api`** on the host. In **PhpStorm**, map **`/app`** → your **`api`** directory (see the PhpStorm section below). If you use **VS Code or Cursor** instead, use the repo root [`.vscode/launch.json`](../../../.vscode/launch.json) (`${workspaceFolder}/api`).

## Debugging with Xdebug and PhpStorm

First, [create a PHP debug remote server configuration](https://www.jetbrains.com/help/phpstorm/creating-a-php-debug-server-configuration.html):

1. In the `Settings/Preferences` dialog, go to `PHP | Servers`
2. Create a new server:
   - Name: same string as **`PHP_IDE_SERVER_NAME`** (default **`dev`**), so it matches **`PHP_IDE_CONFIG`** inside the container
   - Host: `localhost` (or the one defined using the `SERVER_NAME` environment variable)
   - Port: `443`
   - Debugger: `Xdebug`
   - Check `Use path mappings`
   - Absolute path on the server: `/app`
   - Absolute path on the host: path to your **`api`** directory in the monorepo

You can now use the debugger after **`make xdebug-enable`** (or equivalent **`XDEBUG_MODE`**):

1. In PhpStorm, open the `Run` menu and click on `Start Listening for PHP Debug Connections`
2. Add the `XDEBUG_SESSION=PHPSTORM` query parameter to the URL of
   the page you want to debug, or use [other available triggers](https://xdebug.org/docs/step_debug#activate_debugger)

   Alternatively, you can use [the **Xdebug extension**](https://xdebug.org/docs/step_debug#browser-extensions)
   for your preferred web browser.

3. For **CLI** scripts, **`PHP_IDE_CONFIG`** (including your **`PHP_IDE_SERVER_NAME`**) is already set in the **`php`** container by Compose. From the host, pass it only if you run PHP outside that environment:

   ```console
   XDEBUG_SESSION=1 PHP_IDE_CONFIG="serverName=YOUR_SERVER_NAME" php bin/console ...
   ```

   Use the same **`YOUR_SERVER_NAME`** as in PhpStorm **PHP | Servers**. See [path mappings for CLI](https://www.jetbrains.com/help/phpstorm/zero-configuration-debugging-cli.html#configure-path-mappings) in the PhpStorm docs.

## Alternative: Visual Studio Code or Cursor

1. Install the [PHP Debug](https://marketplace.visualstudio.com/items?itemName=xdebug.php-debug) extension (`xdebug.php-debug`).
2. Use the repo root `.vscode/launch.json` configuration **Listen for Xdebug (API)** (port **9003**, path mapping `/app` → `api`).
3. Run **Listen for Xdebug (API)** and trigger a request (browser extension or `XDEBUG_SESSION`), with step debugging enabled (`make xdebug-enable` or `XDEBUG_MODE` including `debug`).

## Troubleshooting

From the **ERPify** repository root, run `make xdebug-verify` (alias: `make xdebug-check`) to print `php -v`, PHP and Xdebug versions, `XDEBUG_MODE`, and `PHP_IDE_CONFIG`. The stack must be running (`make up`).

The extension can still appear in `php -v` while step debugging is disabled; ensure **`XDEBUG_MODE`** includes **`debug`** when the IDE does not connect.

You can also inspect from the **repo root**:

```console
$ docker compose -f compose.yaml -f compose.dev.yaml exec php php --version

PHP ...
    with Xdebug v3.x.x ...
```
