# ERPify — Roadmap de producto + ingeniería

> Plan maestro de **funcionalidades de negocio** de ERPify, por fases y módulos.
> Es la vista ejecutiva (fases, buckets de complejidad, estimación). El detalle
> módulo-a-módulo vive como **dato tipado**, fuente única de verdad, en
> [`pwa/src/app/backoffice/roadmap/_lib/roadmap.ts`](../pwa/src/app/backoffice/roadmap/_lib/roadmap.ts),
> y se renderiza como **backlog vivo** en la página in-app `/backoffice/roadmap`.
>
> Para el roadmap de **infraestructura / delivery** (publicación de imágenes,
> blue-green, multi-tenant a nivel de pipeline) ver
> [`docs/saas-production-roadmap.md`](saas-production-roadmap.md) — son
> complementarios: este cubre el *qué* de producto, aquél el *cómo* de entrega.

## Por qué esta organización (decisión)

Se evaluaron tres destinos para el roadmap: extender el doc de infra, crear un
doc nuevo, o construir la página in-app. La elección es **las tres capas, con
una sola fuente de verdad**, siguiendo el patrón que el repo ya usa para la
navegación (`backofficeMenu.ts` alimenta la sidebar):

1. **`roadmap.ts`** — dato tipado (`RoadmapPhase` → `RoadmapModule` →
   `RoadmapSubmodule` con `status`/`priority`/`dependsOn`/`boundedContext`).
   Cada módulo declara además su `objective` (la "definición de hecho" de
   ingeniería) y `userNeeds` (qué espera el usuario que ERPify le resuelva ahí).
   Es
   el backlog vivo, importable, portable (un futuro seed de API/DB o un export a
   GitHub Projects consume la misma forma) y **sin drift** posible respecto a la
   UI.
2. **Página `/backoffice/roadmap`** — renderiza ese dato (fases → módulos →
   submódulos, con barras de progreso y badges de estado). Realiza el
   "UI tipo backlog vivo".
3. **Este doc** — la narrativa de fases, los buckets de complejidad y la
   estimación de tiempo. No duplica la lista de submódulos (enlaza a la fuente).

El `status` se marca **explícitamente, tarea a tarea**, al cerrar y verificar
cada pieza — el backlog arranca sin nada marcado y nunca se pre-marca por
suposiciones sobre el código (la narrativa de lo ya existente vive en «Estado
de partida», abajo). Se declara **solo a nivel de submódulo** (omitido =
`planned`); el estado de un módulo se **deriva** de sus submódulos
(`moduleStatus`), de modo que un módulo nunca puede aparentar más avance que
el trabajo que contiene.

## Norte estratégico

ERPify **no** compite como "un conjunto de módulos" (CRM + facturación + obras)
ni como un Odoo verticalizado. Compite como un **motor de ejecución de procesos
de construcción**: automatización basada en **eventos + reglas + datos reales de
obra**. Cada módulo del roadmap se diseña para alimentar ese motor (todo emite
eventos, todo es automatizable), no como un silo CRUD. La obra es la fuente de
datos (módulo 2.4) y el Automation Engine (3.1) es el diferenciador: detectar
problemas, proponer soluciones y ejecutar acciones — con o sin aprobación.

## Estado de partida (lo que ya hay)

Dos verticales completas validan los cimientos de la Fase 0:

- **Banks** — CRUD full (búsqueda, filtros, paginación cursor, bulk-delete,
  subida de ficheros, eventos de dominio, realtime Mercure) en ambos lados,
  con DDD + hexagonal, tests (PHPUnit/Behat/Vitest/Playwright), PHPStan y el
  contrato de error RFC 9457. Es la **plantilla de oro** que acelera el resto.
- **System Health** — endpoint + página de estado.

Cimientos compartidos ya operativos: API Response Standard (RFC 9457),
paginación/orden/filtros componibles, dispatcher de eventos de dominio,
pipeline async (Messenger), correlation-id, Sentry (front + back), tabla de
auditoría `domain_event`, CI (quality + tests) y entornos Compose.

Lo grande que **falta** y condiciona todo: **autenticación / RBAC / multi-tenant**
(no hay `company_id` ni login todavía) — es trabajo fundacional de la Fase 0.4.

## Fases

| Fase | Tema | Prioridad | Estado |
|------|------|-----------|--------|
| 0 | Fundación de plataforma | Crítica | En curso |
| 1 | Core ERP operativo | Alta | Pendiente |
| 2 | Operaciones avanzadas | Media-Alta | Parcial (Finance: Banks hecho) |
| 3 | Automation & Intelligence | Alta-Baja | Parcial (notificaciones en curso) |
| 4 | Analytics & Decision Layer | Media-Baja | Pendiente |
| 5 | Integration & Platform Extension | Baja | Pendiente |
| 6 | Platform Ops (CI/CD + Delivery) | Alta-Media | En curso |

El desglose de módulos y submódulos de cada fase está en `roadmap.ts`.

El mapa **granular de bounded contexts** (agregados, value objects, invariantes
y los eventos por los que se integran) está en
[`bounded-contexts.md`](bounded-contexts.md). El **Media & Document System**
(core transversal, módulo `0.8`) está pendiente de diseño: su capa de negocio
(`Document`, versionado, permisos) se describe en
[`bounded-contexts.md`](bounded-contexts.md).

## Estrategia de implementación — modelado de datos (muy importante)

Cómo se construye el esquema a medida que se entregan los módulos de negocio
(Fase 1+). Es ortogonal a las fases de producto: describe la **evolución del
modelo de datos**, no qué módulo va antes. Operacionaliza —a nivel de datos— la
regla ya vigente en [`architecture-api.md`](architecture-api.md): *un contexto
nunca alcanza el `Domain/` ni la `Infrastructure/` de otro; la comunicación
entre contextos va por Application services publicados o por eventos de dominio.*

### Etapa 1 — ahora (cada módulo, aislado)

- **Entidades mínimas por contexto.** Solo los campos que el módulo necesita
  hoy; nada especulativo.
- **Relaciones locales.** FKs dentro del mismo bounded context; **sin FKs
  cross-module agresivas**. Una referencia a otro contexto se modela como un
  identificador (UUID) sin constraint de FK física entre contextos.
- **Fixtures por módulo.** Cada contexto trae sus propios datos de prueba,
  independientes.

### Etapa 2 — integración (solo vía eventos)

- **Integración entre módulos SOLO por eventos** (de dominio / integración),
  nunca por consultas directas al repositorio de otro contexto.
- **Read models para dashboards**: proyecciones materializadas que escuchan
  esos eventos, en lugar de hacer JOINs cross-context en caliente.
- **Automatizaciones simples** disparadas por eventos (Fase 3 del producto).

### Etapa 3 — consolidación

- **Consolidación de esquema** una vez los límites están probados por el uso.
- **Optimización de queries** (índices, `EXPLAIN ANALYZE` — ver
  [`rules/database.md`](rules/database.md)).
- **Proyecciones / CQRS ligero** donde el coste de lectura lo justifique.

### Recomendación arquitectónica concreta

**❌ NO:** una base de datos «ERPIFY_CORE» gigante con todo conectado por FKs
entre contextos. Acopla los módulos y degenera en el monolito espagueti.

**✅ SÍ:** **una sola DB física** (correcto en un modular monolith), pero con:

- **separación lógica estricta** — schema por bounded context, o una
  convención de nombres fuerte (`<context>_<tabla>`) si se mantiene un único
  schema;
- **repositorios aislados** por contexto;
- **sin cross-repository queries directas** entre contextos — los datos de otro
  contexto se obtienen por su Application service o por un read model alimentado
  por eventos.

> Encaja con el plan multi-tenant (`company_id`) de
> [`saas-production-roadmap.md`](saas-production-roadmap.md) (Fase H): el
> aislamiento por tenant es ortogonal al aislamiento por contexto; ambos se
> aplican a nivel de query, no por disciplina de cada repositorio.

Si se quiere que estas reglas sean **vinculantes y verificadas** (no solo
guía de roadmap), el siguiente paso es trasladarlas a
[`rules/database.md`](rules/database.md) / [`architecture-api.md`](architecture-api.md)
y añadir un gate de lint que detecte FKs o imports cross-context.

## Estrategia de arranque — frontend-first con contract-first (idea)

**Veredicto:** sí, empezar por el frontend con datos mockeados es recomendable
en ERPify — **pero solo con disciplina contract-first.** Sin contratos estables
desde el día 1 degenera en "UI bonita sin realidad de backend".

**Por qué encaja en ERPify.** No es un CRUD: hay workflows de obra, estados de
proyecto, automatizaciones, stock con movimientos, pipeline de CRM y finanzas
con dependencias. El riesgo principal **no es técnico, es de producto**:
construir un backend perfecto para una UI incorrecta. Empezar por la UI valida
el **flujo de usuario** antes de comprometer agregados, relaciones y tablas.

**Qué te da:**

1. **Valida la UX de procesos complejos** (¿cómo se ve un flujo de obra real?
   ¿cómo se mueve `lead → proyecto → presupuesto`?).
2. **Revela el dominio real** — aparecen entidades que el modelado "de tabla" no
   ve: `ActivityLog`, `WorkflowState`, `StockReservation`, `CostDeviation`.
3. **Contratos API más limpios** — DTOs estables nacidos del uso, no endpoints
   improvisados desde el backend.
4. **Evita overengineering temprano** — no construyes agregados/tablas erróneos.

**Riesgos a evitar (los tres pecados):**

- ❌ Frontend **sin modelo de dominio** → endpoints "para pantallas", no para
  negocio.
- ❌ Backend **sin UX validada** → agregados y relaciones prematuros.
- ❌ Cualquiera de los dos **sin contratos claros** → al conectar, no encaja y se
  rompe el diseño.

**Cómo hacerlo bien (contract-first + mock domain):**

1. **Define el contrato primero** (sin backend, sin DB): DTOs por entidad con
   estados reales del dominio, no formas inventadas. Ej. un `LeadDTO` con
   `status: new | contacted | won | lost`; un `ProjectDTO` con
   `status: planning | in_progress | completed` y `progress`. El contrato es el
   **modelo conceptual compartido** (DDD) entre front y back, y casa con los
   agregados del [mapa de contextos](bounded-contexts.md).
2. **Mock service layer detrás del puerto de dominio, no hardcode.** La PWA ya es
   DDD (`context/<contexto>/{domain,application,infrastructure}` con interfaces
   de `Repository`). El mock es un **adaptador in-memory detrás del mismo puerto**
   (un `InMemory<Entidad>Repository` junto al `Api<Entidad>Repository` real, como
   ya existe para Bank): cambiar de mock a API real es swap de binding en
   Inversify, sin tocar páginas ni casos de uso.
3. **Mockea eventos y estados, no listas.** La UI debe **reaccionar a eventos**
   del dominio (`LeadCreated`, `ProjectStarted`, `StockMoved`, `InvoiceGenerated`),
   no pintar arrays estáticos — así el mock ya prepara el terreno para el
   Automation Engine, el outbox y el realtime, y no nace desconectado.
4. **La UI = mapa de módulos del roadmap.** Navegación completa por módulo (CRM,
   Projects, Inventory, Finance, Automation, Documents) que valida la
   arquitectura antes de escribir backend.

**Orden propuesto (complementa, no sustituye, las fases de producto):**

| Etapa | Qué | Resultado |
|-------|-----|-----------|
| 1 · UX-first | PWA Next.js, páginas por módulo, mocks por dominio (eventos+estados), navegación completa, **sin backend real** | flujo de usuario validado |
| 2 · Contract freeze | DTOs / OpenAPI estables, validados contra el frontend, ajustes de UX | contrato congelado |
| 3 · Backend | Symfony modular monolith: bounded contexts reales, eventos Messenger, outbox, persistencia (sigue la estrategia de datos de arriba) | realidad de negocio |
| 4 · Integration loop | el frontend deja los mocks y conecta la API real; ajuste iterativo | producto conectado |

> **Regla de oro:** «el frontend define la intención, el backend define la
> realidad» — pero **ambos comparten el mismo modelo conceptual (DDD)**. El
> contrato es ese puente; los mocks deben representar eventos y estados reales
> del dominio, nunca datos arbitrarios.

## Buckets de complejidad (para estimar)

Las ~39 funcionalidades pendientes del menú backoffice no cuestan lo mismo.
Asumiendo que se reutiliza la plantilla Banks. Cada módulo de `roadmap.ts`
declara su bucket en el campo `complexity` (`low` = CRUD sin relaciones en DB,
`medium` = relaciones + reglas, `high` = motores/integraciones/UI interactiva),
visible como chip en la página — la regla para elegir tarea es **prioridad alta
+ complejidad baja primero**:

| Bucket                                            | Ejemplos                                                                                                                                              | Días/módulo | Nº aprox. | Total      |
|---------------------------------------------------|-------------------------------------------------------------------------------------------------------------------------------------------------------|-------------|-----------|------------|
| Simple (CRUD calcado de Banks)                    | Clients, Companies, Employees, Departments, Brands, Products, Tasks                                                                                   | ~2          | 8         | 16 d       |
| Medio (CRUD + relaciones + reglas)                | Quotes, Work Orders, Delivery Notes, Stock, Projects, Invoicing, Users (CRM incluye licitaciones; Budgeting, el estudio previo)                       | ~3,5        | 12        | 42 d       |
| Complejo (motores, integraciones, UI interactiva) | Configurator & Dynamic Pricing (1.6), Cost Allocation (2.5), External Portals (2.6), Commission Engine (2.7), Automation Engine, Reporting, Dashboard | ~6          | 14        | 84 d       |
| Fundacional                                       | Auth/RBAC, multi-tenant, notificaciones, perfil/ajustes                                                                                               | —           | —         | ~27 d      |
| **Total trabajo efectivo**                        |                                                                                                                                                       |             |           | **~169 d** |

> **Reconciliación (2026-06-14).** Configurator & Dynamic Pricing, Cost Allocation,
> External Portals y Commission Engine —antes solo nombrados en estos buckets— son
> ahora módulos reales de `roadmap.ts` (1.6, 2.5–2.7) con su contexto modelado en
> [`bounded-contexts.md`](bounded-contexts.md); Tender y el estudio previo entran
> como submódulos de CRM (1.2) y Budgeting (1.4). Cost Allocation es el único
> alcance nuevo respecto a la estimación previa (+1 módulo complejo, +6 d).

> **Reconciliación (2026-06-16) — modelado por subdominios reales.** El área
> comercial deja de ser un `CRM` genérico y se descompone (split moderado) en
> **Commercial** (leads/embudo/interacción + **Campaign**), **TenderManagement**
> (licitación pública, ciclo propio) y **CommercialProposal** (oferta a cliente).
> La **ejecución física de obra** sale de Projects a un contexto propio
> **SiteOperations** (diario, certificaciones, mediciones, incidencias, calidad,
> PRL). Se añaden **Resources** (maquinaria/vehículos como activos) y una
> identidad fina compartida **`Party`** + roles por contexto (resuelve el dilema
> cliente/proveedor/subcontrata/empleado sin god-entity). Alcance nuevo neto vs.
> estimación previa: **SiteOperations** (+1 complejo, ~6 d), **Resources**,
> **CommercialProposal** y **Campaign** (+3 medios, ~10,5 d); el split comercial y
> `Party` son reorganización, no trabajo nuevo. Modelo completo en
> [`bounded-contexts.md`](bounded-contexts.md).

## Estimación de calendario

Dev en solitario, 8 h/día de lunes a viernes (~21 días laborables/mes), con
Claude Code asistiendo y la plantilla Banks ya existente.

- **Suscripción Claude de 90 €/mes (Max 5×):** tiene límites de uso (ventanas de
  ~5 h + tope semanal). Trabajando 8 h/día no puedes tener a Opus a tope todo el
  día; mezclarás Opus + Sonnet con ratos throttled. Inflación de calendario
  estimada **~1,3×**.

`169 d × 1,3 ≈ 220 días laborables ≈ 10,5 meses` para paridad completa a nivel
Banks (incluyendo auth/RBAC).

| Alcance                                                                      | Calendario aprox. |
|------------------------------------------------------------------------------|-------------------|
| MVP funcional por entrada de menú (CRUD básico, sin todo el pulido de Banks) | **~5-6 meses**    |
| Paridad completa "grade Banks" + auth/RBAC en los ~39 módulos                | **~10-11 meses**  |

Tres factores que mueven mucho la cifra:

1. **Auth/RBAC desde cero** suma ~3-4 semanas si no se reutiliza un bundle.
2. **Los módulos complejos dominan** (78 de 163 días): el Configurador, los
   motores de pricing/comisiones y la facturación con impuestos/PDF pueden
   dispararse según el alcance real.
3. **El límite no es teclear código** (Claude lo genera rápido), sino revisar,
   testear, integrar entre módulos y decidir producto. Eso comprime poco.

> Si el proyecto tuviera plazo, saltar al plan Max 20× (~200 €) probablemente se
> paga solo: quita el throttle y recorta ~1,5-2 meses de calendario.

## UX & transversal (dominio construcción)

Temas que cruzan módulos (no son un contexto): se aplican como NFR/UX sobre las
fases, no como bloque aparte. Detalle de submódulos en `roadmap.ts` donde aplique.

- **Mapa de obras** — geolocalización de proyectos (MapLibre) con estado superpuesto
  (en curso / retrasado / finalizado); útil para comercial e ingeniería.
- **Dashboards por persona** — comercial (pipeline, valor en juego, tasa de
  conversión), ingeniero (estudios pendientes, coste por m²), financiero (cashflow
  proyectado, alertas de liquidez).
- **Configurador interactivo de pavimentos** — arrastrar capas (base/firme/rodadura),
  coste total en vivo, export a PDF con memoria de cálculo (módulo 1.6).
- **Kanban de licitaciones** — convocatoria → estudio → propuesta → adjudicación,
  con alerta automática 72 h antes del cierre (TenderManagement).
- **Exportación** — CSV / Excel / PDF en todas las tablas; plantillas de informe de
  obra.
- **i18n (es/en) desde el inicio** — términos del dominio traducidos
  (`PublicTender` → "Licitación pública", `Certification` → "Certificación"); hoy la
  superficie no está internacionalizada — es trabajo transversal (módulo 0.6).
- **Accesibilidad WCAG 2.1 AA** — `aria-label`/`role`/contraste; hoy parcial vía
  jsx-a11y + Sonar; elevar a objetivo explícito y verificar con axe/Lighthouse.
- **Regresión visual** — Storybook + Chromatic sobre componentes clave
  (`PavementConfigurator`, `PipelineVisualization`, `CostAllocationForm`); añade
  tooling, evaluar coste/beneficio antes de adoptar.
- **Feature flags en caliente** — activar/desactivar funciones sin recargar
  (refina el módulo de Feature Flags).
- **Mock de eventos del dominio en tests** — simular `commercial.opportunity.won` /
  `proposal.accepted` en e2e para verificar la creación de obra.

## Cómo mantenerlo

- El backlog (estado, prioridad, dependencias) se edita **solo en `roadmap.ts`**;
  la página in-app y cualquier consumidor futuro derivan de ahí.
- Al cerrar un submódulo, cambia su `status` a `done` en `roadmap.ts`; al
  empezarlo, márcalo `in-progress`. Un submódulo sin `status` cuenta como
  `planned`, y el estado del módulo se deriva solo — la barra de progreso se
  recalcula sola (`computeRoadmapProgress`).
- Actualiza la narrativa de fases / buckets de este doc solo cuando cambie la
  **estrategia**, no en cada submódulo cerrado.
