---
title: 'DW-10 — el gate de schedule-consumption resuelve compose.dev.yaml como overlay'
type: 'bugfix'
created: '2026-09-24'
status: 'done'
baseline_revision: '8dfc446d77897577690339ff0f606c1d4c632137'
review_loop_iteration: 0
followup_review_recommended: false
context: []
warnings: []
deferred:
  - summary: >-
      El merge de pila sólo modela `command`; `include:`, `extends:`, `profiles:`, `entrypoint:` y `deploy.replicas: 0` en un overlay se ignoran en silencio.
    evidence: |-
      Son claves YAML planas: parsean sin error y ComposeStackCommands::of() no las mira, así que un consume heredado por extends o traído por include se lee como "no consume nada", y un servicio bajo profiles se cuenta como consumidor aunque no arranque. Preexistente: el lector por fichero tenía el mismo punto ciego (el docblock del gate ya nombra extends). BoundedContainerLogRetentionGateTest ya rechaza include; el mismo rechazo aquí cerraría esa mitad.
    location: >-
      api/tests/Support/ComposeStackCommands.php:34
    severity: medium
  - summary: >-
      El bullet "Declaring an #[AsSchedule]" del CLAUDE.md raíz no menciona que un `command:` en un overlay reemplaza el de la base.
    evidence: |-
      Sigue siendo correcto para el árbol actual (añadir el transporte en compose.yaml y compose.prod.yaml), pero no avisa de la trampa que este cambio cierra. Editar ficheros de contexto de agente se difiere por regla del workflow.
    location: >-
      CLAUDE.md
    severity: low
---

<intent-contract>

## Intent

**Problem:** `ScheduleConsumption::COMPOSE_FILES` lee `compose.yaml` y `compose.prod.yaml` como ficheros sueltos, pero Compose los ejecuta como pilas (`make/config.mk`: dev = `compose.yaml + compose.dev.yaml`, prod/staging = `compose.yaml + compose.prod.yaml`). Un `command:` en `compose.dev.yaml` reemplazaría el de `messenger_worker` (o `command: ~` lo borraría) sin que `php.lint.schedule-consumption` lo viese; y por simetría, si `compose.prod.yaml` dejara de redefinir el `command` de `messenger_worker`, prod heredaría el consumo de los `scheduler_*` de la base en el pool escalable y la lectura por fichero tampoco lo vería.

**Approach:** Sustituir la lectura por fichero por una lectura por **pila** (base + overlay, en el orden de `make/config.mk`) que resuelve el `command` efectivo de cada servicio como lo hace Compose — el último fichero que declara la clave gana entero, y un `null` la elimina —, y ejecutar las tres direcciones del gate (forward, servicio equivocado, stale) sobre esas pilas. Añadir fixtures de falsificación del merge.

## Boundaries & Constraints

**Always:** reglas de merge medidas con `docker compose config` (Compose v5): `command` del overlay reemplaza la lista completa (no concatena); `command: ~` en el overlay elimina el comando heredado; `command: []` lo sustituye por vacío. El eje de réplicas (`REPLICA_SCOPE`, `declaredReplicasOf`) queda por fichero, sin cambios. Un fichero de la pila ilegible o sin `services` sigue lanzando `RuntimeException`. El gate sigue fallando (no saltando) si los compose no son alcanzables.

**Never:** no tocar ningún compose de la raíz ni `deferred-work.md`; no implementar `extends`/`include`/`!reset`/`!override` (fuera de alcance; un tag custom hace lanzar al parser, que es fallo, no verde); no reimplementar el merge de otras claves que no sean `command`.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| Overlay sin `command` | base consume `scheduler_alpha`; overlay redefine el servicio sin `command` | se hereda: `scheduler_alpha` consumido | — |
| Overlay reemplaza | overlay `command` sin `scheduler_alpha` | no consumido (forward en rojo) | — |
| Overlay anula | overlay `command: ~` | el servicio no consume nada | — |
| Overlay añade fantasma | overlay `command` con `scheduler_ghost` | stale lo reporta | — |
| Overlay introduce servicio | servicio sólo en el overlay | su `command` cuenta | — |
| Pila vacía | lista de ficheros vacía | — | `RuntimeException` |

</intent-contract>

## Code Map

- `api/tests/Support/ScheduleConsumption.php` -- motor de reglas. `COMPOSE_FILES` (l.24) y `SCHEDULER_CONSUMER` (l.35) son por fichero; `consumedTransportsByServiceIn()` usa `isset($definition['command'])` (trata `null` como ausente — incorrecto en overlay); `consumedTransportsIn()` y `unbackedSchedulerTransportsIn()` delegan en él. `REPLICA_SCOPE`/`declaredReplicasOf`/`replicaConsumerOf` NO se tocan.
- `api/tests/Unit/Gate/ScheduleConsumptionGateTest.php` -- aserciones sobre el árbol real; `composeFiles()` provider y `composeDirectory()` (usa `COMPOSE_FILES[0]`).
- `api/tests/Unit/Gate/ScheduleConsumptionRulesGateTest.php` -- falsificación con fixtures de un solo fichero (siguen valiendo como pila de un fichero).
- `api/tests/Unit/Gate/Fixture/ScheduleConsumption/` -- fixtures YAML; añadir los de base+overlay aquí.
- `make/config.mk:83-89` -- fuente de verdad de las pilas (sólo lectura).
- `api/CLAUDE.md:69`, `docs/architecture-api.md:284` -- prosa que describe el gate como "ambos ficheros".

## Tasks & Acceptance

**Execution:**
- `api/tests/Support/ScheduleConsumption.php` -- reemplazar `COMPOSE_FILES` por `COMPOSE_STACKS` (`'dev' => ['compose.yaml','compose.dev.yaml']`, `'prod' => ['compose.yaml','compose.prod.yaml']`), re-clavar `SCHEDULER_CONSUMER` por entorno, añadir `BASE_COMPOSE_FILE = 'compose.yaml'`; `consumedTransportsByServiceIn(string ...$composeFiles)` resuelve el `command` efectivo con `array_key_exists` (último gana, `null` elimina) y lanza con pila vacía; `consumedTransportsIn(string ...)`; `unbackedSchedulerTransportsIn(array $declared, string ...$composeFiles)` -- el gate lee lo que Compose ejecuta.
- `api/tests/Unit/Gate/ScheduleConsumptionGateTest.php` -- provider por entorno que pasa las rutas de la pila; mensajes nombran entorno y ficheros; `composeDirectory()` sobre `BASE_COMPOSE_FILE`; docblock actualizado -- mismas tres direcciones, sobre pilas.
- `api/tests/Unit/Gate/ScheduleConsumptionRulesGateTest.php` + fixtures nuevos `compose.overlay-base.yaml`, `compose.overlay-silent.yaml`, `compose.overlay-replaces.yaml`, `compose.overlay-nulls.yaml`, `compose.overlay-ghost.yaml`, `compose.overlay-introduces.yaml` -- un test por fila de la matriz + pila vacía; adaptar la llamada existente a `unbackedSchedulerTransportsIn` -- falsificabilidad del merge.
- `api/CLAUDE.md`, `docs/architecture-api.md` -- una frase: el gate resuelve cada pila base+overlay como Compose, así que un `command:` en `compose.dev.yaml` cuenta.

**Acceptance Criteria:**
- Given `compose.dev.yaml` con un `command:` para `messenger_worker` que omite un `scheduler_*`, when corre `make php.lint.schedule-consumption`, then falla nombrando el entorno dev (verificado plantándolo y restaurando los bytes).
- Given el árbol actual, when corre `make php.lint.schedule-consumption`, then pasa.
- Given `compose.prod.yaml` sin el `command` de `messenger_worker`, when corre el gate, then falla por consumo en el servicio equivocado (verificado plantándolo y restaurando).

## Spec Change Log

## Review Triage Log

### 2026-09-24 — Review pass
- verdicts: 23 findings — high 0, medium 6, low 12, false 5, maybe-false 0
- findings:
  - `[medium]` `[patch]` (Blind) COMPOSE_STACKS copia a mano make/config.mk sin ninguna comprobación — añadido `theStacksAreTheOnesMakeHandsToCompose`, que lee config.mk y compara cada lista `-f` en orden (prod/staging→prod, else→dev); falsificado revirtiendo `dev` a la base sola.
  - `[medium]` `[defer]` (Blind) `include:`/`extends:` se ignoran en silencio — preexistente (el lector por fichero igual); diferido en frontmatter.
  - `[low]` `[defer]` (Blind) `entrypoint:`/`profiles:`/`replicas: 0` en overlay no modelados — mismo origen que el anterior; diferido.
  - `[low]` `[patch]` (Blind) docs/claude-code-quickref.md seguía diciendo "ambos compose.yaml y compose.prod.yaml" — reescrito a ambas pilas mergeadas.
  - `[false]` `[reject]` (Blind) comentario del bind mount en compose.dev.yaml "rancio" — sigue describiendo correctamente lo que el gate exige sobre el árbol actual, y la razón "directorio, no ficheros" vale igual con tres ficheros.
  - `[low]` `[defer]` (Blind) bullet del CLAUDE.md raíz no menciona el reemplazo por overlay — fichero de contexto de agente; diferido.
  - `[low]` `[patch]` (Blind) `command: []` medido pero sin test — añadidos `compose.overlay-empties.yaml` y su test. (`command: ""` y cuerpo `~` no añadidos: sin medición propia y sin uso.)
  - `[low]` `[patch]` (Blind) fixtures no reproducibles con `docker compose config` — `image: alpine` en base/introduces y versión + comando (Compose v5.5.1) en el docblock de ComposeStackCommands.
  - `[low]` `[reject]` (Blind) parseo "declares no services" triplicado — refactor más allá de corrección directa, deriva improbable.
  - `[low]` `[patch]` (Blind) claves SCHEDULER_CONSUMER↔COMPOSE_STACKS sólo en una dirección — igualdad de conjuntos añadida en el mismo test.
  - `[low]` `[reject]` (Blind) el mensaje forward aconseja mal con `command: ~` — distinguir casos añade ramas; mensaje sólo orientativo.
  - `[low]` `[patch]` (Blind) cabecera de overlay-silent afirma "lo que compose.dev.yaml hace hoy" — describe ahora sólo la forma (igual en overlay-introduces).
  - `[medium]` `[defer]` (Edge) servicio con `extends` — agrupado con include/extends; diferido.
  - `[medium]` `[defer]` (Edge) `include:` de nivel superior — agrupado; diferido.
  - `[low]` `[defer]` (Edge) servicio bajo `profiles:` — agrupado con claves no modeladas; diferido.
  - `[low]` `[reject]` (Edge) compose.yaml ya no se revisa solo en la dirección stale — sólo afecta a un `docker compose up` desnudo que el repo prohíbe; cubrirlo añade una pila extra.
  - `[false]` `[reject]` (Edge) los tests del merge no están en ScheduleConsumptionRulesGateTest — la clase nueva está cableada en `php.lint.schedule-consumption` y en `.artifact-gate-placement`; el target es la interfaz.
  - `[medium]` `[patch]` (VerifGap) COMPOSE_STACKS vs make/config.mk sin verificar — mismo grupo que el primero; mismo parche.
  - `[low]` `[patch]` (VerifGap) la promesa "un tag !reset hace fallar la lectura" no tiene test — añadidos `compose.overlay-reset.yaml` y test que espera `ParseException` (medido en el contenedor).
  - `[medium]` `[patch]` (Intent) ningún test ata el gate real a compose.dev.yaml — mismo grupo que el primero; el test de config.mk enrojece si `dev` pierde el overlay.
  - `[false]` `[reject]` (Intent) caso prod "sin command en compose.prod.yaml" sin fixture — `aServiceOnlyTheOverlayDeclaresCounts` fija exactamente esa forma (messenger_worker heredado con scheduler_alpha junto al scheduler_worker del overlay), y se midió plantándolo en el árbol real.
  - `[false]` `[reject]` (Intent) merge aplicado también a prod, más allá del intent — el intent pide "resolver los ficheros como Compose los mergea"; prod es la otra pila que ese mecanismo lee.
  - `[false]` `[reject]` (Intent) réplicas por fichero y consumo por pila difieren — decisión explícita y documentada en el docblock de REPLICA_SCOPE (lo más estricto para el pin).

## Design Notes

Clave por entorno y no por fichero porque el invariante (quién consume y cuántas veces) es de la pila desplegada, no de un fichero: `compose.yaml` solo nunca se ejecuta. El eje de réplicas sigue por fichero a propósito — ahí lo más estricto es exigir que ningún fichero contradiga el pin.

## Verification

**Commands:**
- `make php.lint.schedule-consumption` -- exit 0
- `make php.stan` -- exit 0
- `make php.lint.gate-placement` -- exit 0
- `make php.quality` -- exit 0

## Auto Run Result

Status: done

**Resumen:** `php.lint.schedule-consumption` lee ahora cada entorno como la **pila** que Compose ejecuta — dev = `compose.yaml + compose.dev.yaml`, prod/staging = `compose.yaml + compose.prod.yaml` — con el `command` mergeado como Compose (medido con Docker Compose v5.5.1: el último fichero que declara `command` gana la lista entera, `command: ~` la elimina, `command: []` la vacía). Un `command:` en `compose.dev.yaml` ya lo ve el gate (DW-10), y de paso también el caso simétrico de prod: si `compose.prod.yaml` dejara de redefinir el command de `messenger_worker`, el pool escalable heredaría los `scheduler_*` y el gate enrojece. Las pilas están atadas a `make/config.mk` por un test.

**Ficheros:**
- `api/tests/Support/ComposeStackCommands.php` — nuevo: merge de `command` por pila.
- `api/tests/Support/ScheduleConsumption.php` — `COMPOSE_FILES` → `BASE_COMPOSE_FILE` + `COMPOSE_STACKS`; `SCHEDULER_CONSUMER` por entorno; lectores variádicos sobre pila.
- `api/tests/Unit/Gate/ScheduleConsumptionGateTest.php` — tres direcciones por pila; test nuevo que ata `COMPOSE_STACKS` a `make/config.mk` y los conjuntos de claves.
- `api/tests/Unit/Gate/ScheduleStackMergeRulesGateTest.php` — nuevo: 9 casos de falsificación del merge.
- `api/tests/Unit/Gate/ScheduleConsumptionRulesGateTest.php` — firma nueva de `unbackedSchedulerTransportsIn`.
- `api/tests/Unit/Gate/Fixture/ScheduleConsumption/compose.overlay-*.yaml` — 8 fixtures base+overlay.
- `make/php-quality.mk`, `api/.artifact-gate-placement` — cableado y clasificación de la clase nueva.
- `api/CLAUDE.md`, `docs/architecture-api.md`, `docs/claude-code-quickref.md` — prosa del gate actualizada.

**Revisión:** 23 hallazgos. Parches aplicados: 6 grupos (1 medium — sincronía con config.mk, reportado por tres capas; 5 low — tag `!reset`, `command: []`, fixtures reproducibles, igualdad de claves, cabeceras de fixture, quickref). Diferidos: 2 (claves de Compose no modeladas — include/extends/profiles/entrypoint —, preexistente, medium; bullet del CLAUDE.md raíz, low). Rechazados: el resto, con su razón en el Review Triage Log.

**Seguimiento recomendado:** false — parcheados por veredicto: high 0, medium 1, low 5.

**Verificación:** `make php.lint.schedule-consumption` exit 0 (5 lotes: 13, 8, 7, 9, 3 tests); `make php.quality` exit 0 (incluye `php.stan`, `php.lint.gate-placement`, PHPMD, deptrac), ambos tras los parches. Falsificaciones medidas por el implementador y restauradas byte a byte (`cmp`): `command:` plantado en `compose.dev.yaml` sin un transporte → rojo nombrando la pila dev; `command` de `messenger_worker` quitado de `compose.prod.yaml` → rojo por consumo en el servicio equivocado; `COMPOSE_STACKS['dev']` reducido a la base → rojo del test de config.mk; `isset` en lugar de `array_key_exists` → rojo sólo del caso `command: ~`.

**Riesgos residuales:** el merge sólo modela `command` (diferido arriba); la lectura de `make/config.mk` es textual y falla ruidosamente si cambia la estructura de ramas.
