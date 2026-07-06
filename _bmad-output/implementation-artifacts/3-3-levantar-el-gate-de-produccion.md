---
title: 'Story 3.3: Levantar el gate de producción (cerrar mapeo ISO A.5.18/8.15)'
type: 'chore'
created: '2026-07-06'
status: 'done'
baseline_commit: '2451fb61'
context:
  - '{project-root}/_bmad-output/implementation-artifacts/epic-3-context.md'
  - '{project-root}/docs/adr/regulatory-audit-trail.md'
  - '{project-root}/docs/adr/auth-rbac-subsystem.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** La superficie de lectura del trail (timeline + detalle, incl. la UI #377) y el trail regulatorio arrastran la reserva «no llega a producción hasta que exista auth», y el mapeo ISO en el gate autoritativo de pre-producción (`PRODUCTION_SECURITY_CHECKLIST.md`) y en `docs/rules/security.md` está *stale/incompleto*: describe las lecturas de audit como «públicas hasta el auth gate», sin `A.5.18`, sin ninguna frase de auto-auditoría. La fundación auth + voter RBAC (#439/#442) y la auto-auditoría de lectura (#452) ya están en `main` → la reserva es falsa y el mapeo debe cerrarse.

**Approach:** Es un **lift puramente documental — cero runtime**. La investigación confirmó que *no existe ningún gate en código/config* (ni `#[When]`, ni `APP_ENV`, ni feature flag ocultando las rutas o la #377); las lecturas ya imponen `#[IsGranted]` (3.1) y se auto-auditan (3.2). Retirar la reserva «público/hasta-auth» y cerrar el mapeo ISO **A.5.18** (acceso restringido) + **A.8.15** (registro del acceso a los registros) en los dos ficheros que nombra FR17; marcar como entregado el `Status` de ambos ADR; y corregir el único comentario de código stale que afirma que la ruta es «public pre-auth».

## Boundaries & Constraints

**Always:**
- **Solo docs + comentarios.** Ningún cambio en runtime, ruta, config, `security.yaml`, esquema ni comportamiento de tests. `make db.diff` debe reportar «No changes detected».
- Enunciar la realidad **ya enviada**: ambas rutas de lectura (`GET /api/v1/backoffice/audit/timeline` y `GET .../audit/events/{id}`) son RBAC-restringidas vía `#[IsGranted('ROLE_AUDIT_READER')]` (sub-privilegiado → 403 `forbidden`, anónimo → 401 `unauthenticated`, por el pipeline RFC 9457), y toda lectura autorizada auto-audita una fila `security` `AUDIT_TRAIL_READ` durable (write-before-send). Mapear a `A.5.18` (derechos de acceso) + `A.8.15` (registro, incl. del acceso a los logs).
- Respetar el estilo de cita ISO **de cada fichero**: paréntesis plano en el checklist; **negrita** en `security.md`.
- Los enlaces Markdown deben resolver a ficheros concretos (linter del repo). En los ADR **solo cambia la línea `Status`** (puntero de estado vivo); los cuerpos de decisión (D8/D9, Implementation) quedan intactos (son historia; el estado-actual vive en `architecture-api.md`).

**Ask First:**
- **Trato de los ADR:** por defecto = *solo la línea `Status`* (preserva la historia de decisión D8/D9). Si prefieres reescribir los cuerpos D8/D9 a pasado, dilo.

**Never:**
- Ni voter nuevo, ni marker, ni cambio del error-contract, ni edición de `access_control`/`security.yaml`, ni migración.
- **Fuera de alcance** (no tocar): la nota PWA «deferred until auth» del drawer / `architecture-pwa.md` (es el *read model de detalle 4.2a*, otra feature); la reserva «public until auth» de `/backoffice/bank-accounts` (#240, otro rollout); `api-error-contract.md:92` (la superficie de descifrado-en-lectura sigue sin ruta). No tocar artefactos de planificación (`_bmad-output/`).

</frozen-after-approval>

## Code Map

- `PRODUCTION_SECURITY_CHECKLIST.md` — gate autoritativo de pre-prod; item `audit_log` (~L159-193) + session-firewall (~L216-217).
- `docs/rules/security.md` — L63-64 (`#### Database Files`): auth-failures + mapeo ISO de `audit_log`.
- `docs/adr/regulatory-audit-trail.md` — línea `Status` (L3); cuerpos D8/D9 intactos.
- `docs/adr/auth-rbac-subsystem.md` — línea `Status` (L3); cuerpos intactos.
- `docs/index.md` — L69: paréntesis final del resumen del ADR regulatorio.
- `api/src/Backoffice/Audit/Domain/AuditEventDetail.php` — docblock ~L19-20 («public pre-auth», stale).

## Tasks & Acceptance

**Execution:**
- [x] `PRODUCTION_SECURITY_CHECKLIST.md` -- reemplazar «consciously public … until the auth gate» (~L176) por: ambas rutas de lectura RBAC-restringidas (`ROLE_AUDIT_READER`; 403/401 por RFC 9457); añadir una frase de auto-auditoría (cada lectura autorizada → fila `security` `AUDIT_TRAIL_READ` durable, write-before-send) calcando el estilo de la frase `GDPR_ERASURE_EXECUTED`; extender la cita ISO (~L179) para añadir `A.5.18` y ampliar `A.8.15` a «logging of access to logs»; poner en pasado «the shape `#[IsGranted]` … (Epic 3) relies on» (~L216) -- el checklist es el gate autoritativo de pre-prod.
- [x] `docs/rules/security.md` -- L63 cambiar «(Epic 3 adds `#[IsGranted]` on the audit read routes)» a presente/enviado; L64 añadir **A.5.18** (estilo negrita) + ampliar A.8.15, y enunciar que ambas rutas (añadir la ausente `/audit/timeline`) son RBAC-restringidas + auto-auditadas -- espeja el mapeo del checklist.
- [x] `docs/adr/regulatory-audit-trail.md` -- línea `Status`: anotar que el gate RBAC de D8 y la production-readiness los entrega Epic 3 (auth foundation + RBAC del trail + auto-auditoría de lectura, enviados); conservar cuerpos D8/D9 -- Status es el puntero vivo, la decisión es historia.
- [x] `docs/adr/auth-rbac-subsystem.md` -- línea `Status`: anotar Epic 3 enviado y el gate de producción de la #377/trail levantado; conservar cuerpos.
- [x] `docs/index.md` -- L69: girar el paréntesis final a «RBAC-restricted + self-audited; production gate lifted with the auth foundation».
- [x] `api/src/Backoffice/Audit/Domain/AuditEventDetail.php` -- reescribir el docblock (~L19-20): `ip`/`user_agent` ausentes porque esta proyección diff-only omite PII -- eliminar el «the route is public pre-auth» ya falso.

**Acceptance Criteria:**
- Given los dos ficheros que nombra FR17, when aterriza 3.3, then ninguno describe las lecturas de audit como «público/hasta auth» y ambos enuncian acceso restringido (`ROLE_AUDIT_READER`, 403/401) + auto-auditado (`AUDIT_TRAIL_READ`) mapeado a A.5.18/A.8.15.
- Given `git grep -niE "until the auth gate|public pre-auth|\(Epic 3\) relies|blocks prod until auth"` excluyendo `_bmad-output/`, when se corre tras las ediciones, then no queda ningún hit en doc/fuente durable.
- Given `make db.diff` y `make php.quality`, when se corren, then «No changes detected» / EXIT 0 -- el cambio es docs + un comentario.

## Design Notes

- **Por qué docs-only:** no hay gate de runtime (verificado: ni `#[When]`/`APP_ENV`/flag oculta las rutas o la #377). El «gate» siempre fue la reserva del ADR/checklist + un comentario stale; levantarlo = hacer que los docs durables cuenten la verdad ya enviada.
- **Deltas ISO:** hoy ambos ficheros citan solo `A.8.15` + `A.8.17`. Añadir `A.5.18` (acceso restringido a las lecturas de audit) y ampliar `A.8.15` a «logging of access to logs» (la fila de auto-auditoría). La frase `GDPR_ERASURE_EXECUTED` del checklist (~L186) es el patrón para redactar la de `AUDIT_TRAIL_READ`.

## Verification

**Commands:**
- `make db.diff` -- expected: «No changes detected» (sin tocar esquema).
- `make php.stan PHP_SERVICE=messenger_worker` -- expected: No errors (cambio de comentario en `AuditEventDetail`; override por el segfault del web worker).
- `make php.quality` -- expected: EXIT 0 (cs-fixer/phpmd/rector/phpcs/deptrac/gherkinlint) sobre el único cambio PHP.
- `git grep -niE "until the auth gate|public pre-auth|\(Epic 3\) relies|blocks prod until auth"` (excluir `_bmad-output/`) -- expected: sin hits durables.

**Manual checks:**
- Cada enlace Markdown añadido/conservado resuelve a un fichero concreto (linter IDE del repo).
- (Opcional, defensivo) Live `curl -k` en el HTTPS del worktree: `/audit/timeline` sin rol → 403; con `ROLE_AUDIT_READER` → 200 + fila `security AUDIT_TRAIL_READ` — confirma que las afirmaciones de los docs cuadran con lo enviado (ya cubierto por los tests de 3.1/3.2).

## Suggested Review Order

**El gate autoritativo de pre-producción**

- Entry point: el item `audit_log` gira «público hasta auth» → RBAC-restringido + auto-auditado + A.5.18
  [`PRODUCTION_SECURITY_CHECKLIST.md:178`](../../PRODUCTION_SECURITY_CHECKLIST.md#L178)

- El firewall ya no difiere el `#[IsGranted]` a «Epic 3»: es la forma que las rutas de audit imponen hoy
  [`PRODUCTION_SECURITY_CHECKLIST.md:222`](../../PRODUCTION_SECURITY_CHECKLIST.md#L222)

**La regla de seguridad (mapeo ISO)**

- Espejo del cierre: A.5.18 + A.8.15 ampliado + ambas rutas restringidas y auto-auditadas
  [`security.md:64`](../../docs/rules/security.md#L64)

**Los ADR de decisión + el índice**

- Status del trail regulatorio: gate D8/D9 entregado por Epic 3 (cuerpos de decisión intactos)
  [`regulatory-audit-trail.md:3`](../../docs/adr/regulatory-audit-trail.md#L3)

- Status del subsistema auth: E3 enviado, gate de #377/trail levantado; cabecera reencuadrada a histórico
  [`auth-rbac-subsystem.md:3`](../../docs/adr/auth-rbac-subsystem.md#L3)

- El índice espeja los Status a «implemented» y retira «blocks prod until auth»
  [`index.md:69`](../../docs/index.md#L69)

**El único toque de código**

- Comentario stale «route is public pre-auth» → el porqué actual (proyección diff-only omite PII)
  [`AuditEventDetail.php:19`](../../api/src/Backoffice/Audit/Domain/AuditEventDetail.php#L19)
