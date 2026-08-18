---
title: 'Endpoints de salud: exención pública única, contrato de dos ejes de estado y gate sobre PUBLIC_ACCESS (#295, #222)'
type: 'feature'
created: '2026-08-18'
status: 'in-progress'
baseline_commit: '85c1687c'
review_loop_iteration: 0
context:
  - '{project-root}/docs/api-error-contract.md'
  - '{project-root}/PRODUCTION_SECURITY_CHECKLIST.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** #222 y #295 deciden el mismo contrato sobre las tres rutas de salud y ninguna está cerrada. Medido: `/api/v1/backoffice/health` está exento de autenticación sin ningún consumidor anónimo en la PWA (su página monta tras `RequireAuth`; `/status` usa el liveness de *frontoffice*) — sólo lo curlean dos runbooks y Postman; en cambio la exención que sí tiene consumidor real (`/api/v1/health`: `scripts/deploy/deploy.sh:134` exige `200`, y la página pública `/status`) **no la afirma ningún escenario anónimo**, así que retirarla por error no pondría nada rojo. Y la lista `PUBLIC_ACCESS` de `security.yaml` (8 entradas) no tiene ningún control: `PermissionCatalogCoversEveryGatedRouteTest` cubre permisos ⊂ catálogo, no rutas públicas.

**Approach:** Reducir la superficie pública a una sola ruta de salud, escribir el contrato de código de estado que hoy es accidental, y sustituir el anclaje `$` como «control» tácito por un gate que derive del árbol real qué exenciones existen y exija que cada una esté clasificada.

## Boundaries & Constraints

**Always:**
- La superficie pública de salud queda en **una** ruta: `^/api/v1/health$`.
- **Dos ejes de estado, nunca fundidos.** El **código HTTP** dice si la aplicación pudo *admitir y decidir* la petición. **`data.status`** dice el resultado de la comprobación de dependencia que el controlador sí llegó a ejecutar. De ahí que `200 + data.status=error` sea la respuesta correcta de un probe degradado, y no una contradicción: la API está en pie.
- **El `200` es el camino normal del controlador, no una propiedad del endpoint.** Los liveness ya devuelven `500 problem+json` cuando algo lanza antes de ellos, y el probe profundo devuelve `401` sin sesión y `503` con el almacén de sesión caído. La palabra «always-200» queda prohibida en la documentación que este spec escriba.
- Todo no-2xx sale por el pipeline RFC 9457. Nunca un `JsonResponse` de error a mano.
- El gate **deriva su conjunto esperado de `security.yaml`**; no lleva lista de excepciones propia.
- Cada pin que este spec añada o modifique se **falsifica en ambas direcciones**, y todos se re-miden al final.

**Ask First:**
- Si aparece un consumidor anónimo real de `/api/v1/backoffice/health` fuera de los dos runbooks y Postman.
- Si el gate pidiera una allowlist **independiente de `security.yaml`**: eso significa que la fuente de verdad está mal elegida, no que falte una excepción.
- Si cerrar la exención obligara a tocar el flujo de autenticación de la PWA.

**Never:**
- No añadir 503 al probe de BD ni una ruta de readiness nueva. Descartadas con argumento: por el marcador `ServiceUnavailable` el cuerpo pasa a `problem+json` (la PWA pierde `service`/`datetime` — `FetchHttpClient` lanza en `!res.ok` antes de parsear, y `deriveSystemStatus` colapsa DEGRADED→DISRUPTED) y además llega a Sentry, un evento por sondeo mientras dure la caída; una ruta de readiness tendría que ser anónima para servir a un balanceador, lo que reabre la invariante 2 de #222.
- No cambiar el sobre ni el payload de ningún endpoint de salud.
- No exigir anclaje `$` universal: `^/api/test/` es un prefijo deliberado (`when@test`, inerte en prod). El anclaje se **clasifica por entrada**.
- No tocar `CLAUDE.md`, `docs/project-context.md`, `docs/index.md`, `docs/claude-code-quickref.md`, `docs/contribution-guide.md`, `docs/development-guide-pwa.md` ni `pwa/README.md`.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|---|---|---|---|
| Liveness público | `GET /api/v1/health`, sin sesión | `200`, `data.status=ok`, 0 consultas Doctrine | N/A |
| Liveness backoffice cerrado | `GET /api/v1/backoffice/health`, sin sesión | `401`, `application/problem+json`, `type=unauthenticated`, **sin nodo `data`** (prueba que el controlador no corrió) | pipeline RFC 9457 |
| Liveness backoffice con sesión | idem, autenticado | `200`, `data.status=ok` (sin cambio) | N/A |
| Fallo antes del controlador | `?_force_failure=1` en cualquiera de los dos liveness | `500`, `type=unhandled-exception` (sin cambio) | pipeline RFC 9457 |
| Probe profundo anónimo | `GET /api/v1/backoffice/health/database`, sin sesión | `401` (sin cambio) | pipeline RFC 9457 |
| Probe profundo degradado | autenticado; la sesión se lee, el `SELECT 1` agota los 2 s | `200`, `data.status=error` | warning en canal `observability` |
| Almacén de sesión caído | petición autenticada cuya admisión requiere leer la sesión, y Postgres no responde | `503` `service-unavailable` desde `SessionAdmissionGate`, antes del controlador | preexistente, no se toca |
| Exención sin clasificar | entrada `PUBLIC_ACCESS` nueva en `security.yaml` | gate ≠ 0, nombrando el patrón | N/A |
| Entrada huérfana | línea en el registro que ya no está en `security.yaml` | gate ≠ 0, nombrando la línea | N/A |
| Patrón divergente | el registro escribe un patrón equivalente pero no idéntico al de `security.yaml` | gate ≠ 0 | N/A |
| Anclaje retirado | se quita el `$` de una entrada clasificada como anclada | gate ≠ 0 | N/A |

</frozen-after-approval>

## Code Map

- `api/config/packages/security.yaml:42-65` — las 8 entradas `PUBLIC_ACCESS` (7 con `$`, `^/api/test/` prefijo deliberado); línea 56 es la exención a eliminar.
- `api/features/backoffice/identity/access_control.feature:18-21` — hoy afirma **200 anónimo** en `/backoffice/health`; es el pin que se invierte.
- `api/features/frontoffice/health/get.feature` — corre autenticado; **falta** el `@anonymous` 200 que sostenga la exención superviviente.
- `api/features/backoffice/health/database.feature:6-12` — forma de escenario `@anonymous` → 401 a imitar.
- `api/features/shared/error_contract/health_endpoints_conformance.feature` — 4 escenarios (2 sobre backoffice health); autenticados, siguen verdes; revisar redacción.
- `api/tests/Functional/Shared/Http/Infrastructure/HealthEndpointsContractTest.php:50,54-74` — `createClient()` **sin sesión** esperando 200/500 en la ruta de backoffice: sus dos tests de backoffice pasan a 401.
- `api/tests/Functional/.../RateLimitListenerFunctionalTest.php:38` — usa `/api/v1/backoffice/health` como «200 estable» sin sesión; medir si cae.
- `api/tests/Behat/Context/SecurityContext.php` — autentica a Alice por defecto; `@anonymous` es el opt-out.
- `api/tools/phpunit/phpunit.dist.xml:10` — `failOnEmptyTestSuite="true"`: es lo que hace que un `--filter` por clase convierta una clase desaparecida en fallo.
- `make/php-quality.mk` — patrón de sección + target; los **cuatro** sitios: target, `php.quality`, `php.quality.dry-run`, `.PHONY`.
- `api/.audit-resource-types` — idioma de cabecera de registro (formato + «lo que un verde NO demuestra»).
- `PRODUCTION_SECURITY_CHECKLIST.md:126-157`, `docs/architecture-api.md:105`, `docs/api-error-contract.md` — afirmaciones a reescribir.
- `api/docs/production-ready/hardening.md:104`, `server-setup.md:89`, `api/docs/postman/erpify-api.postman_collection.json:26` — consumidores anónimos a migrar.

## Tasks & Acceptance

**Execution:**
- [x] `api/config/packages/security.yaml` — eliminar la entrada `^/api/v1/backoffice/health$` y rehacer el comentario para razonar sobre **una** exención — es el cambio de comportamiento del que cuelga todo lo demás.
- [x] `api/features/backoffice/identity/access_control.feature` — invertir el escenario a `401` y mover el ejemplar de «ruta pública» a `/health` — el pin existente afirma lo contrario de lo decidido.
- [x] `api/features/frontoffice/health/get.feature` — añadir `@anonymous` que exija `200` — hoy nada sostiene la exención de la que depende `deploy.sh:134`.
- [x] `api/features/backoffice/health/get.feature` — añadir `@anonymous` que exija el contrato **completo** del cierre: `401`, `application/problem+json`, `type=unauthenticated` y ausencia de nodo `data` — el status solo no distingue un rechazo del firewall de un controlador que respondió.
- [x] `api/tests/Functional/Shared/Http/Infrastructure/HealthEndpointsContractTest.php` — autenticar el cliente para la ruta de backoffice, dejando la de frontoffice anónima — el contrato RFC 9457 debe seguir probado en ambas.
- [x] `api/tests/Functional/.../RateLimitListenerFunctionalTest.php` — medir si depende del 200 anónimo y repararlo si sí — daño colateral, no rediseño.
- [x] `api/.public-access-exemptions` — registro nuevo: una línea por entrada `PUBLIC_ACCESS`, con su patrón **literal**, su consumidor anónimo declarado y su forma de coincidencia — `exacta`, o `prefijo :: <fichero que lo acota>`. Un prefijo **nombra la cosa que lo mantiene acotado**, no escribe una razón: para `^/api/test/` es `api/config/routes/test.yaml`, el único fichero que registra rutas bajo ese prefijo y que las envuelve en `when@test:`. Cabecera con formato y **lista explícita de lo que un verde NO demuestra**.
- [x] `api/tests/Unit/Shared/Architecture/PublicAccessExemptionGateTest.php` — gate sobre el árbol real, cinco invariantes: (1) cada `PUBLIC_ACCESS` de `security.yaml` tiene exactamente una línea; (2) cada línea corresponde a exactamente una `PUBLIC_ACCESS`; (3) el patrón registrado es **idéntico**, no equivalente; (4) una entrada `exacta` termina en `$` y una `prefijo` no; (5) para cada `prefijo`, el fichero que nombra existe y **cumple de verdad** lo que lo acota — toda ruta declarada bajo ese prefijo vive dentro de `when@test:` del fichero nombrado. El barrido cubre el **directorio** `api/config/routes/` **y el fichero** `api/config/routes.yaml` — este último es donde viven los seis recursos `type: attribute`, y es por donde entraría la excepción que el barrido del directorio no vería. **El universo se extrae parseando el YAML** (`security.access_control[].path` con `roles` conteniendo `PUBLIC_ACCESS`), nunca grepeando el token.
- [x] `api/tests/Unit/Shared/Architecture/PublicAccessExemptionRulesGateTest.php` — falsabilidad de las cuatro reglas sobre entrada sintética, en **ambas** direcciones (sobrante en `security.yaml` y sobrante en el registro).
- [x] `make/php-quality.mk` — sección con su bloque de razón, target `php.lint.public-access` con un `--filter` de nombre exacto por clase, y los cuatro sitios.
- [x] `PRODUCTION_SECURITY_CHECKLIST.md`, `docs/architecture-api.md`, `docs/api-error-contract.md` — escribir el contrato de dos ejes y la exención única; una sonda degradada **no** es `ServiceUnavailable`.
- [x] `api/docs/production-ready/hardening.md`, `server-setup.md`, `api/docs/postman/erpify-api.postman_collection.json` — migrar los curls a `/api/v1/health`; de paso, `hardening.md:106` curlea `/backoffice/banks` esperando 200 y esa ruta exige sesión desde que llegó la auth (regla del boy-scout; se nombra en el resumen).

**Acceptance Criteria:**
- Dado un despliegue sin sesión, cuando `deploy.sh` curlea `/api/v1/health`, entonces recibe `200` y un escenario `@anonymous` lo afirma.
- Dado un atacante sin sesión, cuando pide `/api/v1/backoffice/health`, entonces recibe `401` Problem Details sin nodo `data`, y ningún escenario afirma ya lo contrario.
- Dado el conjunto de entradas `PUBLIC_ACCESS` de `security.yaml`, entonces es **biyectivo** con el registro, y el gate falla si sobra en cualquiera de los dos lados o si un patrón difiere textualmente.
- Dado un anclaje `$` retirado de una entrada clasificada `exacta`, cuando corre el gate, entonces falla nombrando el patrón — el vector que volvió anónimo al probe de BD.
- Dada una ruta bajo `/api/test/` declarada fuera del `when@test:` de `api/config/routes/test.yaml`, cuando corre el gate, entonces falla — porque «inerte en producción» deja de ser cierto y hoy no lo comprueba nada.
- Dado Postgres inalcanzable, cuando entra una petición autenticada cuya admisión requiere leer la sesión, entonces la respuesta es `503` `service-unavailable` — preexistente, no modificado.
- Dado `make php.quality` y `make pwa.quality` desde el worktree, entonces ambos salen `0` con su código impreso.

## Pase adversarial

**Dónde se registra:** aquí, y ejecutado ANTES de `gh pr create` — no después. Dos lecturas hostiles
independientes y ciegas entre sí, en contextos frescos, sobre este worktree en modo solo-lectura: una con
lente de **autorización y consumidores**, otra con lente de **falsabilidad del gate**. Cambió el resultado,
no lo confirmó: el gate que yo daba por bueno estaba roto de tres formas distintas, todas medidas con su
mutación y su exit code, y ninguna visible para ningún gate verde.

### GRAVE — corregidos

| # | Hallazgo medido | Corrección |
|---|---|---|
| G1 | El barrido de rutas era `config/routes/*.yaml`. Symfony importa `{routes}/*.{php,yaml}` **y** `{routes}/{env}/*.{php,yaml}`. Medido: un `config/routes/leak.php` con `/api/test/pwn-php` deja el gate en exit 0 y `debug:router --env=prod` **lista la ruta viva**. | El lector barre las cuatro formas. El YAML se parsea; el PHP **no se puede** parsear como datos, así que se casa como texto y cualquier mención del prefijo se reporta — grosero a propósito, y declarado como tal en la cabecera. |
| G2 | El universo exigía `path:` + el token en `roles`. Symfony no: una regla con `route: <nombre>` pasa desapercibida, y una con `roles: []` o sin `roles` también — `AccessListener::authenticate` hace `if (!$attributes) return;` y **salta la decisión entera**. Medido en vivo: con esa línea puesta, `curl` anónimo a `/api/v1/backoffice/health/database` devolvió `200` con el sondeo a Postgres, y `make php.lint.public-access` **verde**. | El universo se deriva del **efecto** de la regla, no del token: las tres formas se recogen. Una regla con `allow_if` o `request_matcher` se **rechaza** en vez de asumirse segura. |
| G3 | `exact` sólo comprobaba el sufijo `$`. Medido: `^/api/v1/(health|backoffice/health/database)$` y `^/api/v1/health.*$` clasificados `exact` pasan en exit 0 — el segundo reabre exactamente el subárbol cuya pérdida de anclaje volvió anónima la sonda de BD. | `exact` exige **camino literal** entre las anclas (sin metacaracteres). Lo que el ancla compra es *un objetivo*, no *estar anclado*. |

### SERIO / MENOR — corregidos

- **La mitad `prefix` era vacua** si el lector no encontraba ficheros de rutas (mover el directorio → exit 0). Añadido un ancla de población para las fuentes de rutas, además de la que ya existía para `security.yaml`.
- **El barrido de recursos `attribute` sólo miraba el nivel superior**; anidado bajo `when@prod:` se escapaba (A/B: exit 2 vs exit 0). Ahora recorre en profundidad.
- **La gramática del registro aceptaba consumidor vacío y campos sobrantes.** Ambos se rechazan. Corolario cazado por el propio endurecimiento: una línea describía `Security::login`, y `::` es el separador — reescrita.
- **Afirmación FALSA mía, no del gate:** el docblock de `RateLimitListenerFunctionalTest` decía que tras el firewall un 401 «no probaría nada del presupuesto». Medido: el limitador corre en `kernel.request` prioridad **512** contra la **8** del firewall, así que un 401 sí consume token y sella ambas familias de cabeceras. Lo único que obligaba al traslado son tres `assertSame(HTTP_OK, …)`. Docblock reescrito con la medición. *Corolario: el cambio no reduce ni un token la superficie del limitador anónimo.*
- **Cifras rancias introducidas por el propio diff** («nueve veces sobre ocho entradas», «siete de ocho acaban en `$`»): ciertas *antes* de retirar la línea. Re-medidas: **8 apariciones del token / 7 reglas**, **6 `exact` + 1 `prefix`**. Corregidas en `make/php-quality.mk` y en la cabecera del registro, o eliminadas donde no aportaban.
- **Consumidor anónimo omitido:** `.github/workflows/ci.yml:105` y `:183` curlean `/api/v1/health` en cada PR. Añadido al registro, a `security.yaml` y al checklist — el contrato del registro es nombrar al consumidor.
- **Duda elevada a hallazgo:** nada comprobaba que la regla terminal `^/api → IS_AUTHENTICATED_FULLY` existiera ni fuera la última. Borrarla dejaba el gate verde con la biyección perfecta. Añadida esa invariante.

### La cabecera prometía de más

Dos afirmaciones se han retirado por falsas: que una exención por «otro mecanismo» no existía hoy (G2 vive en la MISMA sección `access_control`), y que el barrido de recursos `attribute` cubría todos (no cubría los anidados). Es el defecto que más importa de los siete: un lector habría creído cubierto lo que no lo estaba.

### Lo que los pases NO consiguieron romper

Consumidor sin migrar: **ninguno**, tras barrer `scripts/`, `.github/workflows/`, `compose*.yaml`, `api/docs/`, `docs/`, Postman, `pwa/src` y `pwa/tests`. Ensanchamiento por orden de reglas: **nada** — retirar la línea no recae ninguna ruta en otra regla. Rebote de la PWA, bucle o fuga en `?next=`: **nada** (el único emisor nuevo de 401 vive tras `RequireAuth`, el redirect es single-flight y el `next` sería `/backoffice/health`). Despliegue: **no roto**. Y la aserción `the JSON node "data" should not exist` es falsable de verdad.

### Falsificación final del gate — 11 vectores, tras las correcciones

Verde antes, once rojos distintos, verde después; ficheros restaurados por bytes y verificados con `cmp`, sin residuos:
sin clasificar · huérfana · patrón divergente · ancla retirada · `exact` con metacaracter · exención `route:` ·
`roles: []` · `allow_if` · ruta fuera de `when@test` · fichero `.php` de rutas mencionando el prefijo · catch-all borrado.

### Nota operativa

El worker de FrankenPHP sirvió el contenedor compilado viejo minutos después de revertir un fichero: **verificar por `curl` sin reiniciar `php` mide caché, no configuración.**

## Spec Change Log

- **Pase adversarial (2 lentes, previo a abrir la PR)** — tres GRAVE, tres SERIO y varios MENOR sobre el gate y sobre dos afirmaciones mías. Enmendado: el universo se deriva del efecto de la regla y no del token `PUBLIC_ACCESS`; `exact` exige camino literal; el barrido de rutas cubre las cuatro formas que Symfony importa; se añade la invariante del catch-all; se endurece la gramática del registro; se corrigen cifras y una justificación falsa. Estado malo evitado: una PR que cerraba una issue de exención de autenticación **con un gate que dejaba pasar la propia regresión que narra**, y con una cabecera que prometía cobertura que no tenía. KEEP: la separación en cuatro clases por sujeto (lector / gramática / reglas / acotación de prefijo) sobrevivió a dos rondas de PHPMD y es lo que hizo baratas estas correcciones; y la disciplina de re-falsificar TODOS los vectores después de cada refactor, no sólo los nuevos.

## Design Notes

**Por qué #295 se cierra sin 503.** Su premisa era que un monitor externo lee el código de estado y vería sano un backend con la BD caída. Dos hechos la retiran. Primero: el único cuerpo que puede decir `error` exige sesión, y ningún balanceador porta cookie — el 503 se emitiría para nadie; medido, ningún `healthcheck` de Compose consume HTTP de la app (`:2019/metrics`, `pg_isready`, `kill -0 1`), así que el consumidor de #295 no existe en el árbol. Segundo, y más fuerte: **ese 503 ya se emite** por el eje correcto — `SessionAdmissionGate` (prioridad 7) deja propagar `SessionStoreUnavailable` cuando `DoctrineSessionRepository::findActiveById` captura un `DbalException`, así que con Postgres caído la respuesta es 503 `problem+json` sin llegar al controlador. La rama `error` del cuerpo sólo es alcanzable en la banda donde la sesión se lee y el `SELECT 1` no: exactamente donde un 503 mentiría.

**Por qué el anclaje se clasifica y no se exige.** Siete de las ocho entradas terminan en `$`; `^/api/test/` es un prefijo deliberado sobre rutas que sólo existen bajo `when@test`. Un gate que exigiera `$` universal se pondría rojo sobre una línea correcta y acabaría con una supresión — que es cómo mueren estos controles. Clasificar obliga a que la decisión de ensanchar se escriba y se revise, que es lo que faltó cuando el probe de BD heredó una exención de prefijo.

**Por qué el gate parsea YAML y no grepea.** `grep -c PUBLIC_ACCESS` sobre `security.yaml` devuelve **9**; entradas reales hay **8**. La novena es un comentario que contiene el token. Un gate que derive su universo del token vería una exención fantasma que ningún registro puede satisfacer y, peor, seguiría contando como viva una entrada que alguien comentase. El universo se extrae de `security.access_control[]` filtrando por `roles`. Dos formas que hoy no existen y que la cabecera debe declarar como **no cubiertas**: una entrada bajo `when@test` u otro bloque de entorno, y `roles: [PUBLIC_ACCESS]` en lista en vez de escalar. Precedente en el repo: `php.lint.schedule-consumption` parsea los compose como YAML por exactamente esta razón.

**Trampas medidas al escribir las clases.** PHPMD `TooManyPublicMethods` es >10 y **cuenta los `#[DataProvider]` estáticos**; no hay baseline ni precedente de supresión — si aprieta, repartir por sujeto. Y un callable-string con backslash inicial (`'\trim'`) Rector lo reescribe a algo que no parsea, y sólo PHPMD lo detecta, con un stack que se lee como fallo suyo.

## Verification

**Commands:**
- `make php.behat c='api/features/backoffice/health api/features/frontoffice/health api/features/backoffice/identity/access_control.feature api/features/shared/error_contract/health_endpoints_conformance.feature'` — verdes.
- `make php.unit c='--filter HealthEndpointsContractTest'` y `--filter RateLimitListenerFunctionalTest` — verdes.
- `make php.lint.public-access` — verde; y **rojo provocado uno a uno** en los cuatro vectores de la matriz (sin clasificar, huérfana, patrón divergente, anclaje retirado), restaurando por bytes tras cada uno y re-midiendo todos al final.
- `make php.quality` y `make pwa.quality` — con su exit code impreso.
- `curl -k -i https://localhost:<puerto>/api/v1/backoffice/health` → 401; `…/api/v1/health` → 200.

**Manual checks:**
- `git diff origin/main...` no contiene ninguno de los ficheros listados en **Never**.
