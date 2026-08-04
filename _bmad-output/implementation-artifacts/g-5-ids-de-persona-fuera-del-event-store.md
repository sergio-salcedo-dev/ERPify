---
baseline_commit: 8bc9893a
---

# Story 1.7 (G-5): Ids de persona fuera del `event_store`

Status: ready-for-dev

> **LA DECISIÓN ESTÁ TOMADA Y REGISTRADA EN UN ADR** (Sergio, 2026-08-01):
> [`docs/adr/event-store-and-projections.md`](../../docs/adr/event-store-and-projections.md) **D12**. El log pasa
> a *append-only con un conjunto cerrado de mutaciones sancionadas* —hoy una, el borrado GDPR—, igual que su
> hermano `audit_log`. **Un único `UPDATE` parametrizado** reescribe el id del sujeto con un UUID aleatorio nuevo
> acuñado en el borrado, **en la columna y en las claves de `payload`**, dentro de la transacción que
> `FulfilIdentityErasure` ya posee. **No la reabras.** Lo que faltaba era este artefacto, no la decisión.

> **Es la última historia de la épica.** Mientras G-5 no cierre, `epic-gdpr-hardening` no puede declararse
> completada. Todas las demás (G-1a, G-1b, G-1c, G-2, G-3a, G-3b, G-4a) están mergeadas.

## Story

Como **sujeto de datos borrado**,
quiero que mi identificador tampoco sobreviva en el log de eventos de negocio,
para que el borrado deje de ser cierto solo en las tablas que alguien se acordó de mirar.

**Eje que instala:** la última superficie del eje SI-21 — la tabla que ningún registro alcanza.
**Invariantes que consume:** SI-21/NFR1, D4/NFR4 (prohibición de crosswalk), SI-23 (el control no puede leerse
verde por construcción).
**Dependencias:** ninguna. G-4a cerró la fuga de Messenger; ésta es la permanente que aquélla no alcanza.

## Estado medido (`main` @ `8bc9893a`)

> *Procedencia:* pase read-only sobre el árbol del día. **Dos afirmaciones del corte cedieron al medirlas** y
> están corregidas abajo. Las coordenadas se dan para re-verificarlas, no para citarlas de memoria.

### La superficie son DIECISÉIS tipos de evento en DOS ejes, y el corte contaba OCHO

| Eje | Eventos | N |
|---|---|---|
| `aggregate_id` **es** el id de la persona | los 6 de `Iam.Identity` (`PasswordResetCompleted`, `PasswordResetRequested`, `UserDeactivated`, `UserLocked`, `UserRolesChanged`, `UserSuspended`) **+ `AllSessionsRevoked` + `OtherSessionsRevoked`** | **8** |
| `payload` lleva el id de la persona | los 6 `Invitation*` (`invitedUserId`, vía el trait `CarriesInvitationSnapshot`) + `SessionStarted` + `SessionRevoked` (`userId`) | **8** |

**CORRECCIÓN 1 — el inventario del corte decía «`aggregate_id` de 6 eventos». Son 8.** Se dejaba los dos
revokes masivos, y no es una inferencia:
[`RevokeAllSessions.php:35`](../../api/src/Iam/Session/Application/RevokeAllSessions.php) publica
`new AllSessionsRevoked($userId, …)` y
[`RevokeOtherSessions.php:39`](../../api/src/Iam/Session/Application/RevokeOtherSessions.php)
`new OtherSessionsRevoked($userId, …)`; el docblock de `AllSessionsRevoked` lo dice con todas las letras:
*«A single coarse fact whose subject is the user, so the aggregateId is the `userId`»*. Es la **segunda**
enmienda a esta lista (la primera añadió `SessionRevoked`), lo cual es en sí un argumento a favor de que el
borrado sea **por coincidencia de valor y no por enumeración de eventos**, que es lo que D12 decidió.

**`.persistent-transport-policy` clasifica `Iam.Invitation => non-person` y ACIERTA.** Ese registro clasifica
por lo que denota el **`aggregate_id`**, y el de una invitación es la invitación. El id de persona viaja en su
`payload`, que ese eje no mira. Ningún registro del repo alcanza hoy el eje de payload — es exactamente el
hueco que G-2 cerró para `audit_log.metadata` y que aquí sigue abierto.

### Quién lee `aggregate_id` de vuelta

**CORRECCIÓN 2 — el corte afirma que «NINGUNA consulta de `src/` lee `aggregate_id` de vuelta». Lo lee una.**
[`DbalEventStore::append()`](../../api/src/Shared/Event/Infrastructure/Persistence/DbalEventStore.php) `:55-63`
calcula `COALESCE(MAX(aggregate_version), 0) + 1` con `WHERE aggregate_id = CAST(:aggregate_id AS UUID)`. Lo que
sí es cierto, y es la parte que importaba: **ningún consumidor ni proyección lo lee** — `stream()` (`:93-97`)
filtra por `sequence` y `event_name`, nunca por agregado.

**Consecuencia que el corte no enuncia: reescribir la columna MUEVE la fila de stream.** El `UNIQUE` es
`(tenant_id, aggregate_id, aggregate_version)` y **no incluye `aggregate_type`**, así que los eventos de
`Iam.Identity` y los dos revokes masivos del mismo sujeto **comparten secuencia de versión** (todos usan
`aggregate_id = userId`). Mover **todas** sus filas al **mismo** pseudónimo preserva la unicidad; moverlas a
pseudónimos distintos la rompería. El eje de payload no toca `aggregate_id`, así que no interactúa con el
`UNIQUE`. Y como el sujeto queda borrado, ningún append futuro apunta a ese id.

### La cadena de erasure no toca la tabla

`FulfilIdentityErasure` encadena identidad, ambos ejes de `audit_log`, sesiones, membresía e invitaciones —
**y ninguna sentencia sobre `event_store`**. Las únicas menciones en `Iam/Identity` son docblocks, incluidos
los dos que G-2 dejó diciendo que `event_store.aggregate_id` **queda fuera del control detective**. Cuando G-5
cierre, esos dos párrafos hay que revisitarlos.

### Trampas heredadas que muerden aquí

- **El payload es texto jsonb y Postgres NO normaliza el caso.** Medido en G-2 sobre `audit_log.metadata`: la
  misma fuga en mayúsculas pasaba verde bajo `LIKE` y roja bajo `ILIKE`. La ruta entrega la grafía del cliente
  sin canonicalizar (`Uuid::ensure()` valida sin normalizar).
- **`payload` puede ser `[]` y no `{}`.** `jsonb_object_keys` **aborta** sobre un array y `payload ? 'k'` lo
  silencia. Filtrar por `jsonb_typeof(...) = 'object'` o recorrer `::text`.
- **Reescribir por NOMBRE DE CLAVE es la declaración que se comprueba a sí misma.** Ya hay dos nombres
  (`invitedUserId`, `userId`) y el trait garantiza que aparecerán más. D12 lo zanja: por coincidencia de valor.
- **Un `@AfterScenario` rojo en Behat 4 rompe la build pero no marca escenario** — lee el exit code, no el
  recuento.

## Acceptance Criteria

1. **Given** un sujeto borrado, **When** se inspecciona `event_store`, **Then** su id real no sobrevive **ni en
   `aggregate_id` ni en `payload`**.
   → El testigo siembra su propio dato y **afirma el conteo de filas sembradas** antes del veredicto.
2. **Given** ese mismo borrado, **Then** el log sigue siendo reproducible como log: las filas siguen ahí, con su
   `sequence`, su `event_name` y su `aggregate_version` — la garantía no se compra destruyendo la traza.
3. **Given** una escritura futura que vuelva a meter un id de persona en cualquiera de los dos ejes, **When**
   corre la suite, **Then** un control **falla** — y **cada eje necesita su propio rojo**, porque un control que
   solo mire la columna se lee verde con el id vivo en el payload de al lado (D12 lo enuncia como el modo de
   fallo de esta historia).

## Tasks / Subtasks

- [ ] **Tarea 1 — Re-medir contra el árbol del día** antes de escribir código: el inventario 8+8 y las dos
      correcciones de arriba.
- [ ] **Tarea 2 (AC1, AC2)** — El `UPDATE` único, parametrizado, **por valor**, sobre columna y payload, dentro
      de la transacción que `FulfilIdentityErasure` ya posee. Un solo pseudónimo para todas las filas del
      sujeto (el `UNIQUE` de stream lo exige). Idempotente: una segunda pasada no encuentra nada.
- [ ] **Tarea 3 (AC1)** — Testigo de aceptación que siembre y demuestre **en el mismo escenario**, cubriendo
      **los dos ejes**, con `ILIKE` y sin suponer que `payload` es un objeto.
- [ ] **Tarea 4 (AC3)** — Falsificación por mutación: un rojo **por eje**, provocado de verdad, restaurando por
      copia de bytes (nunca `git checkout --`).
- [ ] **Tarea 5** — Revisitar los dos docblocks que G-2 dejó diciendo que `event_store.aggregate_id` queda fuera
      del control, y el bullet de `.person-reference-policy` que lo llama *standing leak*.
- [ ] **Tarea 6** — Pase adversarial por alguien distinto del autor, registrado, declarando dónde quedó.
- [ ] **Tarea 7** — Puertas con ejecución fresca y exit code impreso.
- [ ] **Tarea 8** — Corregir el marcador de G-2, que se mergeó en `in-progress`: → `done` en `sprint-status.yaml`
      y `Status: done` en su artefacto. Viaja en esta PR por decisión de Sergio (2026-08-04).

## Dev Notes

### Anti-patrones concretos

- **No enumeres eventos ni claves.** El inventario ya se equivocó dos veces; el `WHERE` por valor alcanza todo
  evento presente y futuro sin que ningún productor recuerde nada.
- **No uses pseudónimos distintos por fila**: rompe `(tenant_id, aggregate_id, aggregate_version)`.
- **No derives el pseudónimo del id real** ni crees tabla de mapeo — D4 veta ambas, con esas palabras.
- **No toques `metadata` de `event_store`** sin medir antes qué guarda: es otra columna, otro eje.

### References

- [`docs/adr/event-store-and-projections.md`](../../docs/adr/event-store-and-projections.md) — D12
- [`docs/adr/audit-activity-log.md`](../../docs/adr/audit-activity-log.md) — D4, y el precedente del conjunto cerrado de mutaciones
- [`g-2-ids-de-persona-fuera-de-audit-log-metadata.md`](g-2-ids-de-persona-fuera-de-audit-log-metadata.md) — el hermano: forma del testigo, trampas medidas y el pase adversarial

## Dev Agent Record

### Agent Model Used

claude-opus-5[1m]

### Completion Notes List

- Artefacto de contexto creado. Implementación pendiente.
