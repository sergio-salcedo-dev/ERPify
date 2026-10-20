---
title: 'DW-46 — CHECK de actor_type/actor_id en audit_log'
type: 'bugfix'
created: '2026-09-29'
status: 'done'
baseline_revision: '6ff642c0bbb419b1b962d1f92294b9a53c6627dc'
review_loop_iteration: 0
followup_review_recommended: false
context:
  - '{project-root}/docs/adr/audit-activity-log.md'
warnings: []
deferred:
  - summary: >-
      audit_log.level no tiene CHECK; una fila escrita por SQL crudo con un level desconocido o mal capitalizado no la casa ninguna pasada del pruner.
    evidence: |-
      DbalAuditLogPruner borra por WHERE level = :level por cada AuditLevel; un token fuera del enum sobrevive para siempre. Preexistente y fuera del intent de DW-46 (solo actor_type/actor_id).
    location: >-
      api/src/Shared/Audit/Infrastructure/Persistence/AuditLogSchemaListener.php
    severity: low
---

<intent-contract>

## Intent

**Problem:** `audit_log.actor_type` es un `VARCHAR(16)` libre y `actor_id` un `UUID` nullable sin relación entre ambos, así que SQL crudo (DBAL, fixtures, Behat) puede escribir una fila `user` con `actor_id` NULL — que ninguna de las dos pasadas de borrado casa, dejando los metadatos de una persona vivos — o un `actor_type` fuera de `ActorType`.

**Approach:** Una migración nueva añade dos CHECK con nombre: `audit_log_actor_id_presence_check` = `CHECK ((actor_type IN ('anonymous','system')) = (actor_id IS NULL))` y `audit_log_actor_type_check` = `CHECK (actor_type IN ('anonymous','system','api_key','user'))`. El listener de esquema los declara (nombres como constantes + docblock), y un test funcional prueba contra Postgres que las filas ilegales se rechazan, las legales pasan y el conjunto de tokens del CHECK es exactamente `ActorType::cases()`.

## Boundaries & Constraints

**Always:** migración nueva y reversible (`down()` hace `DROP CONSTRAINT IF EXISTS` de ambas); nombres de constraint explícitos; `make db.diff` sigue sin generar nada tras migrar; los tests funcionales escriben dentro de una transacción que se revierte; todo INSERT crudo existente sobre `audit_log` sigue siendo legal.

**Never:** editar `Version20260623164321.php` ni ninguna migración mergeada; enum nativo de Postgres; que la migración importe clases de `src` (una migración es inmutable, el código no); tocar el ledger `deferred-work.md`.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| user con id | `('user', <uuid>)` | se inserta | — |
| api_key con id | `('api_key', <uuid>)` | se inserta | — |
| anonymous/system sin id | `('anonymous'|'system', NULL)` | se inserta | — |
| user/api_key sin id | `('user'|'api_key', NULL)` | rechazada | SQLSTATE 23514, `audit_log_actor_id_presence_check` |
| anonymous/system con id | `('anonymous'|'system', <uuid>)` | rechazada | SQLSTATE 23514, `audit_log_actor_id_presence_check` |
| token desconocido | `('robot', <uuid>)` o `('robot', NULL)` | rechazada | SQLSTATE 23514, `audit_log_actor_type_check` |
| mayúsculas | `('USER', <uuid>)` | rechazada | SQLSTATE 23514, `audit_log_actor_type_check` |

</intent-contract>

## Code Map

- `api/migrations/2026/Version20260623164321.php` -- crea `audit_log` sin CHECK. Solo lectura (mergeada).
- `api/src/Shared/Audit/Infrastructure/Persistence/AuditLogSchemaListener.php` -- fuente de verdad de la forma de la tabla; su docblock afirma hoy "there is no `CHECK`" (L19–22), que pasa a ser falso.
- `api/src/Shared/Audit/Domain/ActorType.php` -- `anonymous|system|api_key|user`, el conjunto cerrado que el CHECK de tokens refleja.
- `api/src/Shared/Audit/Domain/ActorContext.php` -- ya hace irrepresentable la combinación ilegal en PHP (`anonymous()`/`system()` con null, `forUser`/`forApiKey` con UUID validado).
- `api/src/Shared/Audit/Infrastructure/Persistence/DbalAuditActorAnonymiser.php:74` -- el borrado remintea `actor_id` a un pseudónimo UUID (nunca NULL) y deja `actor_type = user`: compatible con el CHECK.
- `api/src/Shared/Audit/Infrastructure/Persistence/DbalAuditResourceAnonymiser.php:104` -- no toca `actor_*`.
- INSERT crudos medidos, todos legales: `api/features/backoffice/audit/{access_control,self_audit,timeline}.feature`, `api/features/backoffice/users/erase.feature` (L19, L131, L149), `api/tests/Functional/Iam/Identity/Infrastructure/Controller/UserEraseFunctionalTest.php:192`, `api/tests/Functional/Shared/Audit/SubjectErasureReconcilerFunctionalTest.php:58`, `api/tests/Functional/Shared/Privacy/ReconcilerPastTheParameterCeilingFunctionalTest.php:54`. BD dev: 16 filas, todas `user` con id.
- `vendor/doctrine/dbal/src/Platforms/AbstractPlatform.php:1613` -- DBAL 4.4 solo emite CHECK `min`/`max` por columna en `CREATE TABLE` y el schema manager no introspecta CHECK: por eso el espejo no puede vivir en el modelo `Table` y `db.diff` no los ve (ni para crearlos ni para borrarlos).
- `vendor/doctrine/dbal/src/Driver/API/PostgreSQL/ExceptionConverter.php` -- 23514 no tiene excepción propia; llega como `Doctrine\DBAL\Exception\DriverException` con `getSQLState()`.
- `api/tests/Functional/Shared/Audit/AuditLogFieldWidthContractTest.php` -- patrón a imitar: `KernelTestCase`, Postgres como autoridad vía catálogo.
- `docs/adr/audit-activity-log.md` -- D7 (tabla actor_type/actor_id) y esbozo de esquema (~L700–725).

## Tasks & Acceptance

**Execution:**
- `api/migrations/2026/VersionYYYYMMDDHHMMSS.php` (generado con `make sf c='doctrine:migrations:generate'`) -- `ALTER TABLE audit_log ADD CONSTRAINT audit_log_actor_type_check CHECK (...), ADD CONSTRAINT audit_log_actor_id_presence_check CHECK (...)`; `down()` con `DROP CONSTRAINT IF EXISTS`; docblock con el porqué y el coste de bloqueo -- cierra DW-46 en la BD.
- `api/src/Shared/Audit/Infrastructure/Persistence/AuditLogSchemaListener.php` -- constantes públicas con los dos nombres de constraint; docblock corregido: los CHECK existen, viven en la migración porque DBAL no los modela ni introspecta, y el test funcional es su guardián -- espejo honesto y `db.diff` limpio.
- `api/tests/Functional/Shared/Audit/AuditLogActorCheckConstraintFunctionalTest.php` -- data providers para la matriz I/O (legales se insertan, ilegales dan 23514 con el nombre de constraint esperado), más un test que lee `pg_get_constraintdef` de `audit_log_actor_type_check` y compara el conjunto de tokens con `ActorType::cases()` -- prueba en la superficie Postgres.
- `docs/adr/audit-activity-log.md` -- añadir en D7 / esbozo de esquema que ambas reglas las impone también Postgres con esos dos CHECK.

**Acceptance Criteria:**
- Given la BD de test migrada, when se inserta una fila de la matriz I/O, then Postgres la acepta o la rechaza con 23514 y el constraint indicado.
- Given un caso nuevo en `ActorType` sin migración, when corre el test funcional, then falla nombrando la deriva de tokens.
- Given el árbol migrado, when se ejecuta `make db.diff`/validación de esquema, then no se genera ningún cambio.
- Given la suite Behat y funcional existente, when se ejecuta, then ningún INSERT crudo viola los CHECK.

## Spec Change Log

- 2026-09-29 — fila `('robot', NULL)` de la matriz I/O: la rechaza `audit_log_actor_id_presence_check`, no `audit_log_actor_type_check`. Viola ambos CHECK y Postgres los evalúa en orden alfabético por nombre (documentado en `CREATE TABLE`); `…_actor_id_…` < `…_actor_type_…`. Medido contra la BD de test. El test afirma el orden medido y `('robot', <uuid>)` aísla el CHECK de tokens. Las expresiones y nombres del contrato no cambian.

## Review Triage Log

### 2026-09-29 — Review pass
- verdicts: 14 findings — high 0, medium 1, low 5, false 8, maybe-false 0
- findings:
  - `[low]` `[defer]` (Blind) `level` sin CHECK: una fila cruda con `level` desconocido escapa al pruner — real pero preexistente y fuera del intent (solo actor); diferido en frontmatter.
  - `[low]` `[reject]` (Blind) sin preflight de filas ilegales existentes — el `ADD CONSTRAINT` falla ruidoso con 23514 nombrando la constraint y la relación, que es el comportamiento correcto; BD dev medida limpia (16 filas `user` con id). El preflight añade ramas.
  - `[low]` `[patch]` (Blind) `ActorType` no avisa de que Postgres cierra también el conjunto — añadida una frase al docblock nombrando ambas constraints.
  - `[false]` `[reject]` (Blind) `#[CoversClass(AuditLogSchemaListener)]` acredita cobertura no ganada — PHPUnit solo acredita líneas ejecutadas; `postGenerateSchema()` no se ejecuta, así que no hay crédito falso; las constantes son la referencia real al listener.
  - `[false]` `[reject]` (Blind) constantes públicas solo usadas por el test, desviación no declarada — el docblock del listener declara explícitamente que las CHECK viven solo en la migración y por qué (DBAL 4.4 no las modela, `AbstractPlatform.php:1613`).
  - `[false]` `[reject]` (Blind) ningún test prueba un `UPDATE` ilegal — Postgres evalúa todo CHECK en INSERT y UPDATE; no hay resultado malo en el código citado.
  - `[medium]` `[patch]` (Blind + Edge) la definición del CHECK de presencia no se relee, y el mensaje de deriva decía «si lleva id» al revés — un caso nuevo sin id añadido solo al CHECK de tokens se rechazaría en runtime con todo verde. Corregido el mensaje («if it carries no id») y añadido `thePresenceCheckNamesExactlyTheIdLessActorTypes`, que compara los tokens de `pg_get_constraintdef` con `ActorContext::anonymous()/system()`; falsificado con un token `webhook` plantado (rojo) y restaurado.
  - `[low]` `[reject]` (Blind) el caso `robot` sin id depende del orden alfabético de CHECK — orden documentado por Postgres y anotado en el provider; un renombrado falla ruidoso en el test.
  - `[false]` `[reject]` (Blind) entrada DW-46 del ledger sin cerrar — el intent prohíbe editar el ledger; lo registra el orquestador.
  - `[false]` `[reject]` (Blind) racional repetido en tres sitios — convención del repo: la migración (inmutable) lleva su porqué (p. ej. `Version20260828134621`), el listener es el puntero vivo y el ADR el dueño; no se nombra deriva concreta.
  - `[low]` `[reject]` (Blind) `NOT VALID` + `VALIDATE` descrito pero no entregado — tabla pre-producción; exige migración no transaccional, más que una corrección directa.
  - `[low]` `[reject]` (Edge) migración aborta sin diagnóstico ante filas ilegales previas — mismo hallazgo que el preflight de Blind; mismo motivo.
  - `[false]` `[reject]` (Intent) el espejo en el listener es nominal, no estructural, y nada ejecuta `db.diff` — espejo estructural imposible en DBAL 4.4; medido `doctrine:schema:validate` «in sync» y `migrations:status` sin pendientes tras migrar.
  - `[false]` `[reject]` (Intent) la SQL de fixtures/Behat no se ejercita — ejercitada: Behat `features/backoffice/audit` + `users/erase.feature` 33/33 y suite PHPUnit completa verdes con las constraints aplicadas.

## Design Notes

Una sola sentencia `ALTER TABLE` con los dos `ADD CONSTRAINT`: Postgres valida ambos en un único recorrido bajo `ACCESS EXCLUSIVE`. Se descarta `NOT VALID` + `VALIDATE` en migración no transaccional: el proyecto está pre-producción y la tabla es pequeña; el coste queda escrito en el docblock para que quien la aplique sobre un `audit_log` grande lo lea antes.

Igualdad booleana `(actor_type IN ('anonymous','system')) = (actor_id IS NULL)`: con `actor_type NOT NULL` ambos lados son siempre `TRUE/FALSE`, así que el CHECK nunca se evalúa a NULL (que Postgres aceptaría).

## Verification

**Commands:**
- `make db.migrate && make db.test.prepare` -- expected: migración aplicada en dev y test.
- `make sf c='doctrine:schema:validate'` / `make php.lint.doctrine` -- expected: esquema en sync.
- `make php.unit c='--filter AuditLogActorCheckConstraint'` -- expected: verde.
- `make php.behat c='features/backoffice/audit features/backoffice/users/erase.feature'` -- expected: verde.
- `make php.stan` y `make php.quality` -- expected: exit 0.

## Auto Run Result

Status: done

**Resumen:** Postgres impone ahora el discriminante de actor de `audit_log` con dos CHECK con nombre (`audit_log_actor_type_check`, `audit_log_actor_id_presence_check`) añadidos en una migración nueva; un test funcional prueba contra Postgres las 11 combinaciones de la matriz y relee ambas definiciones contra el dominio.

**Ficheros:**
- `api/migrations/2026/Version20260929102251.php` — añade las dos CHECK; `down()` las elimina.
- `api/src/Shared/Audit/Infrastructure/Persistence/AuditLogSchemaListener.php` — constantes con los nombres y docblock corregido (DBAL no modela ni introspecta CHECK).
- `api/src/Shared/Audit/Domain/ActorType.php` — aviso de que añadir un caso exige migración.
- `api/tests/Functional/Shared/Audit/AuditLogActorCheckConstraintFunctionalTest.php` — 13 tests: legales aceptadas, ilegales 23514 con constraint nombrada, deriva de tokens y de tipos sin id.
- `docs/adr/audit-activity-log.md` — D7 y esbozo de esquema nombran las dos CHECK.

**Revisión:** 14 hallazgos — 2 parches (1 medium, 1 low), 1 diferido (`level` sin CHECK, preexistente), 11 rechazados con motivo en el triage log. Recomendación de follow-up: `false` (0 high, 1 medium parcheado).

**Verificación:** `make php.unit c='--filter AuditLogActorCheckConstraint'` 13/13; suite PHPUnit completa verde (4120, antes de los parches, que solo tocan el test y un docblock); `make php.behat` sobre audit + erase 33/33; `doctrine:schema:validate` in sync; migración aplicada en dev y test (down/up probado en dev); `make php.quality` exit 0; ambos tests de definición falsificados en rojo y restaurados.

**Riesgos residuales:** una CHECK borrada fuera de banda no la ve `db.diff`, solo el test funcional; la migración toma `ACCESS EXCLUSIVE` durante un recorrido completo (aceptable pre-producción, anotado en su docblock).
