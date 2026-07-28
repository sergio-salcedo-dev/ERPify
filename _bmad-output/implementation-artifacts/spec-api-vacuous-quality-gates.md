---
title: 'Tres gates que pasan verdes sin comprobar nada'
type: 'chore'
created: '2026-07-28'
status: 'in-review'
review_loop_iteration: 0
baseline_commit: 'e5b0e2f166f502a92c954f8017a8696e80391bd3'
context:
  - '{project-root}/docs/api-error-contract.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** Tres gates pasan verdes sin comprobar nada.

1. El sub-check de frescura documental de `ErrorContractGateTest` (NFR26) hace `markTestSkipped` en **toda** ejecución. Corre en el contenedor, donde `/app` contiene sólo `api/` y `public/` — medido en vivo: sin `.git` y sin `docs/` — y `runGit()` devuelve `null` en cuanto falta `.git` en el cwd. Añadir un marker bajo `api/src/Shared/ErrorContract/Domain/Exception/` sin documentarlo mergea limpio. El override `ERROR_CONTRACT_GATE_BASE` tampoco lo revive: aporta la *ref*, no el repositorio.
2. `OutboxContext::messages()` salta con `continue` cualquier transporte que no sea `InMemoryTransport`, y `transport()` devuelve `null` si el servicio falta. Toda aserción de **cero** eventos y `there should not have been an outbox event created containing:` pasan vacuas si un transporte deja de ser in-memory. Misma clase de defecto que #553 arregló dos líneas más arriba (`$fetchSize` por defecto 1).
3. El filtro `~@wip` de `api/behat.dist.php` no matchea nada — cero `@wip` en 47 features — y su justificación escrita (steps Mink pendientes de migrar) está muerta: cero `Mink` en el árbol. No es config muerta sino un mecanismo de exclusión vivo cuyo propio comentario invita a aparcar un escenario rojo, donde desaparecería de los 373 sin que nada cuente escenarios contra un total esperado.

**Approach:** Convertir el sub-check documental de invariante **de diff** a invariante **de estado** — todo `.php` bajo el directorio de markers debe estar nombrado en `docs/api-error-contract.md` — con el doc montado `:ro` en el contenedor y sin rama de skip. Hacer total `OutboxContext::transport()` para que un transporte no inspeccionable falle en voz alta. Borrar el filtro `~@wip`.

## Boundaries & Constraints

**Always:**
- Todo "verde" sale de una corrida nueva con su exit code impreso.
- Los tres arreglos se **falsifican en vivo** antes de darlos por hechos: romper a propósito → ver rojo → restaurar. Un fixture en memoria no sustituye esa prueba (el gate muerto tenía sus otros sub-checks verdes).
- El sub-check documental no puede volver a tener vía de skip: doc ausente = **fallo** citando el motivo.
- Los cuatro sub-checks vivos de `ErrorContractGateTest` quedan intactos.
- Behat sigue en 373 escenarios.

**Ask First:**
- Si `make php.behat` no queda verde al quitar el filtro.
- Si el montaje del doc obliga a tocar `compose.prod.yaml` o `.github/workflows/ci.yml` (no debería: CI corre con `ENV` sin definir → overlay `compose.dev.yaml`).
- Si PHPStan `level: max` no estrecha el tipo tras `assertInstanceOf` y haría falta un cast.

**Never:**
- No montar `.git` en el contenedor.
- No sacar el gate a un script de host ni tocar `fetch-depth` de CI (alternativa descartada — ver Design Notes).
- No añadir un guard `@wip`: la decisión es quitar el filtro.
- Fuera de alcance: el pin `symfony/mercure-bundle: 0.4.x-dev` (sin tag ≥ `0.4.3` en packagist hoy) y el ordenamiento de listeners 403-antes-de-422 (ya cerrado por #553 en `api/features/backoffice/bank/access_control.feature:132`).

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| Markers documentados | los 12 actuales, todos citados como `` `Nombre` `` | gate verde | N/A |
| Marker nuevo sin documentar | `Gone.php` añadido, doc sin tocar | gate **falla** nombrando el marker y la ruta del doc | N/A |
| Marker citado sin backticks | el doc menciona `Gone` en prosa desnuda | gate **falla**: se exige el formato de cita del doc | N/A |
| Substring accidental | el doc contiene `Gone.php.old` o `GoneAway`, no `` `Gone` `` | gate **falla**: el token es exacto, no substring | N/A |
| Marker citado dos veces | `` `Gone` `` aparece en dos secciones | gate **verde**: se exige presencia, no unicidad | N/A |
| Directorio de markers ausente | la ruta no existe | gate **falla** distinguiendo "no existe" de "vacío" | nunca skip |
| Directorio de markers vacío | la ruta existe, cero `.php` | gate **falla** con el mensaje de "vacío" | nunca skip |
| Doc no visible | contenedor sin el montaje `:ro` | gate **falla** citando ruta y montaje | nunca `markTestSkipped` |
| Cola inspeccionable | `async`, `failed` (in-memory en `APP_ENV=test`) | se lee entera | N/A |
| Cola no in-memory | una `INSPECTABLE_QUEUES` que resuelve a `SyncTransport` | aserción **falla** con cola, service id y clase real | nunca `continue` |
| Servicio de transporte ausente | `messenger.transport.<x>` no registrado | aserción **falla** con cola y service id | nunca `null` |
| Sin escenarios `@wip` | ninguno existe en `api/features` | 373/373, conteo sin cambio | N/A |

</frozen-after-approval>

## Code Map

- `api/tests/Unit/Shared/ErrorContract/Application/ErrorContractGateTest.php` -- `testNewMarkerExceptionWithoutDocsUpdateIsRejected` (:175-220) es el sub-check muerto; `resolveGitBase`/`gitMergeBase`/`gitAddedFiles`/`gitFileChanged`/`runGit` (:369-470) son su fontanería, con dos `@SuppressWarnings` de PHPMD colgando de `runGit`. `apiRoot()` = `/app/api` (montado); `projectRoot()` = `/app` (sólo `api/` + `public/`). El docblock de clase (:12-27) describe el invariante (2) como git-aware. `testFixtureExposesGateMatcher` (:106) es el idiom de la casa para probar un matcher en memoria.
- `compose.dev.yaml` -- volúmenes del servicio `php` (:20-25) y de `messenger_worker` (:113-120); precedente de montaje `:ro` de fichero único (Caddyfile, `20-app.dev.ini`).
- `api/tests/Behat/Context/OutboxContext.php` -- `messages()` (:405-421) con el `continue`; `transport()` (:423-435) devolviendo `?InMemoryTransport`; tres call sites con `?->` (:94 reset, :279 y :290 reject); `messagesOnQueue()` (:379-397) ya trae el guard `assertContains` a imitar.
- `api/behat.dist.php` -- `use Behat\Config\Filter\TagFilter;` (:7), comentario `@wip` (:43-45), `->withFilter(new TagFilter('~@wip'))` (:66).
- `docs/api-error-contract.md` -- `:68` describe el gate como "CI grep gate" (frase además truncada); `:144` afirma que vigila "only new markers".
- `make/php-quality.mk` -- `:78-79` describe el gate citando `api/src/Shared/Domain/Exception/`, ruta que no existe (el gate mira `src/Shared/ErrorContract/Domain/Exception/`).
- `api/config/packages/messenger.yaml` -- `:15` y `:44` declaran `sync: 'sync://'`, el vehículo para falsificar el ítem 2.

## Tasks & Acceptance

**Execution:**
- [x] `compose.dev.yaml` -- montar `./docs/api-error-contract.md:/app/docs/api-error-contract.md:ro` en `php` y en `messenger_worker` (el segundo porque `PHP_SERVICE=messenger_worker` es una vía de ejecución documentada). Va **primero**: el gate no puede quedar verde antes de que el doc sea visible, y el montaje sólo aparece tras recrear el servicio (`make docker.up`), no con un simple restart.
- [x] `api/tests/Unit/Shared/ErrorContract/Application/ErrorContractGateTest.php` -- sustituir el sub-check git-aware por `testEveryMarkerIsNamedInTheContractDoc`: lista los `*.php` del directorio de markers, lee `docs/api-error-contract.md` **una sola vez** y delega en un helper privado `undocumentedMarkers(list<string> $names, string $docBody): list<string>` que exige el nombre como token exacto entre backticks (`` `Gone` ``), no substring. Fallos explícitos y distinguibles entre sí: directorio inexistente, directorio sin `.php`, doc ilegible (citando la ruta y el montaje). Añadir un test de fixture en memoria que ejerza el helper contra un doc sintético — marker ausente, citado sin backticks, substring accidental (`GoneAway`) y citado dos veces — imitando `testFixtureExposesGateMatcher`. Borrar los cinco helpers de git, el env `ERROR_CONTRACT_GATE_BASE` y reescribir el invariante (2) del docblock.
- [x] `api/tests/Behat/Context/OutboxContext.php` -- `transport()` pasa a devolver `InMemoryTransport` no nullable, aseverando servicio presente e instancia correcta; el mensaje de fallo lleva **cola + service id + clase real encontrada**, para diagnosticar sin abrir el contenedor. Quitar el `continue` de `messages()` y los tres `?->`.
- [x] `api/behat.dist.php` -- borrar el filtro, su import y sus tres líneas de comentario; conservar intacto el bloque de `GherkinCompatibilityMode::LEGACY`.
- [x] `docs/api-error-contract.md` -- reescribir `:68` y `:144` al mecanismo real (invariante de estado sobre todos los markers, no diff sobre los nuevos); mantener vivas las notas de `:103`/`:125` que dicen que el gate no dispara al reusar un marker existente.
- [x] `make/php-quality.mk` -- corregir el comentario `:78-79`: ruta real del directorio y semántica de estado.

**Acceptance Criteria:**
- Dado el árbol actual, cuando se ejecuta `make php.lint.error-contract`, entonces exit 0 con **cero** tests skipped.
- Dado un marker temporal sin documentar, cuando se ejecuta `make php.lint.error-contract`, entonces exit ≠ 0 y la salida nombra ese marker; al retirarlo, vuelve a exit 0.
- Dado un marker citado en **dos** secciones del doc, cuando corre el gate, entonces verde: el contrato exige presencia, no unicidad — la unicidad queda como decisión futura, fuera de este alcance.
- Dada una `INSPECTABLE_QUEUES` que incluya temporalmente `sync`, cuando se ejecuta un escenario de outbox, entonces falla nombrando `sync` y `SyncTransport`; al restaurar, verde.
- Dado el árbol sin filtro `~@wip`, cuando se ejecuta `make php.behat`, entonces exit 0 y 378 escenarios (373 era el conteo en `9be7d474`; la rama sale de `e5b0e2f1`, que trae los de #594).
- Dado el diff completo, cuando se ejecutan `make php.stan` y `make php.quality`, entonces exit 0 cada uno.

## Spec Change Log

### 2026-07-28 -- las dos deudas encontradas de paso entran en el alcance (decisión de Sergio)

Se propusieron como seguimiento y Sergio pidió arreglarlas en esta misma rama, así que dejan de ser
"deuda propuesta" y pasan a ser trabajo de esta PR:

1. **Ancla muerta en `docs/api-error-contract.md`.** `#how-to-add-a-new-error` no resolvía porque el
   heading real llevaba `(Amelia walk-through from PRD §Journey 1)`, y tres sitios enlazan la forma
   corta. Arreglar sólo el heading dejaba el doc incoherente: la narrativa de personas seguía en el
   cuerpo de ambas secciones. Retirada entera conservando cada hecho técnico.
2. **`OutboxContext` en su techo de tamaño.** `Support\Messenger\Outbox` se lleva la lectura del
   outbox (438 → 347 líneas); la supresión `ExcessiveClassComplexity` se cae por medición y el
   coupling baja de 14 a 13. Las tres supresiones restantes se verificaron borrando las cuatro y
   volviendo a correr PHPMD. `MessengerConsumerContext`, segundo consumidor real, **no** se migra.

KEEP: el conteo de aserciones de `make php.unit` bajó 9003 → 9000 y **no** lo causa este cambio.
Revertido el árbol al estado exacto que midió 9003, PHPUnit sigue dando 9000: el delta es ambiental
(Behat resetea y resiembra la BD entre corridas, y algún test funcional cuenta según filas). No
atribuir esa cifra al diff si reaparece.

## Design Notes

**Por qué estado y no diff.** El sub-check nació como "el doc cambió en el mismo diff", y eso exige contexto de VCS que el contenedor no tiene y que CI tampoco daría: el job de calidad hace checkout con `fetch-depth` por defecto (1), así que ni existe `origin/main` para el `merge-base`. Revivir el diseño de diff cuesta tres piezas (script de host, target nuevo, `fetch-depth: 0`) y **conserva** la fragilidad que lo mató: cualquier estado sin base utilizable vuelve a saltárselo.

El invariante de estado no necesita base, ni git, ni profundidad de clon; es cierto en reposo y se comprueba desde cualquier checkout. Y es **más estricto** que lo que sustituye: el gate de diff se contentaba con que el doc hubiese cambiado en algún sitio (bastaba una errata), mientras que el de estado exige que el marker esté **nombrado**. Además cubre lo que el de diff no podía: un marker que entrase mientras el gate llevaba meses saltándose. Medido hoy: los 12 markers aparecen en el doc, luego adoptarlo entra verde — es un trinquete, no una deuda. Es también el estilo del sub-check hermano vivo de la misma clase, que valida `api/.error-contract-allowlist` contra el sistema de ficheros.

**Por qué el token entre backticks y no un substring.** El doc cita cada marker como `` `Nombre` `` (tablas y prosa por igual), así que exigir ese formato es a la vez el matcher más estricto disponible y la convención que el propio doc ya sigue. Un substring desnudo se satisface por accidente con `Gone.php.old` o `GoneAway`; el token exacto no. Medido contra el doc actual: los 12 markers aparecen entre backticks (mínimo 1, `RateLimitExceeded`), luego el matcher estricto entra verde sin tocar el doc. Estrictez asumida y nombrada: documentar un marker **sin** backticks deja el gate rojo aunque el texto exista — es deliberado, obliga a la convención del doc en vez de aceptar cualquier mención.

Coste asumido y nombrado: se pierde la semántica temporal "en el mismo diff" (un marker documentado en un PR posterior pasaría el gate del PR que lo introduce sólo si ya está nombrado — es decir, no pasaría), y se añade un montaje `:ro` de un markdown. Se descarta montar `.git`: amplía la superficie del contenedor con el historial completo para un chequeo de texto.

**Por qué `transport()` total y no un guard en `messages()`.** Tell-don't-ask: hoy cada call site pregunta "¿me sirve esto?" con `?->` y decide callar. Con el helper garantizando el tipo, el `continue` y los tres `?->` desaparecen y la imposibilidad de inspeccionar deja de ser un camino silencioso. Ambos conjuntos de colas (`INSPECTABLE_QUEUES`, `RESETTABLE_QUEUES`) son `['async','failed']` y ambos están declarados `in-memory://?serialize=true` bajo `APP_ENV=test`, así que la totalidad es segura hoy y, si dejara de serlo, eso es exactamente lo que hay que gritar.

## Verification

**Commands:**
- `make php.lint.error-contract` -- expected: exit 0 y **`Skipped: 0`** (hoy: `Tests: 5, Assertions: 5, Skipped: 1`; tras el cambio ≥ 6 tests, ninguno skipped)
- `make php.unit` -- expected: exit 0
- `make php.behat` -- expected: exit 0, 378 escenarios
- `make php.stan` -- expected: exit 0
- `make php.quality` -- expected: exit 0
- `docker compose exec php ls -l /app/docs/api-error-contract.md` -- expected: el fichero existe dentro del contenedor

**Manual checks:**
- Falsificación del ítem 1: crear `api/src/Shared/ErrorContract/Domain/Exception/Gone.php`, correr el gate (debe fallar nombrándolo), borrarlo, volver a correr (verde).
- Falsificación del ítem 2: añadir `'sync'` a `INSPECTABLE_QUEUES`, correr un escenario de outbox (debe fallar nombrando `SyncTransport`), restaurar.
- Falsificación del ítem 3: crear un escenario `@wip` que falle a propósito y comprobar que ahora **se ejecuta** y sale rojo; borrarlo.
</content>
