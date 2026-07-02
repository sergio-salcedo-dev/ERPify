# Epic auth-foundation Context: Fundación auth/RBAC

<!-- Compiled from planning artifacts. Edit freely. Regenerate with compile-epic-context if planning docs change. -->

## Goal

ERPify hoy **no tiene ninguna infraestructura de autenticación**: todo `/api/*` es público y el actor de
auditoría se sella siempre como `anonymous`/`system`. Esta épica construye desde cero el subsistema de
identidad y control de acceso —firewall de sesión, agregado de dominio `User`, roles y baseline
default-deny— para que **Epic 3** del trail regulatorio (voter RBAC + auto-auditoría de acceso + atribución
real) sea *dev-able* y se pueda levantar el gate de producción de las rutas de lectura del trail (#377). Es
greenfield (la app no está en producción), sin restricciones de retrocompatibilidad. **Frontera dura:** esta
épica **no toca `ActorContextFactory`** ni el esquema/bus/storage del trail — la costura de actor permanece
`anonymous`/`system` hasta que E3 la reemplace por `forUser`.

## Stories

- Story AF-1.1: Agregado `User` en `Backoffice/Identity` + persistencia — **done** (#419)
- Story AF-1.2: Firewall de sesión + `SecurityUser`/provider/authenticator + CSRF
- Story AF-1.3: Baseline de control de acceso — default-deny + 401 por el pipeline

## Requirements & Constraints

- **Firewall de sesión httpOnly, no JWT.** `json_login` para el login de la PWA (same-origin sobre
  FrankenPHP); la sesión es cookie httpOnly. Sin tokens en cliente (regla PWA: nada en `localStorage`).
- **CSRF stateless double-submit.** `SameSite=Lax` + verificación de `Origin` en métodos no-seguros +
  token CSRF *stateless* (`csrf_protection: stateless`: la PWA lee la cookie del token y la re-emite en
  cabecera). No se ensancha CORS/CSRF ni la política de Mercure; el JWT-cookie de Mercure queda intacto y
  ortogonal.
- **Identidad de dominio libre de framework.** `User` (id UUID v7, email identificador, VO `HashedPassword`,
  roles como enum) no implementa contratos de Symfony; un adapter en Infrastructure sí. El hashing vive en
  Infrastructure; el dominio nunca conoce el algoritmo.
- **Roles = autorización externa.** El adapter emite `->value` del enum como `ROLE_*`; el prefijo `ROLE_`
  sólo existe en el borde de Infra (dominio = fuente de verdad, unidireccional). **Ninguna lógica de
  `Application`/`Domain` ramifica por rol** — Security concede/deniega *antes* de entrar.
- **Baseline default-deny.** `access_control` deniega por defecto con allowlist explícita de rutas hoy
  públicas; añadir una ruta protegida es sólo config, no toca el modelo.
- **Errores por el contrato RFC 9457.** Auth/authz fallidas fluyen por el pipeline (`401 unauthorized` /
  `403 forbidden`), nunca por `JsonResponse` manual (`php.lint.error-contract` verde). Existe el marker
  `Unauthenticated` (401); el puente `AuthenticationException`→401 se confirma/añade con su fila en
  `docs/api-error-contract.md`.
- **Aislamiento de capas/contextos.** Symfony Security confinado a `Infrastructure/`; `deptrac` +
  `php.lint.bounded-context` verdes.
- **Migración segura.** Reversible (`down()`), sin sembrar PII/secretos; el hard-delete de `User` mantiene
  satisfacible el borrado GDPR. **Nunca** credenciales en migraciones.
- **Cero retrabajo del eje de auditoría.** No se modifica `ActorContextFactory` ni el esquema/bus/storage
  del trail.

## Technical Decisions

- **Contexto `Backoffice/Identity`** por Regla de Tres (único consumidor hoy = acceso backoffice al trail);
  promocionable a `Identity`/`IAM` top-level sólo con un 2º consumidor real o capacidades propias de IAM
  (MFA, reset, sessions, API keys, OAuth, SSO). No antes.
- **`SecurityUser` adapter en `Infrastructure/Security/`** implementa `UserInterface` y envuelve al `User`;
  `UserProvider` carga por repositorio (`UserRepository::findByEmail(Email)`); authenticator + hashing
  (`PasswordHasherInterface`) en Infrastructure. DIP obligado por `deptrac` (importar `UserInterface` en
  `Domain/` rompería el gate).
- **Enum de dominio `Role`** (`AUDIT_READER`, `ADMIN`); estático, no tabla dinámica Role/Permission (RBAC de
  grano fino se promociona sólo con ≥3 ejes de decisión: propiedad, organización, clasificación).
- **Autorización de rutas (E3, no aquí):** `#[IsGranted('ROLE_AUDIT_READER')]` + `RoleVoter` interno de
  Symfony sobre las 2 rutas de lectura del trail; la denegación (`AccessDeniedException`) ya la mapea el
  pipeline a `403 forbidden` y ya la auto-audita `AccessDeniedAuditListener`. No se añade voter a medida.
- **`actor_id` permanece nullable** — "atribución real" es un invariante de la costura
  (`ActorContextFactory`), no una constraint de esquema; escrituras `system`/off-request siguen `NULL` por
  diseño. Sin migración de esquema.
- **`ActorContextFactory` es la única costura de atribución de actor** (E3): resuelve token → UUID del
  `User` → `ActorContext::forUser($uuid)`, con fallback `anonymous()`/`system()`. Ningún writer/handler lee
  el token de seguridad directamente.
- **Lifecycle del `User`:** sin auto-registro público (identidad backoffice-only); alta por admin
  autenticado (story posterior); **bootstrap del 1er usuario = comando `identity:user:create`** (hashea en
  Infra); dev/test = fixture Alice con hash precomputado.
- **Patrón a espejar:** el vertical slice `Backoffice/Bank` (agregado + puerto + adapter Doctrine
  `#[AsAlias]`); enum `BankAccountStatus` = forma del enum `Role`. Un módulo nuevo debe registrarse en
  `deptrac.yaml` (no auto-descubre).

## Cross-Story Dependencies

```
AF-1.1 (User + HashedPassword + repositorio + migración)  [done #419]
  └─> AF-1.2 (SecurityBundle · firewall+CSRF · SecurityUser · UserProvider · authenticator · Role→ROLE_*)
        └─> AF-1.3 (access_control default-deny + 401 por el pipeline)
              └─> E3 Story 3.1 (#[IsGranted] en 2 rutas + swap ActorContextFactory → forUser)
```

- AF-1.2 consume de AF-1.1: el puerto `UserRepository::findByEmail(Email)` (lookup **puro sin validar** — el
  `UserProvider` hace `Email::from` en try/catch → `UserNotFoundException`), el VO `HashedPassword`
  (recibe hash ya calculado), el enum `Role`. El `InMemoryUserRepository` (fake del puerto) quedó **diferido
  de AF-1.1 a AF-1.2**.
- AF-1.3 depende del firewall de AF-1.2 (define `access_control` sobre él).
- Toda la épica **desbloquea** E3 (Stories 3.1–3.3); sólo al completarla queda `epic-3` listo para arrancar.
