---
stepsCompleted: ['step-02-design-epics']
inputDocuments:
  - docs/adr/regulatory-audit-trail.md
  - docs/adr/audit-activity-log.md
  - docs/architecture-api.md
  - docs/rules/database.md
  - docs/rules/security.md
  - PRODUCTION_SECURITY_CHECKLIST.md
  - api/src/Shared/Audit/Domain/AuditPolicy.php
  - api/src/Shared/Audit/Domain/ActorContext.php
  - api/src/Shared/Audit/Application/AuditLogEntry.php
  - api/src/Shared/Audit/Application/AuditLogPruner.php
  - api/src/Shared/Audit/Infrastructure/SealedAuditEntryFactory.php
  - api/src/Backoffice/Audit/Application/AuditTimelineSearcher.php
scope: >-
  Trail de auditoría REGULATORIO ISO 27001 (docs/adr/regulatory-audit-trail.md), que EXTIENDE el eje
  operativo/de actor ya enviado (audit-activity-log.md, épicas 1-4, PRs #369/#370/#374/#375/#377). Añade
  captura de ESCRITURAS (CDC Doctrine onFlush + diff campo a campo + acción semántica) y completa las
  LECTURAS (extractor de recurso). Troceado: E1 sin prerequisitos (pre-auth), E2 disparada por el primer
  campo PII auditado (`BankAccount`, sin agregado Persona — ADR D10/D13), E3 condicionada a auth/RBAC. NO es un único PR.
---

# ERPify — Trail de auditoría regulatorio ISO 27001 — Desglose de épicas

## Overview

Este documento desglosa el trail de auditoría **regulatorio** definido en
[`docs/adr/regulatory-audit-trail.md`](../../docs/adr/regulatory-audit-trail.md). **Extiende** —no
revoca— el eje operativo/de actor de [`docs/adr/audit-activity-log.md`](../../docs/adr/audit-activity-log.md),
cuyo backbone (`AuditLogger`, `audit_log` append-only, `ActorContext`, `AuditPolicy`, retención/GDPR,
read model + UI #377) **ya está implementado y vivo**.

El hueco que cubre: hoy `audit_log` solo registra **lecturas** (GET genérico vía `AuditPolicy` +
`AccessLogAuditListener`) y **seguridad** (`AccessDeniedAuditListener`). Las **escrituras**
(create/update/delete) viajan por el eje `DomainEvent → event_store` (verdad de negocio) y **no se
auditan** — por eso la UI #377 no muestra alta/edición/borrado de bancos. El trail regulatorio registra
*quién cambió qué campo, de qué valor a qué valor* (diff campo a campo «BBVA» → «BBVA S.A.»), con
integridad, acceso restringido y olvido de PII sin romper append-only.

**Troceado por dependencia (ADR D9), no diferido en bloque tras auth:**

- **E1 — sin prerequisitos (pre-auth):** captura de escrituras (diff + acción semántica), extractor de
  recurso en lecturas y suelo de retención. Auth-independiente y **sin retrabajo**: la costura de actor
  (`ActorContextFactory`) ya aísla auth; hasta entonces el actor sale `anonymous`/`system`.
- **E2 — disparada por el dato, no por un agregado:** crypto-shredding + keystore + clasificación PII por
  campo. Auditar `BankAccount` introduce el primer campo PII (`holderName`/`iban`) en `audit_log`, así que E2
  se construye **ahora**; **sin** agregado Persona — la identidad cripto es un `EncryptionScopeId` desacoplado
  del dominio (ADR D10/D13).
- **E3 — condicionada a auth/RBAC:** acceso restringido + auto-auditado, atribución real
  (`actor_id` real por request autenticada; la columna sigue nullable, D9 tier-1) y puesta en producción.
  Hoy NO existe firewall/voter.

E1 y E2 son independientes entre sí; ambas son independientes de E3 salvo que **E3 las vuelve
forense-significativas** (sin auth toda acción es `anonymous`/`system`: mecánicamente completa,
ISO-completa solo con atribución real).

## Requirements Inventory

### Functional Requirements

FR1: Captura de escrituras mediante **un listener Doctrine `onFlush`** (`Shared/Audit/Infrastructure`)
que lee `UnitOfWork::getEntityChangeSet()` para insert/update/delete y produce el diff campo a campo
(D1). Justified-flexibility: depende del ORM por diseño (CDC = infraestructura), legal en hexagonal.
FR2: **Reutilizar el changeset de Doctrine**, no derivar el diff por `DomainEvent` ni introducir un
bundle de terceros (Sonata/DataDogAuditBundle) — pelearía con `deptrac`/bounded-context/DTOs por vista (D1).
FR3: **Acción semántica** sobre el diff (`BANK_CREATED`/`BANK_UPDATED`/`BANK_DELETED`): la `action` es
cardinalidad-1 e indexable; el diff estructurado viaja en `metadata`. Reutiliza el contrato `action` del
eje hermano (D2).
FR4: **Reconciliación** `event_store` (negocio) ⊥ `audit_log`+diff (cumplimiento): una escritura produce
ambos; ninguno es fuente de verdad del otro; el trail **no** duplica el `event_store` (D3).
FR5: **Snapshot de la entidad en DELETE**: capturar el estado final *antes* de que la fila desaparezca,
para que el «antes» del diff no se pierda (D1, reto load-bearing).
FR6: **Actor-context ambiente sellado dentro de `onFlush`**: el flush tiene el changeset pero no el actor
HTTP — sellar `ActorContext` + `correlation_id` reutilizando la costura
`SealedAuditEntryFactory`/`ActorContextFactory` (D1, reto load-bearing).
FR7: **Auditar todas las lecturas de toda entidad + extractor de recurso**: cada fila de lectura registra
*qué* recurso se vio (hoy el GET genérico guarda `resource_type`/`resource_id` = null·null). El extractor
resuelve la identidad del recurso desde la ruta/controlador, manteniendo `AuditPolicy` libre de un
catálogo de rutas por módulo (D4).
FR8: **Retención con suelo**: una fila de cumplimiento debe conservarse **al menos** el mínimo legal antes
de ser podable. Suelo = **5 años**. `DbalAuditLogPruner`/`AuditRetentionPolicy` nunca borran por debajo
del suelo; el olvido dentro del suelo lo cubre el crypto-shredding (E2), no el borrado (D7).
FR9: **Crypto-shredding** del diff con PII: cifrado bajo una **DEK por `EncryptionScopeId`** (la fila
referencia el scope); el olvido **destruye la DEK** → ciphertext ilegible, fila/orden/integridad intactos (D6, D13).
FR10: **Keystore**: esquema envelope con **libsodium** (AEAD) y **DEKs en una tabla keystore de Postgres**
(DEK con CSPRNG, envuelta por una KEK custodiada fuera de la app, con su `kek_version`) (D6, D14).
FR11: **Clasificación PII por campo** (A.5.12): atributo `#[PersonalData]` propiedad del módulo dueño (no un
mapa central), que decide cifrado-vs-claro por columna del diff (D6, D12).
FR12: **Solo se cifran los campos `#[PersonalData]`** (`Bank` no tiene; `BankAccount` sí — `holderName`/`iban`);
el crypto-shredding **compone** con el erasure de actor del eje hermano (D4 remint `actor_id` / D4.1
`actor_erased`) sin reabrirlo — distintos loci de PII (D6, D11).
FR13: **Acceso al trail RBAC-restringido** (voter sobre las rutas de lectura de `Backoffice/Audit`) (D8).
FR14: **Auto-auditoría del acceso** (auditar-al-auditor, A.5.18/8.15): leer el trail emite una fila
`security` (D8).
FR15: **Atribución real + puesta en producción**: con auth, toda request autenticada produce un `actor_id`
real (UUID del actor) y la atribución del diff/lectura responde *qué usuario*; los eventos `system`/off-request
siguen persistiendo `actor_id = NULL` por diseño (la columna permanece nullable, conforme a D9 tier-1); solo
cambia `ActorContextFactory`; se levanta el gate de producción de la superficie de lectura (D8/D9).
FR16: **Render del diff en el read model + UI #377**: el timeline pasa a mostrar create/update/delete con
su diff campo a campo (hoy no muestra escrituras) (D2/D4).
FR17: **Mapeo de controles ISO 27001:2022 documentado** (A.8.15 logging/protección, A.8.17 clock sync,
A.5.12 clasificación, A.5.18/8.15 acceso restringido + auditar-al-auditor) en `docs/rules/security.md` y
`PRODUCTION_SECURITY_CHECKLIST.md`; ISO no fija años de retención, exige política justificada + integridad
(D5).

### NonFunctional Requirements

NFR1: **Durabilidad/atomicidad de la fila de escritura** — un registro de cambio de cumplimiento no es
best-effort: no debe existir cambio de negocio sin su fila de auditoría ni viceversa. Candidato principal
a confirmar en historia: inserción síncrona **enrolada en la misma transacción del flush** (atomicidad
nativa), análogo al invariante transaccional que D3 del eje hermano fija para `security`. **Decisión de
durabilidad/nivel a cerrar en E1** (ver Story 1.1) — no inventada aquí.
NFR2: **Cero retrabajo frente a auth**: la costura `ActorContextFactory` aísla la atribución; esquema,
bus, storage, retención y read model no cambian cuando entre auth (D9, NFR10 del eje hermano).
NFR3: **Integridad bajo olvido**: el crypto-shredding preserva append-only — el olvido destruye solo la
clave; la fila, su orden y su prueba de integridad permanecen (D6).
NFR4: **Reloj sincronizado** (A.8.17): `occurred_on` sellado desde el `Clock` en `SealedAuditEntryFactory`.
NFR5: **Sin duplicar la verdad**: `event_store` y `audit_log`+diff responden preguntas distintas y llevan
garantías distintas; el trail no reproyecta el negocio (D3).
NFR6: **Nunca PII en claro en reposo**: el diff de catálogos no es PII; el de personas va cifrado; ningún
camino deja PII de persona sin cifrar en `audit_log` (D6, `rules/security.md`).
NFR7: **Aislamiento de capas/contextos** (`make php.deptrac` + `php.lint.bounded-context`): el listener vive
en `Infrastructure`; `Erpify\Shared\…` importable; ningún `Domain/` alcanza framework.
NFR8: **Atribución pre-auth**: hasta auth toda escritura/lectura se atribuye a `anonymous`/`system`
(mecánicamente completa, ISO-completa solo tras E3) (D9).

### FR Coverage Map

FR1, FR2, FR3, FR4, FR5, FR6: E1 — captura de escrituras (CDC onFlush + diff + acción semántica).
FR7: E1 — extractor de recurso en lecturas.
FR8: E1 — suelo de retención.
FR16: E1 — render del diff en read model + UI #377.
FR9, FR10, FR11, FR12: E2 — crypto-shredding + keystore + clasificación PII.
FR13, FR14, FR15: E3 — RBAC + auto-auditoría + atribución real + prod.
FR17: E1 (mapeo ISO + integridad base) · reforzado en E2 (clasificación) y E3 (acceso).

## Epic List

### Epic 1: Captura de escrituras regulatoria — diff campo a campo (pre-auth, sin prerequisitos)
Toda alta/edición/borrado de un agregado auditado queda en `audit_log` como **diff campo a campo** +
**acción semántica**, atribuido al `ActorContext` actual (`system`/`anonymous` hasta auth) y visible en
la UI #377 (que hoy no muestra escrituras). Incluye el extractor de recurso en lecturas y el suelo de
retención. **Gate:** ninguno. **FRs:** FR1–FR8, FR16, FR17(base). **NFRs:** NFR1, NFR2, NFR4, NFR5, NFR7, NFR8.

### Epic 2: Diffs PII-safe — crypto-shredding + keystore (disparada por el dato, no por un agregado)
El diff de una entidad con campos PII se almacena crypto-shredded, de modo que el olvido funcione sin romper
append-only. **Disparada ahora** por la auditoría de `BankAccount` (primer campo PII en `audit_log`); **no
introduce un agregado Persona** — la identidad cripto es un `EncryptionScopeId` desacoplado del dominio (ADR
D10/D13). **FRs:** FR9–FR12, FR17(clasificación). **NFRs:** NFR3, NFR6.

### Epic 3: Acceso restringido + auto-auditado + atribución real (condicionada a auth/RBAC)
El trail (y la ruta #377) queda con control de acceso, el auditor se audita, la atribución es real
(`actor_id` real por request autenticada; columna nullable, D9 tier-1) y la superficie es apta para
producción. **Gate:** subsistema auth/RBAC (firewall +
voter), inexistente hoy → aguas abajo de construir auth. **FRs:** FR13–FR15, FR17(acceso). **NFRs:** NFR8.

---

## Epic 1: Captura de escrituras regulatoria — diff campo a campo

Toda alta/edición/borrado de un agregado auditado queda en `audit_log` como diff campo a campo + acción
semántica. Construye sobre el backbone vivo (`AuditLogger`, `audit_log`, `ActorContext`); independiente de
E2/E3. Sin prerequisitos: la atribución sale `anonymous`/`system` hasta que entre auth, sin retrabajo.

### Story 1.1: Listener Doctrine `onFlush` que captura el changeset campo a campo

Como plataforma de ERPify,
quiero un listener `onFlush` que lea `UnitOfWork::getEntityChangeSet()` para insert/update/delete,
para capturar el diff campo a campo de cada escritura sin reimplementar lo que el ORM ya calcula.

**Acceptance Criteria:**

**Given** una entidad auditada que se inserta/actualiza/borra dentro de un flush,
**When** se ejecuta `onFlush`,
**Then** el listener obtiene de `UnitOfWork::getEntityChangeSet()` el conjunto de campos cambiados con su
valor `antes`/`después` (en insert, `antes` vacío; en delete, ver Story 1.4) (FR1).

**Given** el listener,
**When** se ubica y se analiza,
**Then** vive en `Shared/Audit/Infrastructure`, no introduce un bundle de terceros, y pasa
`php.deptrac` / `php.lint.bounded-context` (FR2, NFR7).

**Given** la durabilidad de la fila de escritura,
**When** se diseña la inserción,
**Then** la historia **cierra explícitamente** el nivel/durabilidad: candidato principal = inserción
síncrona enrolada en la transacción del flush (atomicidad cambio↔auditoría), de modo que no haya cambio
sin fila ni fila sin cambio; se confirma con el usuario antes de implementar (NFR1) — no es best-effort.

**Given** una entidad **no** auditada (o un cambio sin relevancia de cumplimiento),
**When** se flushea,
**Then** el listener no emite fila (la inclusión es por allowlist/clasificación explícita, no «todo lo que
toca el ORM») — evita el equivalente al log-explosion en escritura.

### Story 1.2: Acción semántica + forma del diff en `metadata`

Como investigador,
quiero una acción legible (`BANK_UPDATED`) sobre el diff estructurado,
para leer el trail como intención y no como un muro de deltas de columnas.

**Acceptance Criteria:**

**Given** un insert/update/delete capturado,
**When** se construye la entrada,
**Then** la `action` es semántica y de cardinalidad-1 (`BANK_CREATED`/`BANK_UPDATED`/`BANK_DELETED`),
indexable; el diff campo a campo viaja estructurado en `metadata` (FR3).

**Given** el mapeo de entidad/operación → acción semántica,
**When** se diseña,
**Then** lo declara el módulo dueño (no una lista central en `Shared`), espejo de cómo `AuditPolicy`
evita un catálogo de rutas; una entidad nueva añade su mapeo sin tocar la clase compartida.

**Given** el diff en `metadata`,
**When** la entidad es un catálogo (`Bank`),
**Then** se almacena en claro (no es PII) — el cifrado por sujeto llega en E2 solo para personas (FR12).

### Story 1.3: Actor-context + correlación sellados dentro de `onFlush`

Como plataforma de ERPify,
quiero sellar `ActorContext` + `correlation_id` dentro del flush,
para que cada fila de escritura quede atribuida y correlacionada como el resto del trail.

**Acceptance Criteria:**

**Given** `onFlush` (que tiene el changeset pero no el actor HTTP),
**When** construye la entrada,
**Then** sella `ActorContext` (de `ActorContextFactory`) y `correlation_id` reutilizando la costura
`SealedAuditEntryFactory`, y `occurred_on` desde el `Clock` (FR6, NFR4).

**Given** que no existe auth,
**When** la escritura ocurre en una request `/api/*`,
**Then** el actor sella `anonymous`; en CLI/scheduler sella `system` (NFR8).

**Given** la entrada de auth futura,
**When** se razona sobre el impacto,
**Then** solo cambia `ActorContextFactory`: esquema, bus, storage y read model no se tocan (NFR2).

### Story 1.4: Snapshot de la entidad en DELETE

Como investigador de cumplimiento,
quiero que el borrado registre el estado final antes de desaparecer,
para que el «antes» del diff de un DELETE no se pierda.

**Acceptance Criteria:**

**Given** una entidad auditada que se borra,
**When** se ejecuta `onFlush`,
**Then** la entrada registra el snapshot de su estado final (el «antes» completo) antes de que la fila se
elimine, con `action=…_DELETED` (FR5).

**Given** la reconciliación con el eje de negocio,
**When** se compara con `event_store`,
**Then** la fila de borrado de auditoría es **cumplimiento** (quién/qué/cuándo/diff), no la verdad de
negocio del agregado — no duplica el `DomainEvent` de borrado (FR4, NFR5).

### Story 1.5: Extractor de recurso en lecturas (qué recurso se vio)

Como investigador,
quiero que cada fila de lectura registre qué recurso concreto se vio,
para responder «quién accedió a qué registro» y no solo «alguien vio un listado».

**Acceptance Criteria:**

**Given** la vía genérica de lectura (`AccessLogAuditListener`),
**When** una lectura es auditable,
**Then** un **extractor de recurso** resuelve `resource_type`/`resource_id` desde la ruta/controlador y
los sella en la fila (hoy quedan null·null) (FR7).

**Given** `AuditPolicy`,
**When** se revisa,
**Then** el extractor **no** introduce un catálogo de rutas por módulo en la policy (la identidad del
recurso la aporta cada ruta) (FR7).

**Given** la atribución pre-auth,
**When** se registra la lectura,
**Then** el «quién» sale `anonymous`/`system` y se vuelve significativo en E3 (NFR8).

### Story 1.6: Suelo de retención (5 años) para filas de cumplimiento

Como responsable de cumplimiento,
quiero que la poda respete un suelo mínimo de retención,
para que la minimización no borre evidencia regulatoria antes del mínimo legal.

**Acceptance Criteria:**

**Given** `AuditRetentionPolicy`/`DbalAuditLogPruner` (hoy solo techo por nivel),
**When** corre la poda,
**Then** una fila de cumplimiento no se borra antes del **suelo de 5 años**, aunque el techo de su nivel
sea menor (FR8).

**Given** una solicitud de olvido dentro del suelo,
**When** se procesa,
**Then** se satisface por crypto-shredding (E2), nunca borrando la fila (FR8, enlaza E2).

**Given** filas sembradas de distintas edades,
**When** corre la poda,
**Then** se borran exactamente las que superan techo **y** suelo (integración contra Postgres real).

### Story 1.7: Render del diff en el read model + UI #377 (solo `Bank`)

Como administrador,
quiero ver create/update/delete de `Bank` con su diff en el timeline,
para investigar cambios, no solo navegación.

> **Scope:** solo `Bank` (institución financiera, no-PII). La captura y el render de `BankAccount` se
> trasladan a E2: su diff lleva PII (`holderName`/`iban`) → debe auditarse crypto-shredded, no en claro.

**Acceptance Criteria:**

**Given** el read model de `Backoffice/Audit` (timeline 4.1),
**When** se consulta el detalle de una fila de escritura,
**Then** se expone vía un **recurso canónico `GET /audit/events/{id}`** (no un "detalle del timeline") que
devuelve la acción semántica y el diff campo a campo; el listado keyset queda slim (FR16, D-A).

**Given** la UI #377,
**When** se abre el detalle de una escritura `change`,
**Then** muestra el diff `antes`/`después` por campo con **color+texto para añadido/eliminado/modificado**,
**colapso de diffs muy grandes** e **indicador de tipo de campo**, escapando todo input no confiable (sin
`dangerouslySetInnerHTML`); el diff de `Bank` se muestra en claro (FR16, `rules/security.md`, D-B).

**Given** la documentación,
**When** se cierra esta rebanada de E1,
**Then** `docs/rules/security.md` y `PRODUCTION_SECURITY_CHECKLIST.md` reflejan el mapeo ISO base (A.8.15
append-only, A.8.17 clock) y `docs/architecture-api.md` describe el nuevo emisor de escritura y la
superficie de lectura de detalle (FR17).

## Epic 2: Diffs PII-safe — crypto-shredding + keystore

El diff de una entidad con campos PII se almacena **crypto-shredded** para que el olvido funcione sin romper
append-only. Construye sobre E1; independiente de E3. **Disparada por el dato, no por un agregado** (ADR D10):
auditar `BankAccount` introduce el primer campo PII (`holderName`/`iban`) en `audit_log`, así que E2 se
construye **ahora**. **No introduce un agregado Persona/Party** — la identidad criptográfica es un value object
`EncryptionScopeId` (`BANK_ACCOUNT:<uuid>` hoy), desacoplado del dominio (ADR D13).

### Story 2.1: Clasificación PII por campo (`#[PersonalData]`)

Como responsable de cumplimiento,
quiero declarar qué campos son datos personales en la propia entidad,
para decidir qué columnas del diff se cifran y cuáles van en claro.

**Acceptance Criteria:**

**Given** un agregado auditado con campos personales,
**When** se clasifica,
**Then** sus campos PII se marcan con un atributo **pasivo `#[PersonalData]`** propiedad del módulo dueño (no
un mapa central en `Shared/Audit`); la auditoría — y cualquier otra infra de tratamiento de datos personales —
solo lo **lee** para decidir cifrado-vs-claro por columna (A.5.12, FR11, ADR D12).

**Given** `BankAccount`,
**When** se clasifica,
**Then** `holderName` e `iban` son `#[PersonalData]`; `bic`/`currency`/`status`/`bankId` van en claro. `Bank`
(institución) no tiene campos PII (FR12, NFR6, ADR D11).

### Story 2.2: Keystore + envelope libsodium (DEK por `EncryptionScopeId`)

Como plataforma de ERPify,
quiero una tabla keystore con DEKs por scope envueltas por una KEK,
para soportar crypto-shredding por sujeto sin acoplarlo al dominio.

**Acceptance Criteria:**

**Given** el esquema envelope,
**When** se implementa,
**Then** existe una tabla keystore en Postgres con una DEK por **`EncryptionScopeId`** (`BANK_ACCOUNT:<uuid>`
hoy), generada con **CSPRNG**, envuelta por una KEK y guardando su **`kek_version`**, usando libsodium AEAD
(`crypto_aead_xchacha20poly1305_ietf`) (FR10, ADR D13/D14).

**Given** la KEK,
**When** se gestiona,
**Then** se **custodia fuera de la app** (env, nunca junto a las DEKs); la rotación de KEK = **rewrap por
lotes acotado**; el ciclo de vida de la DEK (alta por scope, envoltura, **destrucción irreversible**) queda
definido (FR10, ADR D14, reto load-bearing).

### Story 2.3: Auditar `BankAccount` con el diff PII crypto-shredded

Como responsable de cumplimiento,
quiero que las escrituras de `BankAccount` se auditen con sus campos PII cifrados,
para registrar el cambio sin dejar PII en claro en una tabla append-only.

**Acceptance Criteria:**

**Given** `BankAccount` (hoy **no** auditado),
**When** se cablea,
**Then** implementa `AuditedEntity` (`auditResource()` / `auditAction()` → `BANK_ACCOUNT_CREATED`/`_UPDATED`/
`_DELETED`), igual que `Bank` — su captura sale de la Story 1.7 y vive aquí porque su diff lleva PII (ADR D10).

**Given** un cambio sobre `BankAccount`,
**When** el listener `onFlush` construye la entrada,
**Then** los campos `#[PersonalData]` del diff se cifran bajo la DEK del **`EncryptionScopeId`**
(`BANK_ACCOUNT:<id>`) y la fila referencia el scope; los campos no-PII quedan en claro (FR9, FR11, NFR6, ADR
D13).

**Given** el eje hermano,
**When** se revisa,
**Then** el crypto-shredding del diff **compone** con el erasure de actor (D4 remint `actor_id` / D4.1
`actor_erased`) sin reabrirlo — loci de PII distintos: el actor se olvida por remint, el PII del diff por
destrucción de DEK (FR12).

**Given** la frontera hexagonal,
**When** se revisa el dominio,
**Then** ningún metadato cripto (`encryption_scope_id`, `kek_version`, ciphertext) contamina la entidad
`BankAccount` — vive en el keystore y en `audit_log` (raw-DBAL, entity-free) (ADR D17, deptrac).

### Story 2.4: `erase-subject` — olvido por sujeto (destruir la DEK)

Como responsable de cumplimiento,
quiero un "olvídame" por sujeto que destruya su DEK,
para volver ilegible su PII conservando la fila y la integridad.

**Acceptance Criteria:**

**Given** una solicitud de olvido para un sujeto,
**When** se ejecuta `erase-subject` (comando operador-driven, **distinto** de `erase-actor` /
`audit:gdpr:erase`),
**Then** (1) borra/anonimiza el dato vivo y (2) destruye la DEK de su `EncryptionScopeId`; el ciphertext del
diff queda **permanentemente ilegible**; fila, orden e integridad intactos (append-only) (FR9, NFR3, ADR D15).

**Given** la operación,
**When** se repite,
**Then** es idempotente (DEK ya destruida → no-op).

**Given** la separación de conceptos,
**When** se revisa,
**Then** `erase-subject` (de quién es el dato) y `erase-actor` (quién actuó) nunca se mezclan — disparadores
GDPR distintos (ADR D15).

**Given** la evidencia,
**When** se reconcilia,
**Then** todo scope con DEK destruida casa con su evidencia de olvido (cross-check tipo
`GDPR_ERASURE_EXECUTED`); una divergencia es violación de integridad detectable.

## Epic 3: Acceso restringido + auto-auditado + atribución real

El trail (y la ruta #377) queda con control de acceso, el auditor se audita y la atribución es real.
Construye sobre E1; **gate:** subsistema auth/RBAC (firewall + voter), inexistente hoy.

### Story 3.1: Voter RBAC sobre las rutas de lectura del trail + atribución real de actor

Como responsable de seguridad,
quiero que solo roles autorizados lean el trail y que la atribución sea real,
para satisfacer A.5.18 y que el «quién» deje de ser `anonymous`.

**Acceptance Criteria:**

**Given** las rutas de lectura de `Backoffice/Audit` (incluida la #377),
**When** una request sin el rol requerido las invoca,
**Then** un voter las deniega (403 RFC 9457) (FR13).

**Given** auth real en vigor,
**When** se sella una entrada de una request autenticada,
**Then** `actor_id` lleva el UUID real del actor y la atribución del diff/lectura responde *qué usuario*;
`system`/off-request siguen `NULL` (la columna permanece nullable, D9 tier-1); solo cambia
`ActorContextFactory` (FR15, NFR2).

### Story 3.2: Auto-auditoría del acceso (auditar-al-auditor)

Como responsable de seguridad,
quiero que leer el trail deje su propia traza,
para que el acceso a la auditoría sea él mismo auditable (A.5.18/8.15).

**Acceptance Criteria:**

**Given** una lectura autorizada del trail,
**When** se ejecuta,
**Then** emite una fila `security` que registra quién leyó qué (FR14).

**Given** esa fila `security`,
**When** se persiste,
**Then** usa la inserción durable write-before-send del eje hermano (no best-effort) (FR14, D3 del hermano).

### Story 3.3: Levantar el gate de producción

Como responsable de despliegue,
quiero la superficie de lectura apta para producción una vez exista control de acceso,
para exponer el trail y la #377 sin hallazgo ISO.

**Acceptance Criteria:**

**Given** voter + auto-auditoría en vigor,
**When** se revisa el gating de prod,
**Then** se retira la restricción «no llega a producción hasta que exista auth» de la ruta #377 y del
trail regulatorio (FR15, D8).

**Given** `PRODUCTION_SECURITY_CHECKLIST.md` y `docs/rules/security.md`,
**When** se cierran,
**Then** reflejan acceso restringido + auto-auditado y el mapeo ISO completo (A.5.18/8.15) (FR17).

## Riesgos / decisiones abiertas

- **Durabilidad/nivel de las filas de escritura (NFR1, Story 1.1):** el ADR fija la captura, no el
  contrato de durabilidad de los registros de cambio. Candidato principal: inserción síncrona en la
  transacción del flush (atomicidad cambio↔auditoría). **A confirmar con el usuario en E1.**
- **E2 disparada ahora (no gated):** la reclasificación de `BankAccount` como PII (ADR D11) dispara E2 por el
  dato, no por un agregado. La identidad cripto es `EncryptionScopeId` (`BANK_ACCOUNT:<uuid>`), **sin** agregado
  Persona; el agregado Party llega cuando el dominio lo pida y heredará el scope (ADR D13/D16).
- **Gate de E3:** depende del subsistema auth/RBAC, hoy inexistente — primer prerequisito probable de toda
  la fase regulatoria «completa».
- **UX del diff (Story 1.7, solo `Bank`):** la UI #377 ya existe (read-only); mostrar diffs campo a campo
  puede requerir un pase UX (Sally). El render del diff PII de `BankAccount` aterriza con E2.
