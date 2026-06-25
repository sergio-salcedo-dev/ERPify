---
title: "Story 3.2 — Borrado GDPR (anonimización irreversible del actor) sobre audit_log"
epic: 3
story: 3.2
status: ready-for-dev
branch: feat/shared-audit-gdpr-erasure-keoj
---

# Story 3.2 — Borrado GDPR ("olvídame") sobre `audit_log`

Implementa la **segunda** (y última) política de mutación del log append-only del ADR D4: el
"olvídame". A diferencia de la poda (3.1, único `DELETE`), esto es un **`UPDATE` que nunca borra
filas**: rompe de forma **irreversible** el vínculo de las filas con la persona, conservando la traza
de seguridad (`action`, `level`, `occurred_on`, recurso, correlación).

## Decisiones de diseño (cerradas con el usuario; D1–D11)

- **D1 — `actor_id` → UUID aleatorio único por sujeto.** Se acuña **un** UUID v7 nuevo en el momento
  del borrado y se aplica a **todas** las filas de ese `actor_id` en un único `UPDATE`. No se guarda
  el valor original ni tabla de mapeo ni hay derivación determinista → **anonimización efectiva** del
  actor (Recital 26), no pseudonimización con clave (Art. 4(5)). La traza queda correlacionada
  internamente (mismas N filas comparten el nuevo id), pero ya no es atribuible a la persona.
- **D2 — correlación intra-sujeto preservada** (un pseudónimo consistente por sujeto; no un centinela
  global que colapse todos los borrados).
- **D3 — `ip` y `user_agent` → centinela `[REDACTED]`** (no `NULL`): distingue "redactado por GDPR"
  de "nunca hubo dato". Cabe en `VARCHAR(45)`/`VARCHAR(512)`.
- **D4 — `metadata` no se toca.** Se confía en la invariante del ADR "sin PII en `metadata`". **No**
  se introduce un redactor especulativo (regla YAGNI del repo); solo se **documenta** el trigger de
  revisita (si una acción futura guardase PII en `metadata`, esta política debe crecer un redactor).
- **D5 — `correlation_id` intacto.** La re-identificación cruzada con logs externos que comparten el
  id es alcance de la retención de logs / SIEM, no de esta tabla.
- **D6 — comando de consola síncrono** `audit:gdpr:erase <actor-id>` con `--dry-run` y `--force`;
  imprime `Actor`, `Rows matched`, y (tras confirmar) `New anonymized UUID` + `Rows updated`. Sin
  endpoint HTTP (no hay auth todavía → no se podría proteger).
- **D7 — un único `UPDATE`, sin advisory lock ni lotes.** Volumen acotado por la actividad de un solo
  sujeto; idempotente; evita reintroducir el `PostgresAdvisoryLock` de 3.1 (no mergeado).
- **D8 — auto-auditoría del borrado.** Tras un borrado real, emitir vía el seam `AuditLogger->log(...)`
  una entrada `security` `GDPR_ERASURE_EXECUTED` con `metadata = {anonymized_actor_id, affected_rows}`.
  Nunca el `actor_id` original. Off-request el `SealedAuditEntryFactory` sella `actor=system`,
  `correlation_id` nuevo, `ip`/`ua` null — válido desde CLI.
- **D9 — sin serialización extra.** Concurrencia con la poda resuelta por locks de fila de Postgres;
  el peor caso (la poda elimina una fila antes del `UPDATE`) es aceptable.
- **D10 — fuera de alcance: DSAR → `actor_id`.** Esta story recibe un `actor_id`; el mapeo
  persona→id pertenece al futuro contexto de identidad.
- **D11 — `resource_id` no es identidad del sujeto borrado.** Documentar la regla: si un recurso
  representa directamente a una persona, lo gestiona la política GDPR de ese bounded context, no esta.

## Forma (archivos)

- `api/src/Shared/Audit/Application/AuditActorAnonymiser.php` — **puerto**:
  `countFor(string $actorId): int` (preview/dry-run, solo lectura) +
  `anonymise(string $actorId): ActorAnonymisationResult`.
- `api/src/Shared/Audit/Application/ActorAnonymisationResult.php` — DTO readonly
  `{ string $pseudonym, int $affectedRows }`.
- `api/src/Shared/Audit/Infrastructure/Persistence/DbalAuditActorAnonymiser.php` — adaptador DBAL
  (`#[AsAlias(AuditActorAnonymiser::class)]`, `Connection` inyectada; `UPDATE audit_log SET actor_id =
  CAST(:pseudonym AS UUID), ip = :redacted, user_agent = :redacted WHERE actor_id = CAST(:actor_id AS
  UUID)`; pseudónimo `Uuid::generate()`; centinela `[REDACTED]`).
- `api/src/Shared/Audit/Infrastructure/Cli/EraseActorAuditTrailCommand.php` —
  `#[AsCommand('audit:gdpr:erase')]`, `actor-id` arg, `--dry-run`/`--force`, valida UUID, llama al
  puerto, emite la entrada `security` D8 tras un borrado real.
- Docs: `docs/adr/audit-activity-log.md` (D4 wording D1 + D11), `PRODUCTION_SECURITY_CHECKLIST.md`,
  `docs/rules/security.md`, `docs/rules/database.md`.

## Criterios de aceptación

1. `anonymise(actorId)` sustituye `actor_id` por **un** UUID v7 nuevo en todas las filas de ese
   sujeto, pone `ip`/`user_agent` = `[REDACTED]`, no borra filas, no toca filas de otros actores ni
   las anónimas; devuelve `{pseudonym, affectedRows}`.
2. **Idempotente**: un segundo `anonymise(actorId)` con el id original afecta a 0 filas.
3. `countFor(actorId)` devuelve el nº de filas del sujeto sin mutar.
4. Comando: `--dry-run` muestra el recuento y **no** muta ni auto-audita; sin `--force` pide
   confirmación `[y/N]`; un `actor-id` mal formado → `Command::INVALID` antes de tocar la BD.
5. Un borrado real emite exactamente una entrada `security` `GDPR_ERASURE_EXECUTED` cuyo
   `metadata.anonymized_actor_id` es el **nuevo** pseudónimo (nunca el original) y
   `metadata.affected_rows` el recuento.
6. Gates verdes: `make php.stan`, `make php.quality`. Tests funcionales (Postgres real, en
   transacción revertida) + unit del comando (dobles) + DTO.
