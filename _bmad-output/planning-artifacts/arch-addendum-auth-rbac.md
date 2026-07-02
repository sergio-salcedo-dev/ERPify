# Arch addendum — subsistema auth/RBAC (scoped)

> **Estado:** aprobado (Sergio, 2026-07-02) · diseño · **prerequisito de Epic 3 del trail regulatorio** · **Alcance:** nuevo contexto `Backoffice/Identity`, firewall Symfony Security de sesión, autorización sobre las rutas de lectura de `Backoffice/Audit`.
> **Decisiones (el *qué* y el *por qué*):** [`../../docs/adr/auth-rbac-subsystem.md`](../../docs/adr/auth-rbac-subsystem.md).
> **Jerarquía:** `epics-regulatory-audit-trail.md` **>** este addendum. FR15 se reformuló en el epic para alinearlo con ADR D5 (= D9 tier-1 del hermano). No contradice D8/D9 de [`../../docs/adr/regulatory-audit-trail.md`](../../docs/adr/regulatory-audit-trail.md).

Método contract-first scoped: no repite el ADR ni describe estado actual; fija los **invariantes globales mínimos**, **localiza cada decisión en su PR** y da el **DAG de dependencias** para que E3 sea *dev-able*.

## System Invariants (globales — se cumplen en todo el subsistema)

- **SI-1 · Costura única de identidad.** Toda atribución de actor entra **exclusivamente** por `ActorContextFactory`; ningún writer, handler ni servicio de `Application` lee el token de seguridad directamente (ADR D6).
- **SI-2 · Framework confinado.** Symfony Security vive sólo en `Infrastructure/`; la identidad de dominio (`Backoffice/Identity/Domain`) es libre de framework — lo impone `deptrac` (ADR D2).
- **SI-3 · Gate de producción.** El trail regulatorio y la ruta #377 **no llegan a producción** hasta que el voter (D4) y la auto-auditoría de acceso (D7) estén en vigor (D8 del hermano).
- **SI-4 · Errores por el contrato.** Autenticación/autorización fallidas fluyen por el pipeline RFC 9457 (`401 unauthorized` / `403 forbidden`), nunca por `JsonResponse` manual (ADR D4; `php.lint.error-contract`).
- **SI-5 · Roles = autorización externa.** El enum de dominio `Role` es vocabulario que el adapter de Infra emite a Symfony como `ROLE_*` (unidireccional: dominio → Infra → Symfony, **nunca** al revés — el dominio es la fuente de verdad y no conoce el prefijo `ROLE_`); **ninguna lógica de `Application`/`Domain` ramifica por rol** para decidir comportamiento — Security concede/deniega el acceso *antes* de entrar y la aplicación no conoce roles (ADR D3). Impide que Security se filtre poco a poco a lógica de negocio.

## Localización de decisiones por PR

| PR / Story | Decisiones ADR | Costura / artefactos que toca |
|------------|----------------|-------------------------------|
| **PR-0 — auth foundation** (no existe como story hoy; lo corta el PM) | D1, D2, D3 | `composer req symfony/security-bundle`; contexto `Backoffice/Identity` (`User` + `HashedPassword` VO + repositorio + migración); `api/config/packages/security.yaml` (firewall sesión + CSRF); `UserProvider` + authenticator + `SecurityUser` adapter; enum `Role` |
| **Story 3.1** — voter + atribución real | D4, D5, D6 | `#[IsGranted('ROLE_AUDIT_READER')]` en `AuditTimelineSearchController` y `AuditEventDetailController`; nueva impl de `ActorContextFactory` (`forUser` desde el token); **columna `actor_id` intacta (nullable)** |
| **Story 3.2** — auto-auditoría del acceso concedido | D7 | listener sobre la respuesta OK de las rutas de audit → fila `security` durable (write-before-send), reusando la costura de `AccessLogAuditListener` |
| **Story 3.3** — levantar el gate de prod | — | retirar la restricción de prod de #377 + trail; cerrar `PRODUCTION_SECURITY_CHECKLIST.md` y [`../../docs/rules/security.md`](../../docs/rules/security.md) (mapeo ISO A.5.18/8.15) |

## DAG de dependencias

```
PR-0 (auth foundation: SecurityBundle · Backoffice/Identity · firewall+CSRF · SecurityUser · Role)
  └─> Story 3.1 (#[IsGranted] en 2 rutas · swap ActorContextFactory → forUser)
        └─> Story 3.2 (listener auto-auditoría de lectura concedida, durable)
              └─> Story 3.3 (levantar gate de prod · cerrar checklist ISO)
```

`PR-0` es **el subsistema auth/RBAC «inexistente hoy»** sobre el que E3 está *gated*: E3 (Stories 3.1–3.3) asume auth en vigor, pero la fundación aún no está representada como story. Sólo `PR-0` desbloquea 3.1.

## Siguiente paso BMAD

Cortar la épica «auth foundation» (PR-0) con `bmad-create-epics-and-stories` antes de que las Stories 3.1–3.3 sean *dev-ables*. Este addendum + el ADR son su contrato de entrada.
