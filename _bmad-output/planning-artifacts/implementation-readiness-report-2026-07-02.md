---
stepsCompleted: ['step-01-document-discovery', 'step-02-prd-analysis', 'step-03-epic-coverage-validation', 'step-04-ux-alignment', 'step-05-epic-quality-review', 'step-06-final-assessment']
status: complete
overallReadiness: READY (con 2 correcciones de consistencia antes de create-story)
project: ERPify
scope: 'PR #414 — auth/RBAC architecture (ADR + addendum) + auth-foundation epic (AF-1.1/1.2/1.3)'
prBranch: 'feat/api-auth-rbac-architecture-hfql'
documentsUnderReview:
  - _bmad-output/planning-artifacts/arch-addendum-auth-rbac.md
  - docs/adr/auth-rbac-subsystem.md
  - _bmad-output/planning-artifacts/epics-auth-foundation.md
  - _bmad-output/planning-artifacts/epics-regulatory-audit-trail.md
  - _bmad-output/planning-artifacts/epics.md
---

# Implementation Readiness Assessment Report

**Date:** 2026-07-02
**Project:** ERPify
**Alcance evaluado:** PR #414 — arquitectura de auth/RBAC (ADR + addendum) + desglose de la épica *auth-foundation* (AF-1.1 / AF-1.2 / AF-1.3)
**Rama del PR:** `feat/api-auth-rbac-architecture-hfql` (OPEN, no fusionado a `main`)

---

## Paso 1 — Inventario de Documentos

### Arquitectura
- `_bmad-output/planning-artifacts/arch-addendum-auth-rbac.md` (38 líneas) — addendum de arquitectura *scoped*.
- `docs/adr/auth-rbac-subsystem.md` (68 líneas) — ADR con las decisiones.

### Épicas
- `_bmad-output/planning-artifacts/epics-auth-foundation.md` (189 líneas) — **épica primaria** (AF-1.1/1.2/1.3).
- `_bmad-output/planning-artifacts/epics-regulatory-audit-trail.md` (499 líneas) — épica de auditoría (RBAC es su prerrequisito; modificada en el PR).
- `_bmad-output/planning-artifacts/epics.md` (519 líneas) — épicas maestras.

### PRD
- No existe PRD formal. El proyecto usa el **método de addendum de arquitectura *scoped*** (ausencia por diseño, confirmada con el usuario).

### UX
- `_bmad-output/planning-artifacts/ux-designs/ux-ERPify-2026-06-26/` — corresponde a **auditoría (Epic 4)**, no a auth. `auth-foundation` es infraestructura backend → sin superficie UX (a confirmar en el paso de alineación UX).

### Historias
- Ninguna creada aún. AF-1.1/1.2/1.3 definidas dentro de la épica; el *gate* es previo a `create-story AF-1.1`.

### Incidencias
- Sin duplicados (no hay conflicto "whole + sharded").
- PRD ausente → por diseño (método addendum).
- UX existente fuera de alcance (auditoría, no auth).

---

## Paso 2 — Análisis de Requisitos (sustituto de PRD)

**No hay PRD formal.** El proyecto usa el método *addendum contract-first scoped*: las fuentes autoritativas de requisitos son el **ADR `auth-rbac-subsystem.md` (D1–D7)**, los **invariantes del addendum (SI-1–SI-4)** y el **inventario de requisitos de la propia épica**. Los tres coinciden y se trazan entre sí.

### Requisitos Funcionales (alcance auth-foundation / PR-0)

- **FR1** — Firewall de sesión httpOnly: instalar `symfony/security-bundle`, firewall con `json_login` + cookie de sesión httpOnly; login por credenciales establece sesión; **sin JWT en cliente**. *(← ADR D1)*
- **FR2** — Protección CSRF para rutas mutantes bajo sesión: `SameSite=Lax/Strict` + same-origin y, para no-GET, token CSRF o comprobación de cabecera. *(← ADR D1)*
- **FR3** — Agregado `User` en `Backoffice/Identity`: id UUID v7, email (identificador), VO `HashedPassword`, roles de dominio; agregado **libre de framework**. *(← ADR D2)*
- **FR4** — Adapter de seguridad: `Infrastructure/Security/SecurityUser` implementa `UserInterface` y envuelve al `User`; `UserProvider` por repositorio; authenticator + **hashing en Infrastructure** (`PasswordHasherInterface`). *(← ADR D2)*
- **FR5** — Modelo de roles: enum de dominio `Role`; el adapter emite `->value` como `ROLE_*` en `getRoles()`. *(← ADR D3)*
- **FR6** — Baseline de control de acceso: `access_control` **default-deny** + allowlist explícita de rutas públicas; request no autenticada a ruta protegida → **401 RFC 9457** (marker `Unauthenticated`), nunca `JsonResponse` manual. *(← ADR D1 + SI-3/SI-4)*

**Total FR (en alcance): 6.**

**Requisitos funcionales *aguas abajo* (Epic 3, FUERA de esta épica, la desbloquean):**
- **FR13** (E3) — lectura del trail restringida por RBAC (voter). *(← ADR D4)*
- **FR14** (E3) — auto-auditoría de la lectura concedida (listener durable). *(← ADR D7)*
- **FR15** (E3, reformulado) — atribución real vía `ActorContextFactory` → `forUser`; `actor_id` **permanece nullable**. *(← ADR D5/D6)*

### Requisitos No Funcionales (alcance auth-foundation)

- **NFR1** — Aislamiento de capas/contextos: Symfony Security confinado a `Infrastructure/`; `Backoffice/Identity/Domain` libre de framework; `php.deptrac` + `php.lint.bounded-context` verdes. *(← ADR D2, SI-2)*
- **NFR2** — Errores por el contrato RFC 9457 (401/403), jamás `JsonResponse` manual; `php.lint.error-contract` verde. *(← SI-4)*
- **NFR3** — Sin ensanchar superficie: no ampliar CORS/CSRF ni política Mercure; JWT-cookie de Mercure intacto y ortogonal. *(← ADR D1)*
- **NFR4** — Migración segura: reversible (`down()`), sin sembrar PII/secretos; hard-delete de `User` mantiene satisfacible el borrado GDPR. *(← `docs/rules/database.md`)*
- **NFR5** — Cero retrabajo del eje de auditoría: **no** modificar `ActorContextFactory` ni esquema/bus/storage del trail. *(← SI-1, NFR2 del hermano)*

**Total NFR (en alcance): 5.**

### Requisitos / restricciones adicionales

- **Contexto temporal:** app **no en producción**, greenfield sin auth hoy (sin `security.yaml`, sin `SecurityBundle`, sin `User`, sin voter) → sin restricciones de retrocompatibilidad.
- **`actor_id` permanece nullable** (D5): "atribución real" es invariante de *costura* (`ActorContextFactory`), no constraint de esquema → **no hay migración del trail**.
- **`ActorContextFactory` = costura única de identidad** (SI-1/D6): ningún writer/handler lee el token directamente.
- **`ActorType::API_KEY`** se conserva modelado para una futura vía M2M, **sin construirla ahora** (YAGNI).
- **JWT de Mercure** ortogonal — no se toca.
- **Gate de producción** (SI-3/D8 hermano): trail + ruta #377 no llegan a prod hasta voter (D4) + auto-auditoría (D7) en vigor → cierre en E3 Story 3.3, **no** en esta épica.

### Valoración de completitud (fuentes de requisitos)

- ✅ ADR completo: 7 decisiones (D1–D7), cada una con alternativa descartada y *why*; sección de "load-bearing implementation challenges" e "Implementation" con orden por dependencia.
- ✅ Addendum: 4 invariantes globales, tabla de localización de decisiones por PR, DAG de dependencias.
- ✅ La épica inventaría FR1–FR6 / NFR1–NFR5 y los mapea a decisiones ADR y a stories.
- ⚠️ **Ambigüedad de numeración a vigilar** (paso 5): la fundación se numera **Story 1.1/1.2/1.3** en la épica, pero el ADR/addendum la llaman **"PR-0"** y `sprint-status.yaml`/memoria la llaman **AF-1.1/1.2/1.3**; las stories *aguas abajo* son **3.1/3.2/3.3** (Epic 3). No es un hueco de contenido, sí un riesgo de trazabilidad.

---

## Paso 3 — Validación de Cobertura de Épicas

### Matriz de cobertura — FR en alcance (auth-foundation)

| FR | Requisito | Cobertura en épica | Estado |
|----|-----------|--------------------|--------|
| FR1 | Firewall de sesión httpOnly | Story 1.2 (AC 1) | ✅ Cubierto |
| FR2 | Protección CSRF | Story 1.2 (AC 4) | ✅ Cubierto |
| FR3 | Agregado `User` (framework-free) + persistencia | Story 1.1 (AC 1–2) | ✅ Cubierto |
| FR4 | Adapter `SecurityUser`/provider/authenticator + hashing en Infra | Story 1.2 (AC 2) | ✅ Cubierto |
| FR5 | Modelo de roles (enum → `ROLE_*`) | Story 1.1 (enum) + Story 1.2 (mapeo adapter) | ✅ Cubierto |
| FR6 | Baseline default-deny + 401 por el pipeline | Story 1.3 (AC 1–2) | ✅ Cubierto |

### Matriz de cobertura — NFR en alcance

| NFR | Requisito | Cobertura | Estado |
|-----|-----------|-----------|--------|
| NFR1 | Aislamiento capas/contextos (deptrac + bounded-context) | Story 1.1 (AC 3), transversal | ✅ Cubierto |
| NFR2 | Errores por contrato RFC 9457 | Story 1.2 (AC 1) + Story 1.3 (AC 2) | ✅ Cubierto |
| NFR3 | Sin ensanchar CORS/CSRF/Mercure | Story 1.2 (AC 4) | ✅ Cubierto |
| NFR4 | Migración segura (reversible, sin PII/secretos, GDPR) | Story 1.1 (AC 2) | ✅ Cubierto |
| NFR5 | Cero retrabajo del eje de auditoría | Frontera explícita (Story 1.3 AC 3), transversal | ✅ Cubierto |

### Trazabilidad ADR → story (ninguna decisión huérfana)

| Decisión ADR | FR | Story |
|--------------|----|----|
| D1 (firewall sesión + CSRF) | FR1, FR2, FR6 | 1.2, 1.3 |
| D2 (`User` puro + adapter) | FR3, FR4 | 1.1, 1.2 |
| D3 (roles enum → `ROLE_*`) | FR5 | 1.1, 1.2 |
| D4 (voter `#[IsGranted]`) | FR13 | **E3** Story 3.1 (diferido) |
| D5 (`actor_id` nullable) | FR15 | **E3** Story 3.1 (diferido) |
| D6 (`ActorContextFactory` seam) | FR15 | **E3** Story 3.1 (diferido) |
| D7 (self-audit lectura concedida) | FR14 | **E3** Story 3.2 (diferido) |

### FR aguas abajo (desbloqueados por esta épica) — ruta trazable confirmada

- **FR13 / FR14 / FR15** → **cubiertos** en `epics-regulatory-audit-trail.md`, Epic 3, Stories **3.1 / 3.2 / 3.3** (verificado: líneas 437/455/471). No son responsabilidad de esta épica; su ruta existe y el DAG los ordena tras auth-foundation. **Sin hueco de cobertura.**

### Estadísticas de cobertura

- **FR en alcance:** 6 · cubiertos **6** · **100 %**.
- **NFR en alcance:** 5 · cubiertos **5** · **100 %**.
- **Decisiones ADR (D1–D7):** 7 · con story asignada **7** (D1–D3 en esta épica, D4–D7 diferidas a E3) · **0 huérfanas**.
- **FR aguas abajo (FR13–FR15):** ruta trazable en E3 · **sin hueco**.

**Sin requisitos sin cobertura.** La única observación es de *trazabilidad de numeración* (dos espacios de numeración de "D" — ADR auth D1–D7 vs ADR audit D1–D9 — y triple etiquetado de la fundación: Story 1.x / PR-0 / AF-1.x), no de contenido faltante. Se detalla en el Paso 5.

---

## Paso 4 — Alineación UX

### Estado del documento UX
- **No encontrado (para auth).** En la rama del PR no existe ningún artefacto UX (`ux-designs/` vacío en el worktree; el UX de `ux-ERPify-2026-06-26` es de **auditoría / Epic 4** y vive sólo en `main`, sin trackear).
- La épica `auth-foundation` **no toca `pwa/` en absoluto** — verificado: cero referencias a PWA/Next/React/pantalla/formulario. Es una épica **100 % backend** (`api/`).

### ¿UX implícita?
- **Parcialmente, pero fuera de alcance por diseño.** ADR D1 usa `json_login` "para el login de la PWA" → implica una **pantalla de login en la PWA** para el uso final por un humano. Esa UI **no** está en esta épica ni tiene story.
- Para el **objetivo de esta épica** (construir el subsistema auth que *desbloquea* E3 y permite levantar el gate de la #377), no se requiere UX: `json_login` es un endpoint JSON verificable por `curl`/Behat con un usuario sembrado. El "seed del primer usuario" ya está listado como decisión operativa abierta en la épica.

### Problemas de alineación
- **Ninguno.** No hay desalineación UX↔PRD↔Arquitectura. Al contrario: la arquitectura es *UX-aware* sin doc UX — elige sesión httpOnly **precisamente** por la regla de `pwa/CLAUDE.md` (no tokens en cliente), anticipando el futuro login same-origin de la PWA.

### Advertencias
- ⚠️ **(Baja severidad) Login de la PWA implícito, sin documentar ni cortar como story.** El sistema no será *usable por un humano vía navegador* hasta que exista una story de pantalla de login en `pwa/`. **No bloquea** esta épica (backend, prerequisito de E3, verificable por endpoint + usuario sembrado), pero conviene **registrarlo como follow-up explícito** (issue o story PWA) para que no sea un hueco silencioso. Recomendado: crear el follow-up al cerrar la épica, encadenado tras la Story 1.2 (firewall `json_login` en vigor).

---

## Paso 5 — Revisión de Calidad de Épica

### Checklist de mejores prácticas (épica auth-foundation)

| Criterio | Veredicto |
|----------|-----------|
| Épica entrega valor de usuario | ⚠️ Fundación **técnica** (desviación consciente y argumentada — ver m2) |
| Épica funciona de forma independiente (sin dependencia hacia adelante) | ✅ Sí — es el prerequisito; nada aguas arriba |
| Stories bien dimensionadas | ✅ Sí (1.1 dominio+persistencia · 1.2 firewall+adapter · 1.3 baseline) |
| Sin dependencias hacia adelante | ✅ DAG lineal 1.1 → 1.2 → 1.3, sólo hacia atrás |
| Tablas creadas cuando se necesitan | ✅ Una migración (`users`) en la Story 1.1, no upfront |
| Criterios de aceptación claros (Given/When/Then) | ✅ Sí (2 ACs con decisión abierta — ver m3) |
| Trazabilidad a FRs mantenida | ✅ Mapa de cobertura + tabla ADR→story explícitos |
| Punto de integración brownfield tratado | ✅ Frontera E3 explícita (SI-1, "no toca `ActorContextFactory`") |
| Secuenciación en `sprint-status.yaml` | ✅ `epic-auth-foundation` antes de `epic-3`; E3 re-gated "on auth-foundation" |

### 🔴 Violaciones críticas
- **Ninguna.** No hay épica-técnica-sin-argumentar, ni dependencia hacia adelante, ni story de tamaño-épica incompletable.

### 🟠 Problemas mayores

- **M1 — Slug de story E3 contradice la decisión D5 del ADR.** En `sprint-status.yaml` la story E3 es
  `3-1-voter-rbac-rutas-lectura-trail-**actor-id-not-null**`, pero **ADR D5 ratifica que `actor_id` permanece
  `nullable`** ("real attribution is a seam invariant, not a schema constraint"; D5 = D9 tier-1 del hermano) y
  el addendum lo repite ("columna `actor_id` intacta (nullable)"). El slug codifica el encuadre **descartado**
  (NOT NULL) y engañará a quien implemente la Story 3.1. *Es alcance E3, pero **este PR** es justo el que
  reformula FR15/D5*, así que es el sitio natural para corregirlo.
  **Recomendación:** renombrar la clave a algo como `3-1-voter-rbac-rutas-lectura-trail-atribucion-real` (o
  `...-actor-id-nullable`) en `sprint-status.yaml`, y verificar que la línea FR15 del épic de E3 no repita
  "NOT NULL". No bloquea auth-foundation; **sí** conviene cerrarlo antes de crear las stories de E3.

### 🟡 Preocupaciones menores

- **m1 — Triple esquema de ID para las mismas 3 stories.** El épic las titula **"Story 1.1/1.2/1.3"**,
  `sprint-status.yaml` las registra como **`af-1`/`af-2`/`af-3`**, la memoria/Sergio las llama **"AF-1.1/1.2/1.3"**,
  y el ADR/addendum las agrupan como **"PR-0"**. `create-story` lee *tanto* el épic *como* `sprint-status.yaml`;
  un ID inconsistente puede mapear mal o duplicar. **Recomendación:** fijar **un** esquema canónico (sugiero
  `AF-1.1/1.2/1.3` para alinear con el `X.Y` del resto de épicas) y hacer coincidir los encabezados del épic
  con las claves de `sprint-status.yaml` **antes** de `create-story AF-1.1`.

- **m2 — Épica de fundación técnica (sin valor de usuario *standalone*).** Ninguna de las 3 stories entrega,
  por sí sola, algo visible para el usuario final (tabla `users` → login endpoint → default-deny). Por el
  estándar estricto BMAD sería un "technical milestone". **Se acepta como desviación consciente y argumentada:**
  auth greenfield no admite rebanado vertical *user-facing* sin antes el modelo de identidad + firewall; el
  proyecto adopta el método *scoped-addendum* (prerequisito ordenado por DAG); y el valor real —lectura del
  trail RBAC-gated (ISO A.5.18/8.15) + levantar el gate de la #377— está **explícitamente encadenado** a E3.
  **No se recomienda** forzarla a una forma *user-facing* artificial. Única sugerencia: mantener el valor
  aguas abajo visible en el *goal* de la épica (ya lo está).

- **m3 — Dos ACs con decisión abierta embebida.** Story 1.2 AC4 (mecanismo CSRF concreto: "token o
  comprobación de cabecera") y Story 1.3 AC2 (puente `AuthenticationException`→401 "si falta, se añade")
  contienen una decisión aún no tomada. La épica **acierta** al aflorarlas en "Riesgos / decisiones abiertas"
  y asignarlas a la story que las cierra — pero el AC no es plenamente *específico/testable* hasta decidirlas.
  **Recomendación:** confirmar que se cierran dentro de su story y que, si se añade el puente 401, se actualiza
  `docs/api-error-contract.md` en el mismo PR (NFR26, ya anotado en el riesgo).

- **m4 — Dos espacios de numeración "D" conviviendo.** ADR auth (`D1–D7`) vs ADR audit (`D1–D9`) se
  referencian cruzados en los mismos documentos. Los cruces están cualificados ("D8 **del hermano**"), así que
  el riesgo es bajo; es higiene documental. Sin acción obligatoria.

### Balance
Estructura de épica **sólida**: independiente, DAG lineal sin dependencias hacia adelante, migración *just-in-time*, ACs BDD trazables a FRs, frontera brownfield con E3 bien dibujada. Los hallazgos son de **trazabilidad/consistencia entre artefactos** (M1, m1), no de contenido o diseño faltante. Nada bloquea la implementación de auth-foundation; M1 y m1 deben resolverse **antes de `create-story`** para no arrastrar la inconsistencia a los ficheros de story.

---

## Resumen y Recomendaciones

### Estado global de preparación

**✅ LISTO PARA IMPLEMENTAR** la épica *auth-foundation* — condicionado a **una** corrección barata antes de `create-story` (m1) y una corrección oportunista en el mismo PR (M1).

- **Cobertura de requisitos:** 100 % (FR1–FR6, NFR1–NFR5). FR aguas abajo (FR13–FR15) con ruta trazable en E3. **0 huecos.**
- **Alineación de decisiones:** las 7 decisiones del ADR (D1–D7) tienen story asignada. **0 huérfanas.**
- **Calidad de épica:** sin violaciones críticas; DAG lineal, sin dependencias hacia adelante.
- **Total hallazgos:** 1 mayor · 4 menores · 1 advertencia UX. Ninguno bloquea la implementación de las stories de auth-foundation.

### Cuestiones que requieren acción antes de continuar

1. **(m1 — antes de `create-story`)** Reconciliar el **esquema de ID de story**. Hoy conviven "Story 1.1/1.2/1.3" (épic), `af-1/af-2/af-3` (`sprint-status.yaml`) y "AF-1.1/1.2/1.3" (memoria/Sergio). `create-story` lee ambos ficheros. Fijar **uno** canónico (sugerido `AF-1.1/1.2/1.3`) y alinear encabezados del épic ↔ claves de `sprint-status.yaml`.
2. **(M1 — barato, en este PR #414)** Corregir el slug E3 `3-1-...-**actor-id-not-null**` en `sprint-status.yaml` → `...-atribucion-real` (o `...-actor-id-nullable`); contradice **ADR D5** (`actor_id` permanece `nullable`). Verificar de paso que la línea FR15 del épic de E3 no repita "NOT NULL".

### Próximos pasos recomendados (en orden)

1. Aplicar **m1** (esquema de ID) y **M1** (slug E3) — ambos en `_bmad-output/`; edición de minutos.
2. `create-story AF-1.1` (agregado `User` + persistencia) → implementar → `create-story AF-1.2` → `AF-1.3`.
3. Al implementar: cerrar las **2 decisiones abiertas (m3)** — mecanismo CSRF concreto (AF-1.2) y puente `AuthenticationException`→401 con su fila en `docs/api-error-contract.md` (AF-1.3, NFR26).
4. **Follow-up no bloqueante:** registrar issue/story para la **pantalla de login PWA** (advertencia UX), encadenada tras AF-1.2 (`json_login` en vigor).
5. Completada auth-foundation: `sprint-status.yaml` deja `epic-3` listo → Story 3.1 (`#[IsGranted]` + swap `ActorContextFactory`) → 3.2 → 3.3 (levantar gate de prod #377).

### Nota final

Esta evaluación identificó **6 hallazgos** en 3 categorías (cobertura: 0 huecos · UX: 1 advertencia · calidad: 1 mayor + 4 menores). Ninguno es un defecto de contenido o diseño: la planificación de auth-foundation está **completa y alineada**. Resueltos m1 y M1 (consistencia entre artefactos), el desarrollo puede arrancar con `create-story AF-1.1`.

---

## Actualización — correcciones aplicadas (2026-07-02)

Tras la evaluación se aplicaron **M1** y **m1** con el esquema canónico **AF-1.1 / AF-1.2 / AF-1.3** (autorizado por Sergio):

- **M1 (resuelto)** — `sprint-status.yaml`: clave E3 `3-1-voter-rbac-rutas-lectura-trail-actor-id-not-null` → `3-1-voter-rbac-rutas-lectura-trail-atribucion-real`. Verificado que el *cuerpo* del épic de E3 (FR15 y Story 3.1) ya decía "la columna permanece nullable" (D9 tier-1) — no requería cambio.
- **m1 (resuelto)** — esquema de ID unificado a **AF-1.1/1.2/1.3**:
  - `epics-auth-foundation.md`: encabezados y referencias `Story 1.1/1.2/1.3` → `Story AF-1.1/AF-1.2/AF-1.3`.
  - `sprint-status.yaml`: claves `af-1/af-2/af-3-…` → `af-1-1/af-1-2/af-1-3-…`.
- **Pendiente al implementar:** m3 (2 decisiones abiertas: CSRF en AF-1.2, puente 401 en AF-1.3) · advertencia UX (follow-up login PWA). No bloquean.

**Queda listo para `create-story AF-1.1`.**

---

**Evaluador:** Product Manager · skill `bmad-check-implementation-readiness`
**Fecha:** 2026-07-02
**Alcance:** PR #414 (`feat/api-auth-rbac-architecture-hfql`) — épica *auth-foundation*

