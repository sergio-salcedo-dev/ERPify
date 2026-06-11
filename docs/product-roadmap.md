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

El `status` de cada módulo refleja el **código real**, no la aspiración:
`done` = entregado y ejercitado por una vertical real; `in-progress` =
cimientos presentes; `planned` = sin empezar.

## Estado de partida (lo que ya hay)

Dos verticales completas validan los cimientos de la Fase 0:

- **Banks** — CRUD full (búsqueda, filtros, paginación cursor, bulk-delete,
  subida de ficheros, eventos de dominio, realtime Mercure) en ambos lados,
  con DDD + hexagonal, tests (PHPUnit/Behat/Vitest/Playwright), PHPStan y el
  contrato de error RFC 9457. Es la **plantilla de oro** que acelera el resto.
- **Service Health** — endpoint + página de estado.

Cimientos compartidos ya operativos: API Response Standard (RFC 9457),
paginación/orden/filtros componibles, dispatcher de eventos de dominio,
pipeline async (Messenger), correlation-id, Sentry (front + back), tabla de
auditoría `domain_event`, CI (quality + tests) y entornos Compose.

Lo grande que **falta** y condiciona todo: **autenticación / RBAC / multi-tenant**
(no hay `company_id` ni login todavía) — es trabajo fundacional de la Fase 0.6.

## Fases

| Fase | Tema | Prioridad | Estado |
|------|------|-----------|--------|
| 0 | Fundación de plataforma | Crítica | En curso |
| 1 | Core ERP/CRM operativo | Alta | Pendiente |
| 2 | Operaciones avanzadas | Media-Alta | Parcial (Finance: Banks hecho) |
| 3 | Automation & Intelligence | Alta-Baja | Parcial (notificaciones en curso) |
| 4 | Analytics & Decision Layer | Media-Baja | Pendiente |
| 5 | Integration & Platform Extension | Baja | Pendiente |
| 6 | Platform Ops (CI/CD + Delivery) | Alta-Media | En curso |

El desglose de módulos y submódulos de cada fase está en `roadmap.ts`.

## Buckets de complejidad (para estimar)

Las ~35 funcionalidades pendientes del menú backoffice no cuestan lo mismo.
Asumiendo que se reutiliza la plantilla Banks:

| Bucket | Ejemplos | Días/módulo | Nº aprox. | Total |
|--------|----------|-------------|-----------|-------|
| Simple (CRUD calcado de Banks) | Clients, Companies, Employees, Departments, Brands, Products, Tasks | ~2 | 8 | 16 d |
| Medio (CRUD + relaciones + reglas) | Quotes, Work Orders, Delivery Notes, Stock, Projects, Invoicing, Users | ~3,5 | 12 | 42 d |
| Complejo (motores, integraciones, UI interactiva) | Configurador, Dynamic Pricing, Commissions, Automation Engine, Reporting, Portales, Dashboard | ~6 | 13 | 78 d |
| Fundacional | Auth/RBAC, multi-tenant, notificaciones, perfil/ajustes | — | — | ~27 d |
| **Total trabajo efectivo** | | | | **~163 d** |

## Estimación de calendario

Dev en solitario, 8 h/día de lunes a viernes (~21 días laborables/mes), con
Claude Code asistiendo y la plantilla Banks ya existente.

- **Suscripción Claude de 90 €/mes (Max 5×):** tiene límites de uso (ventanas de
  ~5 h + tope semanal). Trabajando 8 h/día no puedes tener a Opus a tope todo el
  día; mezclarás Opus + Sonnet con ratos throttled. Inflación de calendario
  estimada **~1,3×**.

`163 d × 1,3 ≈ 210 días laborables ≈ 10 meses` para paridad completa a nivel
Banks (incluyendo auth/RBAC).

| Alcance | Calendario aprox. |
|---------|-------------------|
| MVP funcional por entrada de menú (CRUD básico, sin todo el pulido de Banks) | **~5-6 meses** |
| Paridad completa "grade Banks" + auth/RBAC en los ~35 módulos | **~9-10 meses** |

Tres factores que mueven mucho la cifra:

1. **Auth/RBAC desde cero** suma ~3-4 semanas si no se reutiliza un bundle.
2. **Los módulos complejos dominan** (78 de 163 días): el Configurador, los
   motores de pricing/comisiones y la facturación con impuestos/PDF pueden
   dispararse según el alcance real.
3. **El límite no es teclear código** (Claude lo genera rápido), sino revisar,
   testear, integrar entre módulos y decidir producto. Eso comprime poco.

> Si el proyecto tuviera plazo, saltar al plan Max 20× (~200 €) probablemente se
> paga solo: quita el throttle y recorta ~1,5-2 meses de calendario.

## Cómo mantenerlo

- El backlog (estado, prioridad, dependencias) se edita **solo en `roadmap.ts`**;
  la página in-app y cualquier consumidor futuro derivan de ahí.
- Al cerrar un submódulo, cambia su `status` a `done` en `roadmap.ts` (o quítale
  el override para heredar el del módulo) — la barra de progreso se recalcula
  sola (`computeRoadmapProgress`).
- Actualiza la narrativa de fases / buckets de este doc solo cuando cambie la
  **estrategia**, no en cada submódulo cerrado.
