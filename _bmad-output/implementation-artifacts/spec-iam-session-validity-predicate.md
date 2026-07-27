---
title: 'Iam · Session — `Session::isActive()` como definición normativa del predicado de validez temporal'
type: 'refactor'
created: '2026-07-27'
status: 'done'
baseline_commit: '234e35a5'
review_loop_iteration: 0
context:
  - '{project-root}/docs/rules/testing.md'
  - '{project-root}/docs/adr/identity-invitation-lifecycle.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** La regla «esta sesión es admisible ahora» está escrita **tres veces con tres contenidos
distintos**. El puerto `SessionRepository.php:26-29` **publica en su contrato** que `findActiveById()`
devuelve la sesión *«only when it is admissible now — `status = ACTIVE AND expires_at > now`»*, y el doble
`InMemorySessionRepository.php:61-79` **debilita esa postcondición** filtrando solo por `status` (LSP roto).
Consecuencia tangible y medida: `RevokeSession.php:16-17` promete *«a session that is already revoked or
expired resolves to `null` and the call is a no-op»* y esa garantía **no está probada en ningún nivel ni
puede escribirse hoy** — contra el doble actual la sesión caducada se encuentra, se revoca y emite un
`SessionRevoked` espurio. El mismo doble tiene además un `save()` (`:54-58`) que **no indexa en `$byId`**,
así que `save()` + `findActiveById()` devuelve `null` donde producción devuelve la sesión.

**Approach:** `Session::isActive($now)` pasa a ser la **definición normativa** y el doble **delega** en ella;
`findActiveById()` se conserva como **punto de imposición** del contrato. Cero cambios en producción.

## Boundaries & Constraints

**Always:**
- El doble **delega** en `Session::isActive(SystemClock::now())`. Reimplementar el predicado allí crearía una
  **cuarta** redacción y anularía toda la tesis.
- En `findByUserId()` el `$now` se iza **fuera** del `array_filter`: el DQL vincula un único `:now` para toda
  la consulta, y una lectura por fila rompería la equivalencia en el borde.
- Sin cambios de comportamiento en producción: `DoctrineSessionRepository` y `Session` solo admiten
  ediciones de documentación.
- `findActiveById()` **conserva su nombre**: no es un smell, es lo que hace la regla imposible de olvidar.

**Ask First:**
- Cambiar la forma del puerto (`findActiveById` → `findById`). **Rechazado** — ver Design Notes.
- Tocar el predicado de `bulkRevokeActive()` (`:122-144`), que omite la expiración **a propósito**.
- Cualquier cambio en `SessionAdmissionGate`, controllers o casos de uso.

**Never:**
- Deduplicar las 2 apariciones del predicado DQL. `USER_ID_FILTER` (`:35`) se usa **tres** veces: el propio
  fichero ya aplicó la Regla de Tres, y un helper junto a `bulkRevokeActive()` invita a «arreglar» ese UPDATE
  y dejar filas ACTIVE caducadas en ACTIVE para siempre.
- Patrón Specification genérico, tocar `Shared/Search/**`, u objetos de dominio que emitan DQL.
- Inyectar `Clock` en el constructor del doble: es variádico (`Session ...$preset`), el parámetro iría primero
  y tocaría **39 call sites**.

## I/O & Edge-Case Matrix

Comportamiento de `InMemorySessionRepository` tras el cambio (debe igualar al adaptador Doctrine):

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|---------------|----------------------------|----------------|
| Sesión admisible | ACTIVE, `expiresAt` futuro | `findActiveById` devuelve la `Session`; aparece en `findByUserId` | N/A |
| Sesión caducada | ACTIVE, `expiresAt` pasado | `null`; ausente de `findByUserId` | N/A |
| Borde exacto | ACTIVE, `expiresAt == now` | `null` (PHP `<=` ≡ DQL `>` estricto) | N/A |
| Sesión revocada | REVOKED, `expiresAt` futuro | `null`; ausente de `findByUserId` | N/A |
| Round-trip de `save` | `save($s)` y luego leer | `findActiveById` devuelve `$s` si es admisible | N/A |
| Revoke de caducada | ACTIVE caducada → `RevokeSession` | no-op: `saved` vacío, sin `SessionRevoked` | N/A |

</frozen-after-approval>

## Code Map

- `api/tests/Unit/Iam/Session/Application/InMemorySessionRepository.php` -- el doble a corregir: predicado (`:61-79`), `save()` sin indexar (`:54-58`), docblock que declara la desviación (`:14-18`)
- `api/src/Iam/Session/Domain/Entity/Session.php:111-119` -- `isExpired()` / `isActive()`: la definición normativa. **No se modifica**
- `api/src/Iam/Session/Domain/Repository/SessionRepository.php:26-29` -- el contrato publicado que el doble debe satisfacer
- `api/src/Iam/Session/Application/RevokeSession.php:16-17,30` -- la garantía hoy inescribible
- `api/tests/Unit/Iam/Session/Application/RevokeSessionTest.php` -- destino del test de no-op sobre caducada
- `api/tests/Functional/Iam/Session/DoctrineSessionRepositoryTest.php:78-101,170-184` -- ya cubre caducidad en ambas lecturas; falta el borde exacto. Helper `activeSession()` acepta offset de expiry
- `api/tests/Unit/Iam/Session/Application/PurgeUserSessionsTest.php:57` y `api/tests/Unit/Iam/Identity/Application/FulfilIdentityErasureTest.php:365` -- fixtures con `expiresAt` ya en pasado
- `api/src/Shared/Clock/Domain/SystemClock.php:15-18` -- reloj ambiental; documenta que es «only for the layers DI cannot reach»
- `docs/adr/identity-invitation-lifecycle.md:55-59` -- D8, donde se registra el rechazo de la forma pura del puerto

## Tasks & Acceptance

**Execution:**
- [x] `api/tests/Unit/Iam/Session/Application/InMemorySessionRepository.php` -- `findActiveById()` y `findByUserId()` delegan en `Session::isActive()` con `SystemClock::now()` izado; `save()` indexa en `$byId`; reescribir el docblock `:14-18` (hoy afirma que filtra solo por estado) y nombrar allí la desviación consciente respecto a la política de `SystemClock` -- cierra el LSP y el fallo de round-trip
- [x] `api/tests/Unit/Iam/Session/Application/RevokeSessionTest.php` -- test: revocar una sesión ACTIVE caducada es no-op (`saved` vacío, sin evento) -- prueba la garantía de `RevokeSession.php:16-17`
- [x] `api/tests/Functional/Iam/Session/DoctrineSessionRepositoryTest.php` -- caso de borde `expiresAt == now` a resolución de segundo entero -- única deriva que la cobertura actual no ve; es el flip `>` → `>=` que ya ocurrió entre `PasswordResetToken::isExpiredAt()` y `SingleUseToken::isExpired()`
- [x] `api/tests/Unit/Iam/Session/Application/PurgeUserSessionsTest.php` y `api/tests/Unit/Iam/Identity/Application/FulfilIdentityErasureTest.php` -- fijar los `expiresAt` a una fecha **inequívocamente pasada** (2020) y decir por qué -- quita la dependencia del reloj de pared **conservando** que la purga alcanza filas caducadas
- [x] `api/tests/Unit/Iam/Session/Application/InMemorySessionRepositoryContractTest.php` -- cubrir el doble: round-trip `save`→lectura, `findByUserId` con las cuatro clases de sesión, y el borde `expiresAt == now` con reloj congelado -- las tres rutas que el cambio toca y ningún test ejercitaba
- [x] `docs/adr/identity-invitation-lifecycle.md` -- en D8, registrar el **rechazo** de la forma pura del puerto con su argumento -- un diferido se pudre; un rechazo argumentado cierra el tema

**Acceptance Criteria:**
- Given el doble corregido, when se ejecuta la suite unitaria completa, then **ningún test existente falla**
  (medido: de 39 sitios de construcción solo 4 presiembran sesión, todas con expiry futuro).
- Given cualquier entrada, when se compara el doble con `DoctrineSessionRepository` contra Postgres real,
  then ambos aceptan y rechazan el mismo conjunto de sesiones.
- Given el diff completo, when se revisa `api/src/`, then **solo hay cambios de documentación** — ninguna
  línea ejecutable de producción se modifica.
- Given la pasada adversarial sobre el gate de autenticación, when se declara hecho, then consta **dónde**
  quedó registrada (descripción del PR).

## Spec Change Log

- **Iteración 1 — el pase adversarial refutó la instrucción de las fixtures.** Hallazgo: subir los `expiresAt`
  podridos «a futuro lejano» elimina cobertura en vez de sanearla — `deleteAllForUser` es un borrado duro que
  **debe** alcanzar filas caducadas (GDPR), y una fixture expirada era su única cobertura unitaria. Enmienda:
  fijarlas a una fecha inequívocamente pasada (2020) con el porqué escrito, lo que mata la dependencia del
  reloj de pared **y** conserva el caso. Estado malo evitado: una PR que dice sanear fixtures y en realidad
  debilita la ruta de borrado GDPR. **KEEP:** la delegación en `Session::isActive()`, el izado de `$now` fuera
  del `array_filter`, y conservar `findActiveById` como punto de imposición — los tres sobrevivieron la
  revisión sin objeción y son la tesis del cambio.
- **Iteración 1 — la cláusula del ADR contenía datos falsos.** Hallazgo: afirmaba «only one of its three read
  consumers calls `verify()`»; en realidad `InvitationRepository::findById()` tiene **dos** consumidores y
  ninguno verifica, mientras el que verifica usa **otro método** (`findByIdForUpdate()`), y el puerto documenta
  el split selector-verificador como decisión deliberada — lo contrario de «accidental safety». Enmienda:
  reescrita para que el argumento descanse en por qué los mecanismos de `Invitation` (un secreto en la mano, un
  segundo método de lectura) **no existen** en `Session`. Estado malo evitado: un ADR durable que sostiene una
  decisión sobre el gate de autenticación con evidencia inventada.

## Design Notes

**Formulación acordada:** `Session::isActive()` es la **definición** (normativa, unit-testeable, sin BD) ·
`findActiveById()` es el **punto de imposición** · cada adaptador la satisface con los medios de su
tecnología — Doctrine la empuja a SQL como optimización, el in-memory delega en el agregado.

**Por qué se rechaza la forma pura del puerto (`findById`).** Fue la recomendación inicial del arquitecto y
la de un AI externo, por coherencia con `InvitationRepository`. Cae por dos motivos verificados:

1. **Regresión fail-open sobre el TCB.** Hoy el predicado en SQL hace la regla imposible de omitir. Con
   `findById()` puro, los cuatro llamantes (`SessionAdmissionGate.php:73`, `RevokeSession.php:30`,
   `MySessionsController.php:41`, `RevokeOtherSessionsController.php:39`) deben acordarse de invocar
   `isActive()`; un quinto que lo olvide obtiene un **bypass de autenticación silencioso** que compila y pasa
   PHPStan, deptrac y los tests. Se cambia *fail-safe por construcción* por *fail-safe por convención*, y el
   eje temporal es el **único sin defensa en profundidad** en el agregado: `revoke()` guarda `status`
   (`Session.php:96`) pero nunca la caducidad.
2. **El precedente `Invitation` no sostiene el argumento: lo desmiente.** De sus tres consumidores de
   lectura, **solo uno** llama a `verify()` (`AcceptInvitation.php:66`); `ResendInvitation.php:51` y
   `RevokeInvitation.php:34` usan `findById()` a pelo. Se salva porque `verify()` exige un secreto en la mano
   y los otros dos son operaciones CLI que nunca lo reciben — **seguridad accidental, no diseñada**. Adoptarlo
   sería propagar un patrón frágil creyendo que se adopta uno probado.

`findActiveById` es, de hecho, la codificación *must-use* más barata posible: obliga a resolver la
admisibilidad sin inventar un tipo nuevo. Es la «justified flexibility» de `CLAUDE.md`, no un smell.

## Verification

**Commands:**
- `make php.unit c='--filter Session'` -- expected: verde, sin regresiones
- `make php.unit` -- expected: suite unitaria completa verde
- `make php.stan` -- expected: 0 errores (nivel max; `tests/` está analizado)
- `make php.behat` -- expected: verde; el gate de sesión es contrato observable
- `make php.quality` -- expected: verde (incluye deptrac, bounded-context, error-contract, PHPMD, cs-fixer)

**Manual checks:**
- `git diff --stat api/src/` -- expected: **vacío**; `api/src/` no se toca en absoluto.
- Confirmar que el doble **llama** a `Session::isActive()` y no reescribe la comparación.

## Suggested Review Order

**El predicado y quién es su autoridad**

- La delegación: una línea, y es toda la tesis del cambio.
  [`InMemorySessionRepository.php:82`](../../api/tests/Unit/Iam/Session/Application/InMemorySessionRepository.php#L82)

- `$now` izado fuera del filtro: un solo instante por consulta, como el `:now` del adaptador.
  [`InMemorySessionRepository.php:88`](../../api/tests/Unit/Iam/Session/Application/InMemorySessionRepository.php#L88)

- El docblock acota qué se cierra y qué no: quedan dos redacciones, no una.
  [`InMemorySessionRepository.php:18`](../../api/tests/Unit/Iam/Session/Application/InMemorySessionRepository.php#L18)

- La definición normativa, sin tocar: gana consumidor, no cambia.
  [`Session.php:116`](../../api/src/Iam/Session/Domain/Entity/Session.php#L116)

**La decisión registrada**

- Se rechaza el puerto puro: el nombre del método es lo que impide olvidar la regla.
  [`identity-invitation-lifecycle.md:59`](../../docs/adr/identity-invitation-lifecycle.md#L59)

**Fidelidad del doble como implementación del puerto**

- `save()` ahora indexa: escribir y leer después deja de contradecirse.
  [`InMemorySessionRepository.php:70`](../../api/tests/Unit/Iam/Session/Application/InMemorySessionRepository.php#L70)

- `index()` centraliza el alta, compartido con el constructor.
  [`InMemorySessionRepository.php:130`](../../api/tests/Unit/Iam/Session/Application/InMemorySessionRepository.php#L130)

**Cobertura**

- La garantía que el docblock prometía y nadie probaba; falla si se revierte la delegación.
  [`RevokeSessionTest.php:51`](../../api/tests/Unit/Iam/Session/Application/RevokeSessionTest.php#L51)

- Las cuatro clases de sesión por `findByUserId`, que no tenía ni un test.
  [`InMemorySessionRepositoryContractTest.php:39`](../../api/tests/Unit/Iam/Session/Application/InMemorySessionRepositoryContractTest.php#L39)

- Round-trip escribir→leer, la ruta que `save()` rompía en silencio.
  [`InMemorySessionRepositoryContractTest.php:28`](../../api/tests/Unit/Iam/Session/Application/InMemorySessionRepositoryContractTest.php#L28)

- El borde exacto en el doble, con reloj congelado.
  [`InMemorySessionRepositoryContractTest.php:60`](../../api/tests/Unit/Iam/Session/Application/InMemorySessionRepositoryContractTest.php#L60)

- El mismo borde contra Postgres real, a resolución de segundo por `TIMESTAMP(0)`.
  [`DoctrineSessionRepositoryTest.php:89`](../../api/tests/Functional/Iam/Session/DoctrineSessionRepositoryTest.php#L89)

- Fixtures deliberadamente caducadas: la purga GDPR debe alcanzarlas.
  [`PurgeUserSessionsTest.php:57`](../../api/tests/Unit/Iam/Session/Application/PurgeUserSessionsTest.php#L57)

- Misma razón en la ruta de borrado de identidad.
  [`FulfilIdentityErasureTest.php:365`](../../api/tests/Unit/Iam/Identity/Application/FulfilIdentityErasureTest.php#L365)
