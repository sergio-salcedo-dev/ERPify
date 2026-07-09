---
stepsCompleted: [1, 2]
inputDocuments: []
session_topic: 'Arquitectura de autorización RBAC transversal de ERPify — el modelo que toda entidad futura (ERP + CRM) hereda para gobernar quién ejecuta qué acción. Bancos/cuentas bancarias = primera implementación de referencia.'
session_goals: 'a) Modelo genérico Permission = Resource + Action y su vocabulario · b) Costura de declaración: cómo cada entidad nueva declara su recurso · c) Resolución de la autorización · d) Ubicación en Hexagonal + relación con SI-5 · e) Postura estático (compilado) vs configurable (runtime) · f) Coste marginal casi cero para añadir un recurso nuevo (añadir una entidad NO debe tocar el núcleo de autorización) · g) Bancos/cuentas como slice de validación · SEPARACIÓN EXPLÍCITA autorización ≠ visibilidad de datos (row-level fuera de alcance salvo: decidir si la arquitectura admite introducir row-level sin rediseñar RBAC).'
selected_approach: 'progressive-flow'
techniques_used: ['First Principles Thinking', 'Morphological Analysis', 'Decision Tree Mapping', 'Constraint Mapping']
ideas_generated: []
context_file: ''
---

# Brainstorming Session Results

**Facilitador:** Claude (IA) · **Participante:** Sergio
**Fecha:** 2026-07-06

## Session Overview

**Tema:** Arquitectura de autorización RBAC transversal de ERPify — el modelo que **toda entidad futura del ERP y del CRM** hereda para gobernar quién puede ejecutar qué acción. Bancos y cuentas bancarias son la **primera implementación de referencia** (slice de validación), no el alcance.

**Objetivos:**

- **(a)** Modelo genérico `Permission = Resource + Action` y su vocabulario.
- **(b)** Costura de declaración: cómo una entidad nueva declara su recurso.
- **(c)** Cómo se resuelve una autorización.
- **(d)** Dónde vive en la arquitectura hexagonal + relación con el invariante congelado **SI-5**.
- **(e)** Postura sobre **estático (compilado)** vs **configurable (runtime)**.
- **(f)** **Coste marginal casi cero** para introducir un recurso nuevo en cualquier bounded context — añadir una entidad **no** debe requerir modificar el núcleo de autorización.
- **(g)** Bancos/cuentas como slice de validación del diseño.

### Restricciones y decisiones de encuadre (fijadas por Sergio)

- **Autorización ≠ visibilidad de datos.** Dos preguntas distintas: *¿puede ejecutar esta acción?* (authorization / RBAC) vs *¿sobre qué subconjunto de datos?* (data scope). No se mezclan en el modelo base; mezclarlas deriva el modelo hacia ABAC cuando el 90 % del ERP sólo necesita RBAC puro.
- **Row-level = subproblema separado, fuera de alcance de esta sesión.** La ÚNICA pregunta arquitectónica que sí respondemos: *¿la arquitectura debe permitir introducir row-level más adelante sin rediseñar RBAC?* No diseñamos own/team/company, predicates, policies, ownership, sharing ni jerarquías — eso es un segundo subsistema.
- **SI-5 (invariante congelado):** roles = política de autorización externa, decidida en el borde HTTP; `Application`/`Domain` no ramifican por rol. Si el brainstorm empuja a romperlo → decisión consciente candidata a ADR nuevo.
- **Método:** estabilizar el lenguaje y el modelo conceptual ANTES de proponer soluciones; cada decisión depende sólo de las anteriores.

### Secuencia conceptual (orden de Sergio) mapeada a las fases del flujo progresivo

| # | Pregunta | Fase |
|---|----------|------|
| 0 | ¿Quién es el **sujeto** de la autorización? | Fase 1 · Exploración |
| 1 | ¿Qué significa "recurso"? | Fase 1 · Exploración |
| 2 | ¿Qué significa "acción"? | Fase 1 · Exploración |
| 3 | ¿`Permission = Resource + Action`, o entidad con identidad propia? | Fase 2 · Patrones |
| 4 | ¿Cómo una entidad nueva declara su recurso? | Fase 2 · Patrones |
| 5 | ¿Cómo se resuelve una autorización? | Fase 3 · Desarrollo |
| 6 | ¿Dónde vive en Hexagonal? | Fase 3 · Desarrollo |
| 7 | ¿Configurable o compilado? | Fase 3 · Desarrollo |
| 8 | ¿Cómo encaja el row-level? (sólo punto de extensión) | Fase 4 · Plan de acción |

## Technique Selection

**Enfoque:** Flujo progresivo (divergencia → convergencia → validación).

| Fase | Técnica | Preguntas | Modo |
|------|---------|-----------|------|
| 1 · Exploración | First Principles Thinking | Subject · Resource · Action | Divergente |
| 2 · Patrones | Morphological Analysis | modelo Permission · costura de declaración (obj. f) | Convergente-analítico |
| 3 · Desarrollo | Decision Tree Mapping | resolución · ubicación Hexagonal · configurable vs compilado · SI-5 | Convergente |
| 4 · Plan de acción | Constraint Mapping | punto de extensión row-level · prueba de estrés · slice bancos · siguiente paso BMAD | Acción |

### Refinamientos de Sergio al itinerario

- **Modelo canónico = `Subject ── can perform ── Action ── on ── Resource`.** El vocabulario nace alrededor del **Subject** (hoy User/Role; mañana API Client, Service Account, Scheduled Job, Integration) → modelo mucho más general.
- **Fase 2 — naturaleza del permiso:** validar si un permiso es *exactamente* `Resource + Action` (derivado) o una **entidad con identidad propia** (`Permission { id, resource, action }`). Condiciona si los permisos son derivados u objetos de dominio.
- **Fase 4 — prueba de estrés:** someter el diseño a un set de recursos reales (Bank, Bank Account, Invoice, Contact, Opportunity, Product, Warehouse, Purchase Order) y preguntar por cada uno: *"¿tengo que tocar el núcleo de autorización para soportarlo?"* Un "sí" delata un acoplamiento innecesario.
- **Regla metodológica Fase 1:** prohibido mencionar Symfony, `#[IsGranted]`, voters o atributos. Permanecer en abstracción de dominio el mayor tiempo posible → el modelo sobrevive a un cambio de framework o de plataforma.

---

## Fase 1 · Exploración — Primeros Principios (Subject · Resource · Action)

> **Nota de método:** a mitad de Fase 1, Sergio externalizó la decisión a una IA general (`/local-generate-md` → `tmp/bmad-md/consult-rbac-authorization-architecture-20260706-143806.md`). La respuesta cubrió Q0–Q8 a nivel de diseño, adelantando conceptualmente las fases 1–3. Lo que sigue es el modelo **endurecido tras crítica**, pendiente de ratificación de Sergio (persistencia/arquitectura = decisión del owner).

### Consulta externa — crítica y síntesis

**Coincidencia ~85%.** La IA externa acertó en el núcleo; se le hizo push-back en 3 puntos por no aplicar YAGNI de forma pareja.

**Bloqueado (correcto + encaja con el repo):**

- **[Q3] Permission = `(Resource, Action)` valor derivado, NO entidad.** Decisión load-bearing: mantenerlo como valor (`"bank.read"`) hace que la migración estático→configurable cambie **sólo la implementación del store** (código→BD), no el modelo.
- **[Q1] Resource** = objeto de negocio gobernable de forma independiente (raíz de agregado; a veces read model, p.ej. el timeline de auditoría). Nunca ruta/controlador/contexto.
- **[Q2] Action** relativa al recurso; CRUD = vocabulario semilla; operaciones de dominio de 1ª clase (`bank.close`, `bankAccount.changeStatus`, `invoice.approve`).
- **[Q7]** Estático/compilado ahora, moldeado para volverse configurable sin rediseño.
- **SI-5 intacto.**

**Push-back (donde la IA falló):**

1. **Subject — se salta su propia Regla de Tres.** Bajo SI-5 (enforcement en el borde puro), el Subject *es* el token de Symfony; no hay objeto `Subject` en el código todavía. La distinción conceptual autoridad ≠ rendición-de-cuentas es correcta como **vocabulario**, pero **materializar `AuthorizationSubject` hoy es abstracción prematura** (misma trampa que él rechaza para Permission). Adoptar vocabulario, NO crear el tipo. El `ActorType` (`SYSTEM`/`API_KEY`) ya reserva el futuro no-humano.
2. **Costura de declaración — "el core descubre las declaraciones" pesa de más.** No hace falta registry/discovery en runtime: constantes de permiso **co-localizadas por módulo** + mapa rol→permiso central ya dan coste casi cero y 0 ediciones al core. Discovery se gana con un 2º caso.
3. **"Domain define el vocabulario" — inconsistente con SI-5.** El vocabulario de autorización es concern del borde/Infra; los Domains de negocio quedan libres de permisos. `Shared/Authorization` posee los tipos; cada módulo posee sus constantes en su borde; el binding rol→permiso es config de Infra.

**Auto-dudas de la IA resueltas con hechos del repo:** (a) `Shared/` siempre importable + deptrac auto-plega módulos `Shared/` anidados → `Shared/Authorization` es ubicación legal; (b) los voters de Symfony votan sobre un objeto de forma nativa → la puerta row-level (Q8) sale **gratis** (`#[IsGranted('bank.read', subject: $bank)]`, hoy sin sujeto).

### Modelo endurecido (propuesto — pendiente de ratificación)

| Q | Decisión final propuesta |
|---|---|
| 0 · Subject | Vocabulario "portador de autoridad atribuible", distinto de identidad y del actor de auditoría. NO se crea tipo `AuthorizationSubject` hoy. Sibling de `ActorContext`, no unificado. |
| 1 · Resource | Objeto de negocio gobernable (raíz de agregado; a veces read model). Nunca ruta/controlador/contexto. |
| 2 · Action | Capacidad relativa al recurso; CRUD = semilla; operaciones de dominio de 1ª clase. |
| 3 · Permission | Valor derivado `(Resource, Action)` → `"bank.read"`. No entidad. |
| 4 · Declaración | Constantes de permiso co-localizadas por módulo + mapa rol→permiso central (Infra). 0 ediciones al core. Sin registry runtime (aún). |
| 5 · Resolución | Un voter tras `#[IsGranted('bank.read')]` resuelve permiso→roles vía policy. El negocio nunca ramifica por rol. |
| 6 · Ubicación | Tipos + puerto + voter en `Shared/Authorization` **o** arrancan en `Identity/Infrastructure/Security` y se promueven. ⬅ BIFURCACIÓN ABIERTA. |
| 7 · Estático/config | Estático ahora; abstracciones listas para store rol→permiso en BD sin tocar modelo. |
| 8 · Row-level | Puerta abierta gratis vía `subject:` del voter; hoy no se evalúa sujeto. Nada de ABAC. |

### Decisiones ratificadas (Sergio, 2026-07-06)

- **Q0 · Subject — RATIFICADO.** Adoptar `Subject = "portador de autoridad"` como vocabulario arquitectónico; **NO** crear un tipo `AuthorizationSubject` todavía. Tres niveles: *conceptual* (sí), *contrato/abstracción* (aún no), *implementación* (ya existe — el adaptador de seguridad obtiene el principal autenticado y decide). El tipo se justifica cuando aparezca un 2º tipo de sujeto que obligue a escribir código distinto (`API_KEY`, `SERVICE_ACCOUNT`, `SCHEDULED_JOB`); hasta entonces una clase sólo añade indirección.
- **Q6 · Ubicación — RATIFICADO.** Arrancar en `Backoffice/Identity/Infrastructure/Security`; promover a `Shared/Authorization` cuando exista un **2º consumidor claramente transversal** (CLI protegida, API Keys, Frontoffice reusando el modelo, permisos declarativos reusados por varios BC). Hoy sólo hay *una primera implementación de autorización*, no un subsistema — sigue siendo preocupación de infraestructura de Identity.
  - **Refinamiento load-bearing:** diseñar las **interfaces neutrales desde el día 1** — el contrato habla de *autorización / permisos / decisiones*, **nunca** de `User` / `Role` / `SecurityUser`. Así la promoción futura a `Shared/Authorization` es **re-empaquetado y composición, no rediseño de modelo ni de API**.

**Modelo conceptual Q0–Q8 COMPLETO** (ver tabla anterior; Q0 y Q6 ya cerradas).

### Fase 4 · Prueba de estrés (Constraint Mapping) — validación del objetivo (f)

*Core = voter + valor `Permission` + puerto de policy.* Pregunta por recurso: ¿añadirlo toca el core?

| Recurso | ¿Toca el core? | Qué toca |
|---|:---:|---|
| Bank / BankAccount / Invoice / Contact / Opportunity / Product / Warehouse / PurchaseOrder | ❌ (8/8) | constantes `<resource>.*` co-localizadas en su borde + `#[IsGranted]` en rutas + fila en el mapa rol→permiso |

**Resultado: 8/8 pasan.** Lo que la tabla demuestra NO es "el modelo escala" en abstracto, sino una propiedad precisa y comprobable: **el motor de autorización es cerrado para modificación y abierto para extensión (OCP)**. Un recurso nuevo sólo *añade* (constantes/declaraciones de permiso + `#[IsGranted]` en su borde + entradas en la política) y nunca *modifica* (algoritmo de resolución + contrato del puerto + valor `Permission` + voter).

**Hallazgo — el mapa rol→permiso es POLÍTICA, no MECANISMO** (precisión de Sergio; corrige el previo "datos, no lógica"). El único punto central que tocan los 8 es el mapa rol→permiso, y NO viola el objetivo (f): (f) nunca dijo "no modificar ningún fichero" sino "añadir un recurso no obliga a modificar el *núcleo* del subsistema". El mapa es **configuración/política**, no el mecanismo. Analogía: cambiar la tabla de rutas ≠ cambiar el router; añadir una migración ≠ cambiar Doctrine; registrar un servicio ≠ cambiar el contenedor. La separación *policy vs mechanism* es más estable que *datos vs lógica* porque la política (`TREASURY_MANAGER → bank.read`) es una **regla de negocio de seguridad** aunque su soporte sea código hoy, PostgreSQL mañana o un servicio externo — el mecanismo (voter+resolución+puerto) permanece idéntico.

**Tripwire (guardia del diseño):** el mapa debe permanecer **estrictamente declarativo** (`rol → [permisos]`). En cuanto aparezca `if(...) then grant`, un `closure` o una `expression`, deja de ser un mapa y pasa a ser un *motor de políticas* → **ADR nuevo, capacidad distinta** (ya no se evoluciona RBAC). Junto con la puerta row-level de Q8, son los **dos límites objetivos que impiden derivar a ABAC**: cruzar cualquiera = ADR nuevo.

### Criterio de aceptación para el ADR (adoptado verbatim)

> **Objetivo arquitectónico (OCP):** el subsistema de autorización será abierto para extensión y cerrado para modificación. Añadir un nuevo recurso o acción sólo podrá requerir *declarar* nuevos permisos y *actualizar la política* de autorización; nunca modificar el algoritmo de resolución, el modelo `Permission` ni el contrato del puerto de autorización.

**Candidato para el ADR/épica (no ahora):** convertir el criterio en **gate ejecutable** al estilo `deptrac`/`php.lint.*` — un test de arquitectura que asegure (a) el set-núcleo no cambia al añadir un recurso y (b) el mapa de política no contiene código ejecutable. Convierte el tripwire en fallo de CI en vez de nota de revisión.

### Estado del brainstorm

**Convergido y estabilizado.** Modelo conceptual Q0–Q8 completo, criticado (consult externo + push-back) y validado (prueba de estrés → OCP). Listo para traducirse a uno o varios ADRs + plan de implementación **sin reabrir las decisiones fundamentales**. Siguiente fase BMAD: `bmad-create-architecture` (ADR + addendum scoped que extiende el subsistema auth/RBAC existente), banks/accounts como 1ª rebanada.


