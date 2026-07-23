---
title: 'Reportar upstream la regresión de doctrine/doctrine-bundle 3.2.5 en doctrine:database:create sobre PostgreSQL'
type: 'chore'
created: '2026-07-22'
status: 'done'
baseline_commit: '848e5c95362a2a1ed09465b3440fecdbd647806f'
review_loop_iteration: 0
context:
  - '{project-root}/CLAUDE.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** doctrine/doctrine-bundle 3.2.5 (publicado 2026-07-22) rompe `doctrine:database:create` en PostgreSQL para cualquier app cuyo driver DBAL esté envuelto por un middleware — que es el caso por defecto en Symfony. ERPify lo neutralizó con un `conflict` en `api/composer.json` que hoy no tiene dueño, ni caducidad, ni rastro público: nadie sabrá cuándo puede retirarse.

**Approach:** Construir un repro mínimo y desechable (skeleton Symfony + Postgres efímero) que aísle la causa con un A/B de 5 celdas, redactar con él un issue en `doctrine/DoctrineBundle` que reconozca el trade-off que PR #2252 resolvía, y abrir un issue de seguimiento en ERPify que le dé dueño y disparador de retirada al pin.

## Boundaries & Constraints

**Always:**
- Todo artefacto del repro vive bajo `/home/dev/Projects/ERPify/tmp/` (gitignorado). Cero ficheros nuevos en el árbol versionado.
- El repro corre contra un **contenedor Postgres efímero propio**, nunca contra el Postgres del stack de ERPify.
- El texto del issue upstream va en **inglés** y no contiene internals de ERPify (namespaces `Erpify\`, rutas del repo, nombres de contexto acotado, ni el volcado real de `erpify_user`).
- Toda afirmación técnica que entre en el issue se demuestra antes en el repro. Nada por deducción.
- Cada celda de la matriz registra el **entorno exacto** (`php -v`, versiones resueltas de doctrine-bundle / dbal / doctrine-bridge / framework-bundle, driver, `server_version`, `idle_connection_ttl`), el **comando literal** ejecutado y el **SQLSTATE** además del mensaje. Un repro que no se pueda reejecutar byte a byte no es evidencia.
- El issue describe, no diseña: la dirección de fix se formula como observación (`During investigation we observed…`, `One possible direction appears to be…`), nunca como prescripción (`The fix is…`).

**Ask First:**
- Publicar el issue en `doctrine/DoctrineBundle` — externo e irreversible. Sergio aprueba el texto final.
- Abrir el issue de seguimiento en `sergio-salcedo-dev/ERPify`.
- Cualquier edición de `api/composer.json`, `api/composer.lock` o `api/tools/behat/composer.json`.

**Never:**
- Tocar el bloque `conflict` ni subir doctrine-bundle en la app. El pin se queda hasta que exista una versión posterior a 3.2.5 verificada contra el repro y `make php.behat`.
- Abrir un PR de fix a `doctrine/DoctrineBundle` (descartado explícitamente en el alcance).
- Crear rama, commit o worktree en ERPify: este trabajo no produce cambio de código productivo.
- Tocar nada del hilo Symfony 8.1 (issue #487).

## I/O & Edge-Case Matrix

Las cinco celdas del A/B. La fila 3 aísla la causa, la fila 4 la prueba en positivo, y la fila 5 demuestra que entendemos qué arreglaba PR #2252.

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| Línea base sana | 3.2.4 + middleware activo (`idle_connection_ttl` por defecto) + `server_version` fijado | `doctrine:database:create` crea la BD, exit 0 | N/A |
| Regresión | 3.2.5, misma config | Falla: `database "<rol>" does not exist`, exit ≠ 0 | Registrar stdout/stderr, exit code **y SQLSTATE** literales — sin predecir el código: se transcribe el que salga |
| Aislamiento de causa | 3.2.5 + `idle_connection_ttl: 0` (sin middleware) | Vuelve a funcionar, exit 0 → la causa es el envoltorio, no la versión | N/A |
| Prueba positiva del envoltorio | 3.2.5, config por defecto, antes de invocar el comando | El driver más externo resuelto **no** es el driver pgsql desnudo — se vuelca su clase y, por reflexión sobre `wrappedDriver`, la cadena hasta el driver real | Si la reflexión falla, basta el nombre de la clase externa; se anota la limitación en vez de inventar la cadena |
| Trade-off de PR #2252 | 3.2.4 **sin** `server_version`, sin middleware | Falla al resolver la plataforma — el fallo original que #2252 arreglaba | N/A |

</frozen-after-approval>

## Code Map

- `api/composer.json` — lleva `conflict: {"doctrine/doctrine-bundle": "3.2.5"}` con `require: "^3.2.4"`. Solo lectura en este spec.
- `api/vendor/doctrine/doctrine-bundle/src/Command/CreateDatabaseDoctrineCommand.php:71` — versión **3.2.4** instalada: `$connection->getDatabasePlatform() instanceof PostgreSQLPlatform`. Es el código *bueno*; el malo hay que traerlo del tag 3.2.5 upstream.
- `api/vendor/doctrine/dbal/src/Driver/Middleware/AbstractDriverMiddleware.php:14` — `implements Driver`, **no** extiende `AbstractPostgreSQLDriver`. Raíz del fallo: cualquier middleware rompe el `instanceof`, no solo el de Symfony.
- `api/vendor/symfony/doctrine-bridge/Middleware/IdleConnection/Driver.php:19` — `extends AbstractDriverMiddleware`; el envoltorio concreto que activa el bug en Symfony.
- `api/vendor/doctrine/doctrine-bundle/src/DependencyInjection/DoctrineExtension.php:547,573` — el middleware se registra si `idle_connection_ttl > 0` (por defecto **600**) y existe la clase `Listener` del bridge → activo por defecto en toda app Symfony.
- `api/tests/Behat/Context/FixturesContext.php:106` — el `@BeforeScenario` que ejecuta `doctrine:database:create`; explica por qué la regresión mata los 368 escenarios y no solo un comando manual.

## Tasks & Acceptance

**Execution:**
- [x] `tmp/repro-dbal-middleware/` — crear skeleton Symfony mínimo (`symfony/framework-bundle` + `doctrine/doctrine-bundle` + `symfony/doctrine-bridge` + `doctrine/dbal`, driver pgsql) con un `doctrine.yaml` de una sola conexión — sustrato del A/B, sin nada de ERPify.
- [x] `tmp/repro-dbal-middleware/compose.yaml` — Postgres efímero con puerto host libre, credenciales throwaway y **rol distinto del nombre de BD** (el bug solo se manifiesta cuando libpq cae al nombre de rol) — aísla el repro del stack de ERPify.
- [x] `tmp/repro-dbal-middleware/dump-driver-chain.php` — script que resuelve la conexión del contenedor y vuelca la clase del driver externo, recorriendo `wrappedDriver` por reflexión hasta el driver real — prueba **positiva** de que el envoltorio estaba activo, no solo que quitarlo lo arregla.
- [x] `tmp/repro-dbal-middleware/run-matrix.sh` — script que ejecuta las celdas de la matriz volcando, por celda: comando literal, entorno resuelto (`php -v`, `composer show` de doctrine-bundle / dbal / doctrine-bridge / framework-bundle, driver, `server_version`, `idle_connection_ttl`), exit code, stdout/stderr y SQLSTATE — evidencia reejecutable, no prosa.
- [x] `tmp/repro-dbal-middleware/RESULTS.md` — tabla con las salidas literales más el bloque de entorno congelado — es lo que se pega en el issue, y lo que impide un «no lo reproduzco, tendrías otra versión de DBAL».
- [x] `tmp/repro-dbal-middleware/NOTES.md` — los dos fragmentos enfrentados de `CreateDatabaseDoctrineCommand::execute()` (3.2.4 → 3.2.5) — no va al issue; acelera cualquier revisión futura.
- [x] Validar en el repro la dirección de fix antes de sugerirla: si `getDriver()->getDatabasePlatform($versionProvider)` (que `AbstractDriverMiddleware` **sí** delega a la cadena) resuelve la plataforma sin conexión al servidor. Si no se sostiene, el issue no propone dirección alguna — solo describe el fallo.
- [x] `tmp/bmad-md/issue-doctrinebundle-middleware-regression.md` — redactar el issue en inglés: versiones afectadas, repro, matriz, mecanismo (`AbstractDriverMiddleware implements Driver`), referencia a PR #2252 con su trade-off legítimo, y la dirección de fix solo si quedó validada.
- [x] **HALT** — presentar el texto a Sergio para su OK explícito antes de publicar nada.
- [x] Publicar el issue upstream (solo tras el OK) y registrar su número/URL — <https://github.com/doctrine/DoctrineBundle/issues/2261>.
- [x] Retirada del workaround, ejecutada directamente en vez de diferida a un issue de seguimiento. Ver Change Log.

**Acceptance Criteria:**
- Given el repro mínimo y un Postgres efímero, when se ejecuta la matriz, then las filas 1 y 3 salen con exit 0 y la fila 2 falla con `database "<rol>" does not exist`, demostrando que el envoltorio del driver —no la versión por sí sola— es la causa.
- Given la celda de prueba positiva, when se vuelca el driver resuelto bajo la configuración por defecto, then la clase más externa **no** es el driver pgsql desnudo, cerrando la objeción de que el middleware pudiera no estar cargado.
- Given `RESULTS.md`, when se lee cualquier celda, then incluye comando literal, versiones resueltas de PHP y de los cuatro paquetes, driver, `server_version`, `idle_connection_ttl`, exit code y —en las celdas que fallan— el SQLSTATE.
- Given el repro completo, when se inspecciona el árbol versionado de ERPify, then `git status` sale limpio: ningún fichero nuevo ni modificado fuera de `tmp/` y del propio spec.
- Given el texto del issue redactado, when se busca en él cualquier identificador de ERPify (`Erpify`, `erpify_user`, rutas del repo), then no aparece ninguno.
- Given que el issue upstream está publicado, when se consulta el issue de seguimiento de ERPify, then enlaza al upstream y enuncia la condición concreta que permitirá retirar el `conflict`.
- Given cualquier punto del trabajo, when se revisa `api/composer.json`, then es byte-idéntico a `main`.

## Design Notes

El handoff atribuía el fallo al middleware *idle-connection de Symfony*. La evidencia en `vendor/` lo corrige y lo generaliza: `AbstractDriverMiddleware` **implementa** `Driver` en vez de extender el driver envuelto, así que **cualquier** middleware DBAL (logging, profiling, uno propio con `#[AsMiddleware]`) derrota igual el `instanceof AbstractPostgreSQLDriver`. Enunciarlo así en el issue es más útil para los mantenedores y más difícil de rebatir que señalar un solo envoltorio.

Segundo matiz que refuerza el reporte: ERPify **sí** fija `server_version: '18'`, o sea que la condición que PR #2252 quería evitar (`getDatabasePlatform()` necesitando conectar) nunca nos aplicaba. El cambio no arregló nuestro caso; solo lo rompió. Eso convierte el reporte en «se cambió una condición estrecha por una más ancha», que es exactamente el argumento que un mantenedor puede accionar.

## Verification

**Commands:**
- `bash tmp/repro-dbal-middleware/run-matrix.sh` — expected: las 5 celdas ejecutadas, exit codes coincidiendo con la matriz de arriba.
- `cd /home/dev/Projects/ERPify && git status --porcelain` — expected: sin entradas fuera del propio spec (`tmp/` está gitignorado).
- `git diff origin/main -- api/composer.json api/composer.lock` — expected: salida vacía.
- `grep -riE 'erpify' tmp/bmad-md/issue-doctrinebundle-middleware-regression.md` — expected: sin coincidencias.

**Manual checks (if no CLI):**
- El texto del issue reconoce explícitamente qué resolvía PR #2252 antes de describir la regresión, y formula la dirección de fix como observación, no como prescripción (`The fix is…` no aparece).

## Spec Change Log

### 2026-07-23 — upstream arregla, y la retirada se ejecuta en vez de diferirse

**Upstream resuelto.** El issue se publicó como
[doctrine/DoctrineBundle#2261](https://github.com/doctrine/DoctrineBundle/issues/2261) y `derrabus` lo
cerró el 2026-07-23 10:45 UTC: *"I've reverted the change and released as 3.2.6/3.3.1."* Packagist
confirma `3.2.6` (10:36 UTC) y `3.3.1` (10:37 UTC).

**El intent frozen quedó superado por decisión humana explícita.** El bloque `Never` prohibía tocar el
`conflict` y subir doctrine-bundle, y `Boundaries` prohibía crear rama, commit o worktree. Sergio
renegoció ambas cosas al ordenar ejecutar la retirada; el bloque frozen se deja intacto como registro
de la intención original.

**El Code Map había envejecido.** Describía `api/composer.json` con
`conflict: {"doctrine/doctrine-bundle": "3.2.5"}` y `require: "^3.2.4"`. Para cuando se ejecutó la
retirada, el PR #540 ya había borrado esa línea de `conflict` y subido la restricción a `^3.3.0`. O sea
que lo que protegía al repo **no era una restricción de versión** —`3.3.0` está rota igual, como
documenta el propio issue— sino el workaround `FixturesContext::ensureDatabaseExists()` que #540
introdujo. La retirada es por tanto de ese workaround, no de un `conflict` que ya no existía.

**Verificación (arnés `tmp/repro-dbal-middleware/verify-331.sh` → `RESULTS-331.md`, mismas celdas y
entorno que el informe original):**

| Celda | Configuración | Exit |
|-------|---------------|------|
| V1 | 3.3.1 + middlewares activos + `server_version` fijado | 0 |
| V2 | 3.2.6, misma configuración | 0 |
| V3 | Volcado de la cadena: `IdleConnection → Debug → PDO\PgSQL`; *outermost instanceof `AbstractPostgreSQLDriver` = NO* | 0 |
| V4 | 3.3.1 **sin** `server_version` y sin middleware | 1 |

V3 es lo que cierra la objeción del falso verde: bajo 3.3.1 el driver sigue envuelto y el `instanceof`
sigue fallando, y aun así V1 pasa — el arreglo es real, no un artefacto de que el envoltorio
desapareciera. V4 registra el precio del revert: vuelve el fallo que arreglaba PR #2252, irrelevante
aquí porque `api/config/packages/doctrine.yaml` fija `server_version: '18'`.

**Cambio aplicado:** `doctrine/doctrine-bundle` de `^3.3.0` a `^3.3.1` (se sube la restricción, no solo
el lock, para que ninguna resolución futura pueda volver a caer en la 3.3.0 rota), y
`FixturesContext::ensureDatabaseExists()` retirado en favor de `doctrine:database:create
--if-not-exists`. El resultado deja el fichero idéntico al blob previo a #540.

**Gates, en frío y con exit code impreso:** `php.behat` 0 (370 escenarios / 3358 steps), `php.stan` 0,
`php.unit` 0 (2064 tests), `php.deptrac` 0, `php.quality` 0. La corrida de Behat que cuenta es la
segunda: se borraron `erpify_db_test` y su backup para forzar la ruta de creación —la que estaba
rota— y volvió a salir 0 con la BD recreada.

**Criterios de aceptación caducados a propósito:** los dos que exigían `api/composer.json`
byte-idéntico a `main` y `git diff` vacío pertenecían al alcance «solo reportar». La retirada los
invalida por diseño.
