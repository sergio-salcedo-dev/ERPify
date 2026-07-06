---
stepsCompleted: ['step-01-document-discovery', 'step-02-prd-analysis', 'step-03-epic-coverage-validation', 'step-04-ux-alignment', 'step-05-epic-quality-review', 'step-06-final-assessment']
status: complete
project: ERPify
feature: IAM — ciclo de vida de identidad / invitación
branch: docs/iam-identity-invitation-bvdn
pr: 455
assessmentScope: >-
  Readiness de la FUNDACIÓN (ADR + addendum + UX) como input a la creación de
  épicas/historias. Épicas e historias IAM aún no existen (NEXT planificado =
  bmad-create-epics-and-stories); su ausencia se reporta como gap de cabecera.
inputDocuments:
  architecture:
    - docs/adr/identity-invitation-lifecycle.md
    - _bmad-output/planning-artifacts/arch-addendum-identity-invitation.md
    - docs/adr/auth-rbac-subsystem.md            # hermano — extendido, ya en main
    - docs/adr/rbac-authorization-model.md       # hermano — extendido, ya en main
    - _bmad-output/planning-artifacts/arch-addendum-auth-rbac.md
    - _bmad-output/planning-artifacts/arch-addendum-rbac-authorization-model.md
  ux:
    - _bmad-output/planning-artifacts/ux-designs/ux-ERPify-2026-07-06/DESIGN.md
    - _bmad-output/planning-artifacts/ux-designs/ux-ERPify-2026-07-06/EXPERIENCE.md
    - _bmad-output/planning-artifacts/ux-designs/ux-ERPify-2026-07-06/validation-report.md
    - _bmad-output/planning-artifacts/ux-designs/ux-ERPify-2026-07-06/review-security.md
    - _bmad-output/planning-artifacts/ux-designs/ux-ERPify-2026-07-06/review-a11y.md
    - _bmad-output/planning-artifacts/ux-designs/ux-ERPify-2026-07-06/review-editorial.md
    - _bmad-output/planning-artifacts/ux-designs/ux-ERPify-2026-07-06/review-rubric.md
  prd: []      # ausente por convención de proyecto (no hay PRD; requisitos = UX EXPERIENCE + ADR)
  epics: []    # ausentes para IAM
  stories: []  # ausentes para IAM
---

# Implementation Readiness Assessment Report

**Date:** 2026-07-07
**Project:** ERPify
**Feature:** IAM — ciclo de vida de identidad / invitación (PR #455, rama `docs/iam-identity-invitation-bvdn`)

---

## Paso 1 — Inventario de documentos

Los artefactos del PR #455 (ADR + addendum) viven **solo en el worktree** de la rama; el
checkout `main` no los tiene. Todas las lecturas se hacen contra la ruta del worktree.

| Tipo | Estado | Ficheros |
|------|--------|----------|
| **Architecture** | ✅ Presente | `docs/adr/identity-invitation-lifecycle.md` (D1–D12) · `arch-addendum-identity-invitation.md` (SI-10…15, PR-0…8, DAG; `frozen-ready`). Extiende hermanos en `main`: `auth-rbac-subsystem.md`, `rbac-authorization-model.md` + sus addenda. |
| **UX** | ✅ Presente | `ux-designs/ux-ERPify-2026-07-06/` — `DESIGN.md`, `EXPERIENCE.md`, reviews (a11y · security · editorial · rubric), `validation-report.md/html`. `status: final`. |
| **PRD** | ⚠️ Ausente (por convención) | No existe PRD en el repo. La fuente de requisitos del proyecto es el run UX `EXPERIENCE.md` + el ADR. |
| **Epics** | 🛑 Ausente para IAM | Los `epics-*.md` existentes son de otras features; ninguno cubre identity/invitation. |
| **Stories** | 🛑 Ausente para IAM | Sin historias IAM en `implementation-artifacts/`; `sprint-status.yaml` sin entradas IAM. |

**Duplicados (whole + sharded):** ninguno.

---

## Paso 2 — Análisis de requisitos (PRD-equivalente)

**No hay PRD.** La fuente de requisitos de esta feature es el conjunto: run UX `EXPERIENCE.md`
(4 máquinas de estado + 2 invariantes + journeys J1–J6/V1), ADR `identity-invitation-lifecycle.md`
(D1–D12), addendum (SI-10…15, PR-0…8, DAG) y `review-security.md` (12 findings, todas plegadas al ADR).
Extracción de FRs/NFRs desde ese cuerpo, con referencia de origen.

### Functional Requirements

| ID | Requisito | Origen |
|----|-----------|--------|
| **FR1** | Ciclo de vida de identidad `INVITED → ACTIVE ↔ SUSPENDED ↔ DEACTIVATED` (sin `PENDING`); `User.status` enum de 4 casos | EXPERIENCE M1 · ADR D3 |
| **FR2** | `User` nace `INVITED`, `HashedPassword` nullable hasta `ACTIVE`; membership + roles provisionados **antes** de aceptar | ADR D3 |
| **FR3** | Agregado `Invitation` (`CREATED → SENT → ACCEPTED\|REVOKED\|EXPIRED`), entidad separada del `User` | EXPERIENCE M2 · ADR D5 |
| **FR4** | Invitar (admin asigna rol) → email → B4 accept: fija contraseña, voltea `INVITED→ACTIVE`, acuña 1ª sesión | ADR D5 · J1 |
| **FR5** | Reenvío emite token nuevo e invalida el anterior; revoke→`REVOKED`; lapso TTL→`EXPIRED` | ADR D5 · J5 |
| **FR6** | Autenticación `Unlocked / LockedUntil(T)` — lock persistido tras N fallos, limpiado por reset/login/TTL | EXPERIENCE M3 · ADR D7 |
| **FR7** | `Session` registry server-side (`Active → Revoked\|Expired`), «dispositivo actual» distinguible, revoke una/todas-menos-actual/todas | EXPERIENCE M4 · ADR D8 |
| **FR8** | Session Admission Gate por request | ADR D8 |
| **FR9** | Reset revoca TODAS las sesiones; cambio en *Mi cuenta* revoca todas-menos-actual; suspend/deactivate revoca sesiones | ADR D8/D9 |
| **FR10** | A1 landing entry — único CTA login, sin «crear cuenta» | EXPERIENCE IA |
| **FR11** | B1 Login (estados idle/enviando/inválido/lockout/offline/error) | EXPERIENCE |
| **FR12** | B2 Forgot password — respuesta neutra uniforme para todo estado | EXPERIENCE · ADR D9 |
| **FR13** | B3 Reset password vía single-use token | EXPERIENCE · ADR D9 |
| **FR14** | B4 Accept invitation vía token | EXPERIENCE · ADR D5 |
| **FR15** | C1 Access walls (token-opaco + muros post-identidad SUSPENDED/LockedUntil/DEACTIVATED/sesión-expirada) | EXPERIENCE |
| **FR16** | **Retirar** el mock `/register` de alta libre (invitation-first) — retirada efectiva, no solo deprecada | EXPERIENCE IA · security [medium] |
| **FR17** | `Organization` + `Membership`; una org/instalación; CLI `ProvisionOrganization` + `CreateInitialAdministrator` | ADR D1/D2 · addendum PR-1 |
| **FR18** | Seam multi-tenant-ready (`organizationId` en todo agregado); tenancy operativa diferida a ADR propio | ADR D2 · SI-15 |
| **FR19** | `Shared/Token/SingleUseToken` (CSPRNG, hash-at-rest, verify constant-time, TTL, single-use) | ADR D6 · addendum PR-2 |
| **FR20** | `SecurityEmail` (invitación/reset/aviso-cambio) async Messenger, enlace HTTPS-only, escape de contenido dinámico, remitente no-`no-reply` + aviso «no compartas este enlace» | EXPERIENCE · addendum PR-8 · security [low] |
| **FR21** | Promoción `Backoffice/Identity → Iam/{Identity,Invitation,Session}` + nuevo `Organization/`; actualizar `security.yaml`, `deptrac.yaml` | ADR D1 · addendum PR-0 |

**Total FRs: 21**

### Non-Functional Requirements

| ID | Requisito | Origen |
|----|-----------|--------|
| **NFR-A** | **Invariante 1** — indistinguibilidad pre-identidad en **timing + status HTTP + shape** (hash dummy siempre, incl. `INVITED`/forgot) | SI-12 · ADR D10 |
| **NFR-B** | **Invariante 2** — opacidad del token: opaco/single-use/TTL/hash-at-rest; `Referrer-Policy: no-referrer` en pantallas token; strip de URL (`history.replaceState`); redacción en logs; un único mensaje por muerte de token | SI-13 · ADR D11 |
| **NFR-C** | Contrato de error graduado por confianza, RFC 9457; markers `invalid-token`/`account-locked`/`account-suspended`/DEACTIVATED-genérico/operacional-503; actualizar `api-error-contract.md` | SI-14 · ADR D12 |
| **NFR-D** | Session Admission Gate **fail-closed**, parte del TCB de auth | SI-11 · ADR D8 |
| **NFR-E** | Regla de los tres momentos — `SessionId` solo tras admisión (nunca `credenciales → sesión`) | SI-10 · ADR D4 |
| **NFR-F** | CSRF + regeneración de sesión en POST login **y** accept-invitation (anti-fixation) | ADR D5 · security [medium] |
| **NFR-G** | Rate-limit login/forgot/reset/accept por IP+cuenta sin romper neutralidad (mismo status/copy al saturar) | ADR D10 · security [medium] |
| **NFR-H** | Muro post-identidad **stateless** (sin sesión/cookie/token parcial, sin estado de cuenta en URL) | ADR D4 · security [medium] |
| **NFR-I** | WCAG 2.2 AA: gestión de foco (autofocus, focus-return-to-error, focus-to-`<h1>` en transiciones), orden natural (sin keyboard-trap en tarjetas no-modales, WCAG 2.1.2-A), semántica de encabezados, color no-canal-único, `aria-live` polite/assertive, reduced-motion, dark mode | Accessibility Floor · validation [high] ×2 |
| **NFR-J** | UX resiliente (no offline-first): loading claro, anti-doble-envío, sin pérdida de datos, reintento idempotente, error recuperable | Resiliencia |
| **NFR-K** | i18n español-primero, multi-idioma-ready, ninguna cadena hardcodeada (límites en Zod `.max()`, nunca `maxLength`) | Foundation |
| **NFR-L** | Aislamiento por-agregado (ningún object graph cruza módulo; referencia por id; registro deptrac); sin credenciales en migración (bootstrap CLI + Alice) | ADR D1 · Implementation |

**Total NFRs: 12**

### Requisitos / restricciones adicionales

- **Ortogonalidad de planos:** identidad ≠ autorización (RBAC, ADR hermano) ≠ tenancy (diferida). El
  `subject:` del voter RBAC sigue sin evaluar (ADR D2/D9-hermano).
- **Riesgo de secuenciación con RBAC:** el core RBAC (`PermissionVoter`+`AuthorizationPolicy`) se empaqueta
  hoy en `Backoffice/Identity/Infrastructure/Security`; PR-0 mueve ese destino a `Iam/…`. Orden (a) PR-0
  primero / (b) RBAC a `Backoffice` y PR-0 mueve ambos — **decisión de corte de épica**, aún abierta.
- **Deuda de wiring de mocks:** `/register` (alta libre) y el copy de `ResetPasswordForm` («…o ha
  expirado») **contradicen** los invariantes; deben retirarse/colapsar al cablear, no solo deprecarse.
- **Forward-path clause (D8):** almacenamiento de sesión estable para single-node; multi-node reabre un
  ADR nuevo (no drift automático a `PdoSessionHandler`).

### PRD Completeness Assessment

- **Cobertura de requisitos: alta.** El cuerpo UX+ADR+addendum es inusualmente completo y auto-consistente:
  las 4 findings [high] y las [medium] de seguridad de la validación UX están **plegadas** al ADR (D4–D12)
  y a los invariantes (SI-10…15); trazabilidad UX↔ADR verificable decisión a decisión.
- **Formato: no-PRD.** No hay FRs/NFRs numerados de origen; los de arriba son una **síntesis** para poder
  validar cobertura de épicas. No existe la lista canónica FR/NFR que este skill normalmente cotejaría.
- **Gap de método (crítico, se arrastra al Paso 3):** el objeto que este readiness debe auditar —
  **épicas e historias** — **no existe** para IAM. El siguiente paso BMAD registrado es
  `bmad-create-epics-and-stories`. Sin ese artefacto no hay trazabilidad FR/NFR→épica→historia que validar;
  el Paso 3 lo confirmará como hallazgo de cabecera.

---

## Paso 3 — Validación de cobertura de épicas

**Artefacto formal ausente:** no hay `epics-identity-invitation*.md` ni historias IAM → **cobertura
formal = 0 / 21 FR, 0 / 12 NFR**. Los `epics-*.md` presentes son de otras features.

En su lugar, valido contra la **descomposición PR-0…PR-8** del addendum (tabla «Localización de
decisiones por PR» + DAG) — el contrato de entrada que `bmad-create-epics-and-stories` consumirá. Mide
si el modelo es *dev-able* y dónde el corte tendrá huecos.

### Matriz de cobertura FR → descomposición PR (addendum)

| FR | Backend localizado | UI/Frontend | Estado |
|----|--------------------|-------------|--------|
| FR1 status enum | PR-3 | — | ✅ |
| FR2 nace INVITED | PR-3 | — | ✅ |
| FR3 Invitation agg. | PR-4 | — | ✅ |
| FR4 accept flow | PR-4 | **B4 UI ⚠** | 🟠 backend sí / UI sin PR |
| FR5 resend/revoke/expire | PR-4 | (backoffice, diferido) | ✅ backend |
| FR6 lockout | PR-6 | — | ✅ |
| FR7 Session registry | PR-7 | (gestión sesiones mín.) | ✅ backend |
| FR8 admission gate | PR-7 | — | ✅ |
| FR9 revoke semantics | PR-5 + PR-7 | — | ✅ |
| FR10 A1 landing entry | — | **⚠ sin PR** | 🔴 no localizado |
| FR11 B1 login estados | (login backend ya existe) | **⚠ sin PR** | 🟠 UI sin PR |
| FR12 B2 forgot | PR-5 | **UI ⚠** | 🟠 backend sí / UI sin PR |
| FR13 B3 reset | PR-5 | **UI ⚠** | 🟠 backend sí / UI sin PR |
| FR14 B4 accept | PR-4 | **UI ⚠** | 🟠 backend sí / UI sin PR |
| FR15 C1 access walls | PR-3 (tipos error) | **render muros ⚠** | 🟠 backend sí / UI sin PR |
| FR16 retirar /register | — | **⚠ solo en prosa** | 🔴 no localizado |
| FR17 Organization+Membership | PR-1 | — | ✅ |
| FR18 seam multi-tenant | PR-1 / SI-15 | — | ✅ |
| FR19 Shared/Token | PR-2 | — | ✅ |
| FR20 SecurityEmail | PR-8 | — | ✅ |
| FR21 promoción contexto | PR-0 | — | ✅ |

### Matriz de cobertura NFR → descomposición PR

| NFR | Localización | Estado |
|-----|--------------|--------|
| NFR-A indistinguibilidad (timing/status/shape) | PR-5 + PR-8 (barrido constant-time) | ✅ |
| NFR-B opacidad token | PR-4 (token) + PR-8 (hygiene) · **client URL-strip ⚠** | 🟠 backend sí / client sin PR |
| NFR-C error graduado RFC 9457 | PR-3 | ✅ |
| NFR-D gate fail-closed | PR-7 | ✅ |
| NFR-E tres momentos | PR-3 (UserChecker) | ✅ |
| NFR-F CSRF + regen sesión | PR-4 | ✅ |
| NFR-G rate-limit neutral | PR-8 | ✅ |
| NFR-H muro stateless | PR-3 | ✅ |
| NFR-I WCAG 2.2 AA / gestión foco | — | 🔴 no localizado (frontend transversal) |
| NFR-J UX resiliente | — | 🔴 no localizado (frontend transversal) |
| NFR-K i18n español-primero | — | 🔴 no localizado (frontend transversal) |
| NFR-L aislamiento agregado / no-seed | PR-0 + PR-1 | ✅ |

### Missing Requirements — hallazgo estructural

El backend está **completamente localizado** en PR-0…PR-8 (16 FR + 9 NFR con PR asignado, DAG coherente
safe-first). El hueco es sistemático y de un solo tipo:

- **🔴 GAP-1 · Toda la capa de integración frontend está sin localizar.** El addendum PR-0…8 es una
  descomposición **backend**. Pero EXPERIENCE marca B1/B2/B3/B4/C1 + A1 como **«Diseño completo — Fase 1»**
  y advierte que las 4 pantallas `(auth)` **existen como mocks** que hay que cablear al backend real.
  Ninguna PR del addendum asigna: cableado de las superficies públicas (FR10/11/12/13/14/15 lado UI),
  el **client-side token URL-strip** (`history.replaceState`, NFR-B), ni los transversales frontend
  **a11y (NFR-I) / resiliencia (NFR-J) / i18n (NFR-K)**. El corte de épica **debe** añadir historias PWA
  o este trabajo cae entre las grietas.
- **🔴 GAP-2 · FR16 (retirar `/register`) y la deuda de copy del mock viven solo en prosa.** EXPERIENCE y
  la review de seguridad ([medium]) insisten: la retirada debe ser **efectiva** (ruta eliminada/bloqueada),
  no deprecada, y el copy de `ResetPasswordForm` («…o ha expirado») debe **colapsar** a «Este enlace ya no
  es válido». Sin una historia explícita, «el invariante nace roto» al cablear.
- **🟠 OPEN-1 · Decisión de secuenciación con RBAC no resuelta.** PR-0 mueve el destino del core RBAC. El
  addendum lo deja explícitamente como *«decisión de orden a fijar al cortar épicas»* (a: PR-0 primero /
  b: RBAC a `Backoffice` y PR-0 mueve ambos). El corte de épica **debe** resolverlo — hoy está abierto.
  Interacción viva: **PR #456 (RM-1 RBAC) está OPEN** y aterriza el core en `Backoffice/Identity/Infrastructure/Security`;
  si se mergea antes de PR-0, gana la rama (b).
- **🟢 Fuera de alcance correcto:** enforcement del `subject:` del voter (tenancy), org self-signup,
  multi-sesión detallada, magic-link/MFA/SSO — diferidos por diseño (ADR D2, EXPERIENCE § Frontera).

### Coverage Statistics

- **Total FR: 21 · NFR: 12.**
- **Cobertura en épicas/historias formales: 0 % (artefacto inexistente).**
- **Localización en descomposición PR-0…8 (backend):** 16 / 21 FR y 9 / 12 NFR con PR asignado y DAG.
- **Sin localizar en ninguna PR (capa frontend + retirada mock):** FR10, FR16 (🔴) + lado-UI de
  FR11/12/13/14/15 (🟠) + NFR-I/J/K (🔴) + client-side de NFR-B (🟠).
- **Veredicto de cobertura:** el modelo es **dev-able en backend**; el corte de épica debe **cerrar el
  plano frontend (GAP-1/GAP-2) y la decisión OPEN-1** antes de considerarse listo.

---

## Paso 4 — Alineación UX

### UX Document Status

**Encontrado · `status: final`** — `ux-ERPify-2026-07-06` (DESIGN + EXPERIENCE + 4 reviews +
validation-report). Validado con 4 lentes: **0 críticas, 0 high de cobertura**; las **4 findings [high]**
adversariales (2 seguridad + 2 a11y) y las [medium] **plegadas** aguas abajo. No hay PRD, así que la
alineación relevante es **UX ↔ Arquitectura (ADR+addendum)**.

### Alineación UX ↔ Arquitectura — crosswalk

El ADR **realiza** el modelo UX decisión a decisión (fue destilado de él). Trazabilidad directa:

| Contrato UX (EXPERIENCE) | Realización arquitectura | Estado |
|--------------------------|--------------------------|--------|
| Máquina 1 Identity (`INVITED→ACTIVE↔SUSPENDED↔DEACTIVATED`, sin PENDING) | ADR D3 | ✅ |
| Máquina 2 Invitation (entidad propia) | ADR D5 | ✅ |
| Máquina 3 Auth `Unlocked/LockedUntil` | ADR D7 | ✅ |
| Máquina 4 Session `Active→Revoked/Expired` + dispositivo actual | ADR D8 | ✅ |
| Invariante 1 (indistinguibilidad) | ADR D10 · SI-12 | ✅ |
| Invariante 2 (opacidad token) | ADR D11 · SI-13 | ✅ |
| Regla de los tres momentos | ADR D4 · SI-10 | ✅ |
| D-a muro siempre ofrece «Iniciar sesión» | *(comportamiento frontend — sin home backend)* | 🟠 frontend |
| D-b reset limpia `LockedUntil` | ADR D9 + D7 | ✅ |
| D-c sesión solo para `ACTIVE` (muro stateless) | ADR D4 | ✅ |
| Reset revoca TODAS; Mi-cuenta revoca todas-menos-actual | ADR D9 · D8 | ✅ |
| CSRF + regen de sesión (login + accept) | ADR D5 · NFR-F | ✅ |
| Forgot uniforme para todo estado | ADR D9 | ✅ |
| Contrato `{SecurityEmail}` (HTTPS, escape, no-`no-reply`, «no compartas») | addendum PR-8 | ✅ |
| Seam multi-tenant (invitación org-scoped, sin UI tenant) | ADR D2 · SI-15 | ✅ |

**Veredicto de alineación: excepcionalmente fuerte en el plano backend.** No encuentro **ninguna**
decisión de arquitectura que **contradiga** la espina; el ADR cierra las findings [high]/[medium] de
seguridad que la propia validación UX marcó (timing/status/shape, token-en-URL, CSRF+fixation,
revoca-todo-en-reset, muro stateless, forgot uniforme).

### Alignment Issues / Warnings

- **⚠️ GAP-1 (confirmado desde UX) — el contrato *frontend* de la UX no tiene arquitectura que lo sirva.**
  EXPERIENCE especifica como **Fase 1 completa**: las superficies públicas B1/B2/B3/B4/C1 + A1, y
  transversales **a11y WCAG 2.2 AA** (gestión de foco, no keyboard-trap, semántica `<h1>`, `aria-live`),
  **resiliencia de conectividad** (anti-doble-envío, sin pérdida de datos, reintento idempotente),
  **i18n**, y comportamientos UI propios (**D-a**, campo único de contraseña revelado-por-defecto,
  focus-return-to-error, **client-side token URL-strip**). El ADR+addendum son **backend**: nada de esto
  tiene PR asignada. La arquitectura **no da cuenta de las necesidades UX del lado cliente** — hay que
  cortar historias PWA o el plano frontend de la UX queda sin realizar.
- **⚠️ GAP-2 (confirmado desde UX) — deuda de mocks que la UX marca y la arquitectura no posee.**
  EXPERIENCE §Foundation y security [medium/low·carry-over]: `/register` (alta libre) debe **retirarse
  efectivamente** y el copy de `ResetPasswordForm` **colapsar** a «Este enlace ya no es válido». La UX lo
  declara deuda a corregir *al cablear*; ninguna PR del addendum la posee.
- **🟢 Nota [low] — mockups no renderizados.** `validation-report.md` marca «Visual reference coverage —
  strong (mocks aún no renderizados — paso Finalize pendiente)». La espina de **comportamiento** es
  autoritativa para el backend, pero los HTML de mockup del run de acceso no se generaron (a diferencia
  del run de audit 2026-06-26, que sí los tiene). Impacto bajo: no bloquea el corte de backend; sí
  conviene para las historias de UI.

---

## Paso 5 — Revisión de calidad de épicas

**No hay épicas/historias formales.** Aplico el estándar de `bmad-create-epics-and-stories` **hacia
adelante**, sobre la descomposición **PR-0…PR-8 + DAG** del addendum (el contrato que el corte heredará),
para anticipar violaciones que un mapeo mecánico PR-N→historia produciría.

### Lo que el proto-épica hace BIEN (no inventar defectos)

- **DAG acíclico y orden safe-first** ya calculado (aditivo/estructural → comportamiento → superficie
  pública). El análisis de dependencias — donde estos cortes suelen fallar — **ya está hecho**.
- **Creación de esquema por-historia, no big-bang.** PR-1 `Organization`/`Membership`, PR-3 `User.status`,
  PR-4 `Invitation`, PR-6 columnas lockout, PR-7 `Session`. Cumple «cada historia crea las tablas que
  necesita». ✅
- **Trazabilidad a decisiones** (cada PR → D-numbers del ADR) explícita.

### 🔴 Violaciones críticas (riesgo estructural que el corte arrastrará)

- **C1 · Un épica backend-only NO entrega valor de usuario end-to-end.** PR-0…8 son **todas backend**. Un
  usuario no puede *aceptar una invitación* ni *restablecer contraseña* sin las superficies PWA (GAP-1).
  Si el épica se corta del addendum tal cual, es una **«épica técnica» disfrazada**: entrega capacidad
  backend, no un *outcome de usuario*. **Remediación:** cada capacidad de cara al usuario (accept, reset,
  forgot, login-walls) debe entregarse como **slice vertical** (backend + UI), o el épica debe declarar
  explícitamente que abarca ambos planos. Un «FR14 accept» con solo PR-4 y sin historia de B4 **no está
  *done*** para el usuario.
- **C2 · Acoplamiento cross-épica con RBAC sin resolver (OPEN-1).** PR-0 mueve el destino del core RBAC
  (`…/Infrastructure/Security`); el **épica RBAC ya está cortada** y **PR #456 (RM-1) está OPEN** apuntando
  a `Backoffice/Identity`. Dependencia cross-épica que **debe** secuenciarse al cortar (a: PR-0 primero /
  b: RBAC a `Backoffice` y PR-0 mueve ambos). Sin decidir → mina de orden-de-merge; es lo único que rompe
  la independencia entre épicas.

### 🟠 Problemas mayores

- **M1 · Varias PR son historias-habilitadoras técnicas sin valor de usuario aislado** (PR-0 promoción
  move/rename, PR-2 `Shared/Token`, PR-3 status+checker, PR-7 registry+gate). Legítimas como *historias*
  dentro de **un único épica de valor**, pero serían **épicas técnicas** si se cortan como épicas separadas.
  El corte debe ser **UN épica «Identity & Invitation lifecycle»**, no 9.
- **M2 · No existen criterios de aceptación, y los invariantes de seguridad son los que pasan
  silenciosamente sin AC testables.** NFR-A (tiempo constante), NFR-D (gate fail-closed), NFR-F
  (CSRF+regen), FR9/NFR-8 (reset revoca TODAS), NFR-H (muro stateless) son **comportamientos verificables**
  (Behat/integración). El corte **debe** adjuntar ACs Given/When/Then a cada invariante — no dejarlos en
  prosa, o no se probarán. (La casa ya tiene contextos Behat de evento/outbox/log y budget de queries.)
- **M3 · Radio de impacto de PR-0.** Mover código shipped (`User`, `SecurityUser`, provider, authenticator,
  `SecurityActorContextFactory`, `security.yaml`, `deptrac.yaml`) en una historia es alto riesgo. ACs duros:
  **sin cambio de comportamiento**, `deptrac` verde, Behat de auth verde; aterrizar **primero y sola**.

### 🟡 Concerns menores

- **m1 · Trazabilidad FR/NFR→historia aún no presente** (la tabla PR traza a D-numbers). El corte debería
  arrastrar el mapa FR/NFR de este informe.
- **m2 · PR-8 empaqueta tres concerns** (barrido constant-time · higiene `Referrer`/strip/log-redaction ·
  `SecurityEmail` async). Probable split en 2–3 historias.

### Checklist best-practices (proyectado sobre PR-0…8)

| Criterio | Veredicto |
|----------|-----------|
| Épica entrega valor de usuario | 🔴 solo si incluye frontend (C1) |
| Épica funciona independiente | 🟠 acoplada a RBAC vía PR-0 (C2) |
| Historias bien dimensionadas | 🟠 PR-0 y PR-8 a vigilar (M3, m2) |
| Sin forward dependencies | ✅ DAG acíclico safe-first |
| Tablas creadas cuando se necesitan | ✅ por-historia, no big-bang |
| Criterios de aceptación claros | 🔴 inexistentes aún (M2) |
| Trazabilidad a FRs | 🟡 a D-numbers sí, a FR/NFR no (m1) |

---

## Summary and Recommendations

### Overall Readiness Status

**NEEDS WORK** — desglosado en dos veredictos porque miden cosas distintas:

- **Fundación (ADR + addendum + UX como input al corte de épicas): ✅ READY.** Es de lo más sólido
  que he revisado en este repo: modelo de dominio estable (4 máquinas + 2 invariantes resisten 6
  journeys + V1 sin estado nuevo), ADR destilado del UX **con las findings de seguridad plegadas**,
  descomposición backend PR-0…8 *dev-able* con DAG acíclico safe-first y esquema por-historia.
- **Implementación (¿puede arrancar Fase 4?): 🛑 NOT READY.** El artefacto que este gate audita —
  **épicas e historias** — **no existe** para IAM. No se implementa sin él. Y cuando se cree, debe
  cerrar 3 huecos estructurales que el corte, hecho a la ligera, arrastraría.

### Critical Issues Requiring Immediate Action

1. **No hay épicas/historias IAM.** Bloqueante absoluto para Fase 4. El siguiente paso registrado
   (`bmad-create-epics-and-stories`) es exactamente esto.
2. **GAP-1 · El plano frontend no tiene arquitectura ni PR.** PR-0…8 son 100% backend; las superficies
   públicas B1/B2/B3/B4/C1+A1 (Fase 1 «diseño completo» en UX, hoy **mocks**), el client-side token
   URL-strip y los transversales a11y/resiliencia/i18n **no están localizados**. Un épica backend-only
   **no entrega valor de usuario end-to-end** (C1).
3. **GAP-2 · Retirada de `/register` + colapso del copy de reset viven solo en prosa.** Deben ser
   historias explícitas o «el invariante nace roto» al cablear.
4. **OPEN-1 / C2 · Secuenciación con RBAC sin decidir.** PR-0 mueve el destino del core RBAC; **PR #456
   (RM-1) está OPEN** apuntando a la ubicación vieja. El orden de merge hay que fijarlo **antes** de cortar.

### Recommended Next Steps

1. **Ejecutar `bmad-create-epics-and-stories`** sobre el ADR + addendum + UX (su contrato de entrada).
   Es el desbloqueo, no un rodeo.
2. **Al cortar, incluir un track de historias PWA (cierra GAP-1):** cablear cada superficie pública al
   backend real como **slice vertical** (la historia de «aceptar invitación» lleva PR-4 **y** B4), más
   a11y WCAG 2.2 AA, resiliencia de conectividad, i18n y el token URL-strip cliente.
3. **Historias explícitas para GAP-2:** retirar efectivamente `/register` (ruta eliminada/bloqueada) y
   colapsar el copy de `ResetPasswordForm` a «Este enlace ya no es válido».
4. **Resolver OPEN-1 en el corte:** decidir (a) PR-0 primero / (b) RBAC a `Backoffice` y PR-0 mueve ambos,
   y alinear con el merge de PR #456.
5. **Higiene de corte:** UN único épica de valor (no 9 técnicas · M1); **AC Given/When/Then testables por
   invariante de seguridad** (constant-time, gate fail-closed, CSRF+regen, reset-revoca-todo, muro
   stateless · M2, con Behat); PR-0 como historia primera, aislada, sin cambio de comportamiento (M3);
   arrastrar el mapa FR/NFR de este informe (m1); considerar split de PR-8 (m2).
6. **Opcional (low):** renderizar los mockups HTML del run de acceso para las historias de UI.

### Final Note

Esta evaluación encontró **4 issues críticos, 3 mayores y 3 menores** across 5 categorías (descubrimiento,
requisitos, cobertura, alineación UX, calidad de épica). El patrón dominante no es debilidad de diseño —
la fundación es fuerte — sino un **artefacto ausente** (épicas/historias) y un **plano no localizado**
(frontend). Aborda los 4 críticos **dentro del** `bmad-create-epics-and-stories`; no requieren rehacer el
ADR ni el UX. Puedes proceder al corte tomando este informe como checklist de entrada.

---

**Assessor:** PM readiness review (BMAD `check-implementation-readiness`)
**Date:** 2026-07-07 · **Branch:** `docs/iam-identity-invitation-bvdn` · **PR:** #455
