---
title: 'make php.unit: elevar memory_limit del proceso PHPUnit (OOM a 128M)'
type: 'bugfix'
created: '2026-07-22'
status: 'done'
review_loop_iteration: 0
baseline_commit: '848e5c95'
context:
  - '{project-root}/CLAUDE.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** `make php.unit` muere con `Allowed memory size of 134217728 bytes exhausted` en un test distinto en cada pasada. El pico real medido de la suite completa es **127–129 MB** contra el `memory_limit` de **128 MB** que trae el `php.ini` de la imagen: el pico cae *sobre* la línea, así que la víctima es quien haga la siguiente asignación. No es una fuga —la curva de memoria se estabiliza— y rompe todos los agregados locales (`php.test`, `app.test`, `ci.test`, `ci.api`, `app.pre-commit`, `make ci`).

**Approach:** Elevar el `memory_limit` **solo en la invocación de PHPUnit** del target `php.unit`, con el mismo patrón `php -d memory_limit=…` que `php.unit.coverage` ya usa y documenta, y corregir el comentario del módulo que hoy afirma lo contrario.

## Boundaries & Constraints

**Always:**

- El cambio vive en `make/php-test.mk`, sobre la línea de `php.unit`, vía `php -d memory_limit=…` (flag por invocación).
- El comentario de bloque de `php.unit.coverage` afirma hoy `«Raised only here (report-only); the php.unit gate keeps the default limit»`: queda falso y debe reescribirse en el mismo commit.
- La justificación del valor va en un comentario `why:` sobre el target, con la cifra medida (pico 127–129 MB) para que un lector futuro sepa cuándo revisarlo.

**Ask First:**

- Subir el límite en el `php.ini` de la imagen o en Compose en vez de en el target: afectaría a peticiones HTTP reales y a prod, donde 128 MB por petición sí es una barrera legítima.
- Tocar `php.unit.coverage` (su 1G tiene otra causa documentada) o `php.bench`.
- Reducir la huella de la suite: descartado con medición (ver Design Notes); reabrirlo es otro alcance.

**Never:**

- No tocar `api/tools/phpunit/phpunit.dist.xml`, ni tests, ni `api/config/**` — el diagnóstico descarta que la causa esté ahí.
- No relajar `failOnWarning` / `failOnDeprecation` ni añadir `--do-not-fail-on-phpunit-warning` a `php.unit`.
- No dividir la suite en varios procesos ni introducir paratest.
- No usar `memory_limit=-1` (ilimitado): una fuga real dejaría de fallar y se comería el host.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| Suite completa | `make php.unit` sobre `main` limpio | 2064 tests, exit 0, sin `Allowed memory size` | N/A |
| Args passthrough | `make php.unit c='--filter BankTest'` | El `c=` sigue llegando a PHPUnit tras el flag `-d` | N/A |
| Fuga real futura | Un test que asigna sin límite | Sigue muriendo por OOM (techo finito), no cuelga la máquina | Fatal error de PHP, exit ≠ 0 |

</frozen-after-approval>

## Code Map

- `make/php-test.mk:17-18` — target `php.unit`: `@$(PHP_TEST) bin/phpunit $(c)`. **Único punto a cambiar.**
- `make/php-test.mk:21-37` — bloque de comentario + `php.unit.coverage`: precedente exacto del patrón (`php -d memory_limit=1G bin/phpunit`) y sede de la afirmación que queda obsoleta.
- `make/config.mk:99-110` — `PHP_TEST` resuelve a `cd api && APP_ENV=test` con **`IN_CONTAINER=false`** (rama host) y a `docker compose exec -e APP_ENV=test php` en el caso por defecto (`IN_CONTAINER=true`); el `php -d` debe ir **después** de esa macro, como en el target de coverage.
- `.github/workflows/ci.yml:126` — CI ejecuta `make php.unit.coverage` (ya a 1G), nunca `php.unit`: por eso CI está verde y el fallo es solo local.

## Tasks & Acceptance

**Execution:**

- [x] `make/php-test.mk` — en `php.unit`, anteponer `php -d memory_limit=512M` a `bin/phpunit`, preservando `$(c)` al final — alinea el techo del proceso con el pico medido de la suite.
- [x] `make/php-test.mk` — añadir sobre el target un comentario `why:` con la cifra medida y reescribir la última frase del bloque de `php.unit.coverage` (ya no es cierto que `php.unit` conserve el límite por defecto) — el comentario es la única documentación de la decisión.
- [x] `make/config.mk` — declarar `PHPUNIT_MEMORY_LIMIT ?= 512M` junto a los exec helpers de PHP y consumirlo desde `php.unit` — válvula de escape sin editar fichero trackeado; `c=` no sirve porque cae después del nombre del script.

**Acceptance Criteria:**

- Given `main` limpio y el stack arriba, when se ejecuta `make php.unit` tres veces seguidas, then las tres terminan con exit 0 y ninguna imprime `Allowed memory size`.
- Given el target modificado, when se ejecuta `make php.unit c='--filter BankStoredObjectMultipartFunctionalTest'`, then PHPUnit recibe el filtro y ejecuta solo esa clase.
- Given `IN_CONTAINER=false` (rama host), when se expande la receta, then queda `cd …/api && APP_ENV=test php -d memory_limit=512M bin/phpunit` — el `-d` no rompe esa rama.
- Given el knob, when se ejecuta `make php.unit PHPUNIT_MEMORY_LIMIT=32M`, then la suite muere con `Allowed memory size of 33554432 bytes exhausted` — prueba de que la variable gobierna de verdad y no es decorativa.
- Given `git diff` sobre ficheros trackeados, when se revisa el commit, then solo aparecen `make/php-test.mk` y `make/config.mk` — ni tests, ni config, ni `php.ini`, ni `php.unit.coverage` (el propio spec se añade como artefacto bmad, no cuenta como código).

## Spec Change Log

- **Review adversarial (2026-07-22).** *Hallazgo:* el `Never` congelado que prohíbe tocar `api/tools/phpunit/phpunit.dist.xml` se justificaba con «el diagnóstico descarta que la causa esté ahí» — un non sequitur que confunde *dónde está la causa* con *dónde va el arreglo*. Ubicar el techo en el `<php><ini>` del propio config cubriría los 4 entrypoints (make, IDE, `bin/phpunit` a mano, `vendor/bin/phpunit --group benchmark`) con una línea. *Consultado:* Murat (Test Architect) → mantener el `-d` en el target; Winston (Arquitecto) → mover al XML. *Decidido por Sergio:* mantener el target. *Estado malo evitado:* el `<ini>` de PHPUnit **pisa** cualquier `-d` (verificado: `<ini value="32M"/>` + `-d 512M` → OOM a 32M), así que moverlo al XML habría impuesto un techo ÚNICO de 1G a todas las corridas, borrando el 1G específico de coverage y aflojando el presupuesto de detección de runaway del gate. *KEEP:* la evidencia de Design Notes (curva + hipótesis refutadas) debe sobrevivir a cualquier re-derivación — sin ella se vuelve a perseguir una fuga que no existe.
- **Review adversarial (2026-07-22) — palanca de override.** *Hallazgo:* dos literales de memoria codificados y sin vía de override desde CLI (`c='-d …'` cae después del nombre del script, así que lo consume PHPUnit). *Amendado:* añadido `PHPUNIT_MEMORY_LIMIT ?= 512M` en `make/config.mk` (precedente: `PHP_SERVICE ?= php`), consumido solo por `php.unit`. *Estado malo evitado:* un knob decorativo — se verifica que gobierna forzándolo a 32M y comprobando el OOM exacto. `php.unit.coverage` NO se parametriza: un único llamador, CI-only.

## Design Notes

**Por qué no hay fuga que arreglar** — curva medida con `--log-events-text --with-telemetry` sobre los 2064 tests:

```
test #1     69.6 MB   ← antes de ejecutar nada: PHPUnit construye la suite (396 ficheros)
test #301  115.3 MB   ← pico, en plena fase Functional
test #601   99.0 MB   ← el GC recupera al pasar a la fase Unit
test #1951 102.0 MB   ← plana: +3 MB en 1350 tests
```

No es monotónica: sube, cae y se aplana. Los 70 MB previos al primer test y los ~45 MB de la fase funcional son clases y metadatos cargados que PHP nunca libera — coste inherente, no retención accidental.

**Hipótesis descartadas midiendo, no opinando:**

- *Acumulador estático de `TestDebugDataHolder`* (su `reset()` es no-op; solo Behat llama `resetScenario()`): con `profiling: false` la fase Functional **sube** de 98.5 a 107 MB. Refutada.
- *BD de dev creciendo*: 22 filas en `bank`, 58 en `audit_log`. Irrelevante.
- *Un test glotón*: el mayor salto individual es +6.5 MB. El buffer de 7 MB de `finfo` que aparece en el fatal es la gota, no la causa.

**Corrección de la cifra tras verificar:** en el worktree con stack y BD en frío la primera pasada marcó **143 MB**, por encima del rango 127–129 MB medido en el primario. El rango real es **127–143 MB**; el comentario del target lleva esa cifra. No invalida la decisión (512M sigue dando ~3.5×), pero **descarta 256M**, que era la alternativa cercana. El bloque `Intent` sigue diciendo 127–129 MB porque está congelado — corregirlo es tuyo.

**Por qué 512M:** ~3.5–4× sobre el pico medido — margen para varias épicas — y sigue siendo un techo finito que hace fallar rápido una fuga real. Descartado 1G (igualar coverage): innecesario y retrasa el descubrimiento de un runaway. Descartado 256M: solo 2× de margen.

## Verification

**Commands:**

- `make php.unit` — esperado: `OK` / exit 0, sin `Allowed memory size`; repetir 3 veces.
- `make php.unit c='--filter BankStoredObjectMultipartFunctionalTest'` — esperado: 2 tests ejecutados, exit 0.
- `make php.unit PHPUNIT_MEMORY_LIMIT=32M` — esperado: `Allowed memory size of 33554432 bytes exhausted` (prueba de que el knob gobierna).
- `git diff --stat` — esperado: `make/php-test.mk` + `make/config.mk`.

## Suggested Review Order

- El arreglo entero: el techo pasa a ser una variable, no un literal en la receta.
  [`php-test.mk:28`](../../make/php-test.mk#L28)

- La cifra y su procedencia — por qué 512M y por qué los runs filtrados no necesitan flag.
  [`php-test.mk:18`](../../make/php-test.mk#L18)

- La palanca y su razón de ser: `c=` no puede subir el límite porque cae tras el nombre del script.
  [`config.mk:101`](../../make/config.mk#L101)

- Comentario de coverage devuelto a auto-contenido: ya no referencia el literal del otro target.
  [`php-test.mk:42`](../../make/php-test.mk#L42)

- Lo que este cambio NO arregla, y que Murat considera más grave que el propio OOM.
  [`deferred-work.md:160`](deferred-work.md#L160)
