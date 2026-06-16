# ERPify — Mapa de bounded contexts (alta granularidad)

> Artefacto de **roadmap / diseño**, no de implementación. Modela los bounded
> contexts de ERPify con detalle de agregados, value objects, invariantes y —
> sobre todo— los **eventos** por los que se integran. Es el "qué" y el "cómo se
> hablan"; el "cuándo" (secuencia de entrega) vive en
> [`product-roadmap.md`](product-roadmap.md), y el patrón de código real está en
> la vertical de referencia `Backoffice/Bank` de
> [`architecture-api.md`](architecture-api.md).
>
> **Nada aquí implica código todavía.** Al entrar en construcción, un contexto
> sigue la estrategia de datos por etapas de
> [`product-roadmap.md`](product-roadmap.md#estrategia-de-implementación--modelado-de-datos-muy-importante).

## Principios (vinculantes para todo contexto)

1. **Modular monolith, una sola DB física.** Separación **lógica** estricta:
   schema por bounded context o convención de nombres fuerte (`<contexto>_<tabla>`).
2. **Referencias cross-context por ID, no por FK (por defecto).** Una referencia
   a otro contexto de negocio es un **UUID v7** sin FK física; la integridad la
   dan eventos/políticas/ACL, no la DB. Una FK hacia shared kernel/identidad
   (`user`, `company_id`/tenant) sí está permitida. Lo prohibido no es la
   herramienta (FK/import), es el **conocimiento del dominio ajeno** — ver los
   tres niveles abajo.
3. **Sin cross-repository queries.** Un contexto nunca consulta el repositorio de
   otro; obtiene datos ajenos por un Application service publicado o por un
   **read model** alimentado por eventos.
4. **Todo pasa por eventos desde el inicio.** Cada cambio de estado de un agregado
   **registra un domain event** (`AggregateRoot::record`), se persiste en la tabla
   de auditoría/outbox (`PersistDomainEventMiddleware` → `StoredDomainEvent`) y se
   publica por Messenger. La integración entre contextos es **siempre** vía esos
   eventos.
5. **Convención de nombres de evento:** `erpify.<contexto>.<entidad>.<acción>`
   (ej. `erpify.frontoffice.commercial.opportunity.won`). Pasado, en minúsculas.
6. **Identidad y borrado:** UUID v7 asignado en la capa de aplicación;
   **hard delete por defecto** (estados de negocio como `archived`/`cancelled` se
   modelan como estado del agregado, no como soft delete) — ver
   [`rules/database.md`](rules/database.md).
7. **Errores:** excepciones de dominio con markers (`NotFound` → 404,
   `Conflict` → 409, etc.) y respuesta RFC 9457.

**Enforce boundaries, not total isolation (3 niveles).** El acoplamiento se
clasifica, no se prohíbe en bloque:

- **🔴 Level 1 — vinculante (bloquea revisión):** import cross-context del
  `Domain/`/`Application/` ajeno (salvo su Application service publicado +
  integration events); cross-repository query / `JOIN` cross-context.
- **🟡 Level 2 — desaconsejado (soft):** FK cross-context entre contextos de
  negocio → preferir referencia por ID; justificar una FK real en el PR.
- **🟢 Level 3 — permitido:** shared kernel (`User`, tenant, `Money`, `Uuid`),
  referencias solo-ID, integración por eventos, read models.

> **Regla de oro:** *los contextos referencian las identidades y reaccionan a los
> eventos de otros, nunca conocen sus interioridades.* Enunciado completo y
> niveles en
> [`rules/database.md`](rules/database.md#bounded-context-data-isolation-modular-monolith)
> y [`architecture-api.md`](architecture-api.md). Un gate estático de 3 niveles
> (error/warning/allowlist) lo verifica: `make php.lint.bounded-context` (Nivel 1
> bloquea, Nivel 2 avisa; seams publicados en `api/.bounded-context-allowlist`).

> **Eventos de dominio vs. de integración.** Un *domain event* es interno al
> contexto (rico, puede cambiar). Un *integration event* es el contrato público
> que otros contextos consumen (estable, payload mínimo de IDs + datos
> imprescindibles). Un contexto downstream traduce el evento ajeno en su propio
> lenguaje mediante un **Anti-Corruption Layer (ACL)** — nunca importa el evento
> del otro tal cual en su `Domain/`.

## Context map (relaciones)

```text
                 ┌──────────────────────────────────────────────────┐
                 │  Shared Kernel (identidad, Party (directorio de    │
                 │  actores), eventos, auditoría, UUID,               │
                 │  ProblemDetails, búsqueda/paginación)              │
                 └──────────────────────────────────────────────────┘
                                   ▲ usado por todos
   Organization ──(tenant/usuarios, upstream de TODO)──► (todos)
        │
        ▼ eventos
   Commercial ──opportunity.won──► Projects ──project.created──► Budgeting
      │  ▲                            │  │                          │
      │  │ tender.awarded             │  └─► Workforce / Resources  ▼ budget.approved
      │  └── TenderManagement ──study previo──► Budgeting        Procurement
      │                                │                            ▲
      ▼ proposal.accepted              ▼                            │
   CommercialProposal ──► Projects   Procurement ◄──────────────────┘
                                       │
   Projects ──project.created──► SiteOperations (diario, certificaciones, calidad, PRL)
                                       │ certification.approved
                                       ▼
     Finance ◄──cost-accrued / time.logged / goods.received / certification.approved──┘
        │
        ▼ invoice.issued / invoice.paid / payment.received / budget.exceeded
   Reporting (read models)   Notifications (fan-out)   Audit (todos los eventos)
        ▲                          ▲                        ▲
        └───── suscriptores genéricos del bus de eventos ───┘

   Automation ── escucha eventos de cualquier contexto y dispara acciones (comandos)
   Integration ── traduce eventos ⇄ sistemas externos (webhooks/conectores)
   Cost Allocation ── consume eventos de coste (horas, compras) y los reparte sobre obras
   Commissions ── devenga sobre opportunity.won / invoice.paid
   Portals ── expone (RBAC+ABAC) datos de otros contextos al actor externo dueño
```

**Tipos de relación:**

| Upstream → Downstream                                 | Tipo                                  | Integración                                                  |
|-------------------------------------------------------|---------------------------------------|--------------------------------------------------------------|
| Organization → todos                                  | Customer/Supplier                     | tenant id + `UserInvited`/`CompanyRegistered`                |
| Commercial → Projects, Finance, Commissions           | Customer/Supplier (ACL en downstream) | `OpportunityWon`                                             |
| TenderManagement → Projects, Budgeting                | Customer/Supplier                     | `TenderRegistered`, `TenderAwarded`                          |
| CommercialProposal → Projects, Finance                | Customer/Supplier                     | `ProposalAccepted`                                           |
| Projects → Budgeting, Procurement, Workforce, SiteOps | Partnership                           | `ProjectCreated`, `TaskCompleted`                            |
| Budgeting → Procurement, Finance                      | Customer/Supplier                     | `BudgetApproved`, `BudgetExceeded`                           |
| Procurement → Finance                                 | Customer/Supplier                     | `GoodsReceived`, `StockMovementRegistered`                   |
| Workforce → Finance, Projects                         | Customer/Supplier                     | `TimeLogged`                                                 |
| Resources → Projects, Cost Allocation                 | Customer/Supplier                     | `ResourceAssigned`                                           |
| SiteOperations → Finance, Reporting                   | Customer/Supplier (ACL en downstream) | `CertificationApproved`, `IncidentRaised`                   |
| Finance → Reporting, Notifications, Commissions       | Publisher/Subscriber                  | `InvoiceIssued`, `InvoicePaid`, `PaymentReceived`            |
| (todos) → Audit, Notifications, Reporting             | Publisher/Subscriber (conformist)     | bus de eventos                                               |
| Automation → (todos)                                  | Orquestador                           | escucha eventos / emite comandos                             |
| Workforce, Procurement, Resources → Cost Allocation   | Publisher/Subscriber                  | `TimeLogged`, `GoodsReceived`, `ResourceAssigned`           |
| Commercial, Finance → Commissions                     | Publisher/Subscriber                  | `OpportunityWon`, `InvoicePaid`                              |
| (varios) → Portals                                    | Conformist (read/command acotado)     | Application services + read models, gobernados por RBAC+ABAC |

---

## Contextos

Para cada uno: **responsabilidad**, **agregados/entidades** (raíz marcada con ⬢),
**value objects**, **invariantes**, **eventos que emite**, **eventos que consume**
(de quién), **read models** propios y la **necesidad de usuario** que cubre
(resumen; detalle en `roadmap.ts`).

### Shared Kernel / Platform (no es un contexto de negocio)

Dos caras del mismo núcleo compartido:

- **Kernel técnico** — primitivas compartidas: identidad (`Identifiable`, `Uuid`
  v7), `AggregateRoot`, `DomainEvent`, store/outbox de eventos
  (`StoredDomainEvent`), `ProblemDetails`, criteria de búsqueda/paginación,
  `NormalizedText`, validación, mailer. Hoy ya vive en `api/src/Shared`.
- **Platform (kernel de negocio referenciable por todos)** — identidad y
  plataforma: `User`, `Tenant`/`company_id`, `Role`, `Permission`, `FeatureFlag`.
  Es el **único** núcleo que cualquier contexto puede referenciar, y hacia el que
  una **FK / `ManyToOne` está permitida** (Level 3). Su CRUD/gestión vive en sus
  módulos del roadmap (Organization para users/roles, Feature Flags), pero como
  *referencia* actúan como shared kernel.
- **Party (directorio de actores, identidad fina compartida)** — la **identidad
  legal mínima** de cualquier actor del negocio: ⬢ `Party` (`id` UUID v7,
  `LegalName`, `TaxId` (NIF/CIF), `kinds`) + `PartyRole`
  (`customer`/`supplier`/`subcontractor`/`employee`/`autónomo`/`lead`/`prospect`,
  varios a la vez sobre la **misma** Party — habitual en construcción: una empresa
  es a la vez cliente y proveedor). Es la **resolución del dilema "actor único vs
  por contexto"** (ver [`adr`](adr/bank-bankaccount-modeling.md) para el patrón de
  referencia por id): la Party es identidad compartida y referenciable por id;
  cada contexto de negocio **posee su rol** (Commercial → `Account`/`Lead`,
  Procurement → `Supplier`, Workforce → `Employee`/`Subcontractor`, Finance
  referencia `partyId`) y nunca conoce el modelo de rol de otro. Evita el
  god-context `Party` sin perder la vista 360°. Emite `party.registered`,
  `party.role-assigned`, `party.merged`.
- **Regla:** estable y mínimo. Un contexto de negocio **no** se referencia por
  asociación Doctrine desde otro (eso es por id + eventos); solo el Platform
  recibe ese trato. Detalle y ejemplos en
  [`rules/database.md`](rules/database.md#bounded-context-data-isolation-modular-monolith).

### Organization (`erpify.backoffice.organization.*`)

- **Responsabilidad:** la **frontera multi-tenant**. Empresas, usuarios, equipos
  y la pertenencia usuario↔empresa. Upstream de todos.
- **Agregados/entidades:** ⬢ `Company` · ⬢ `User` · `Team` · `Membership`
  (user↔company↔role) · `Invitation`.
- **Value objects:** `CompanyName` (NormalizedText), `Email`, `TaxId` (NIF/CIF),
  `Locale` (es/en), `Role` (enum), `PermissionSet`.
- **Invariantes:** email único por empresa; una empresa tiene ≥1 owner; no se
  puede borrar el último owner; `company_id` obligatorio en todo agregado
  tenant-owned del resto de contextos.
- **Emite:** `company.registered`, `company.archived`, `user.invited`,
  `user.activated`, `user.role-changed`, `team.created`, `member.added`,
  `member.removed`.
- **Consume:** — (raíz; solo Shared).
- **Read models:** directorio de usuarios por empresa.
- **Necesidad:** gestionar usuarios/equipos, invitar y asignar roles, perfil y
  preferencias. *(roadmap 1.1)*

> **Sin "CRM" genérico — modelado por subdominios reales.** El comercial de
> construcción no piensa en "CRM"; piensa en *leads*, *licitaciones*, *propuestas*
> y *clientes*. El área comercial se descompone (split moderado) en tres contextos
> de lenguaje del dominio en lugar de un `CRM` que mezcla procesos con ciclos de
> vida distintos: **Commercial** (prospección + embudo privado + interacción),
> **TenderManagement** (licitación pública, ciclo propio) y **CommercialProposal**
> (oferta económica/técnica al cliente). Un `SalesModule` o un `CRM` serían
> abstracciones técnicas, no del negocio.

### Commercial (`erpify.frontoffice.commercial.*`)

- **Responsabilidad:** prospección, embudo de venta privada e historial de
  relación con el cliente (LeadManagement + OpportunityTracking +
  ClientInteraction).
- **Agregados/entidades:** ⬢ `Lead` · ⬢ `Contact` · ⬢ `Account` (rol comercial de
  una `Party`, por id) · ⬢ `Opportunity` (deal) · ⬢ `Campaign` (marketing) ·
  `Activity`/`Interaction` (llamada/tarea/email/reunión) · `Note` · `Attachment` ·
  `Pipeline`/`Stage` (configurable) · `LeadSource`.
- **Value objects:** `PipelineStage`, `Money` + `Currency`, `Probability`,
  `ContactChannel`, `LeadStatus`, `CampaignChannel`.
- **Invariantes:** una oportunidad pertenece a un `Account` (→ `Party` por id); su
  `Stage` avanza por la máquina del `Pipeline`; cerrar como ganada exige importe y
  cliente; una campaña no mezcla leads de otro tenant.
- **Emite:** `lead.captured`, `lead.qualified`, `opportunity.created`,
  `opportunity.stage-changed`, `opportunity.won`, `opportunity.lost`,
  `activity.logged`, `interaction.logged`, `campaign.launched`,
  `campaign.lead-attributed`.
- **Consume:** `organization.company.registered` (provisión de tenant),
  `organization.user.invited` (propietario comercial), `party.registered`.
- **Read models:** pipeline board, embudo por fase, próximas actividades, ROI por
  campaña, ficha 360° del cliente (interacciones).
- **Necesidad:** todo el cliente en un sitio, seguir oportunidades, no olvidar
  seguimientos, atribuir leads a campañas. *(roadmap 1.2)*

### TenderManagement (`erpify.frontoffice.tender.*`)

- **Responsabilidad:** seguimiento de **licitaciones públicas** — un ciclo de vida
  propio (convocatoria → estudio → propuesta → adjudicación) con plazos y
  documentación reglada, distinto del embudo privado.
- **Agregados/entidades:** ⬢ `PublicTender` · `SubmissionDeadline` ·
  `TenderDocument` (pliegos) · `BidDecision` (bid/no-bid).
- **Value objects:** `TenderDeadline`, `AwardStatus` (ganada/perdida/desierta),
  `TenderReference`, `CpvCode`.
- **Invariantes:** una licitación tiene fecha límite; presentarse referencia un
  **estudio económico previo** (Budgeting, por id) y una `CommercialProposal`;
  la adjudicación es terminal; no se presenta sin decisión bid registrada.
- **Emite:** `tender.registered`, `tender.deadline-approaching`,
  `tender.bid-submitted`, `tender.awarded`, `tender.lost`.
- **Consume:** `budgeting.study.completed` (estudio previo listo),
  `proposal.issued` (oferta a adjuntar).
- **Read models:** agenda de licitaciones por fecha límite, tablero Kanban
  (convocatoria → estudio → propuesta → adjudicación), tasa de adjudicación.
- **Necesidad:** no perder un plazo, gestionar pliegos, decidir bid/no-bid y seguir
  la adjudicación. *(roadmap 1.2b)*

### CommercialProposal (`erpify.frontoffice.proposal.*`)

- **Responsabilidad:** generación de la **oferta económica y técnica al cliente** —
  cara a cliente y con validez, distinta del presupuesto interno de Budgeting.
- **Agregados/entidades:** ⬢ `Proposal` (`ProposalDocument`) · `ProposalLine` ·
  `CostEstimate` (resumen cara a cliente, derivado de un `Budget` por id).
- **Value objects:** `ValidityPeriod`, `Money`/`Currency`, `ProposalStatus`
  (draft/issued/accepted/rejected/expired).
- **Invariantes:** una propuesta referencia una `Opportunity` o un `PublicTender`
  (por id) y un `Budget` interno (por id); emitida es inmutable (se versiona);
  aceptarla es terminal y dispara la creación de obra.
- **Emite:** `proposal.issued`, `proposal.accepted`, `proposal.rejected`,
  `proposal.expired`.
- **Consume:** `budgeting.budget.approved` (base económica),
  `commercial.opportunity.won` / `tender.awarded` (contexto de la oferta).
- **Read models:** propuestas por estado, valor en juego, ratio de aceptación.
- **Necesidad:** mandar una oferta profesional ligada al estudio económico, con
  validez y seguimiento de su aceptación. *(roadmap 1.2c)*

### Projects / Construction (`erpify.backoffice.projects.*`)

- **Responsabilidad:** gestión de obra de principio a fin (vertical core).
- **Agregados/entidades:** ⬢ `Project` (obra) · `Phase` · `Milestone` · ⬢ `Task` ·
  `Assignment` · `ProgressEntry`.
- **Value objects:** `ProjectStatus` (lifecycle), `Percentage` (avance),
  `DateRange`, `ProjectCode`.
- **Invariantes:** una tarea pertenece a una fase; el avance del proyecto deriva de
  sus fases/tareas; no se cierra una obra con tareas abiertas.
- **Emite:** `project.created`, `project.status-changed`, `phase.started`,
  `phase.completed`, `task.assigned`, `task.completed`, `progress.updated`.
- **Consume:** `proposal.accepted` / `tender.awarded` (ACL → crea borrador de
  obra), `commercial.opportunity.won` (deal privado sin propuesta formal),
  `organization.member.added` (asignables). La **ejecución física** de la obra
  (diario, certificaciones, mediciones, calidad, PRL) vive en su contexto hermano
  **SiteOperations**, no aquí — Projects modela estructura y avance, SiteOps el
  día a día de campo.
- **Read models:** tablero de obra, timeline de hitos, carga por persona.
- **Necesidad:** controlar la obra, ver avance real, saber quién hace qué.
  *(roadmap 1.3)*

### Budgeting & Estimation (`erpify.backoffice.budgeting.*`)

- **Responsabilidad:** presupuestos y estimación por obra, con versionado y
  márgenes. Incluye el **configurador por capas** (pavimentos como primer vertical)
  como sub-motor de estimación y el **estudio económico previo** (presupuesto
  borrador para una licitación, antes de adjudicar).
- **Agregados/entidades:** ⬢ `Budget` · `BudgetLine` (partida) · `EstimationTemplate`
  · `BudgetVersion` · ⬢ `Configuration` (configurador por capas) ·
  `PreBidStudy` (estudio previo).
- **Value objects:** `Money`/`Currency`, `Markup`/`Margin`, `Quantity`+`Unit`,
  `CostType`, `LayerSpec`, `ProductivityRatio` (rendimiento), `PricingRule`.
- **Invariantes:** un presupuesto referencia una obra (UUID, sin FK); el total es
  la suma de líneas × cantidades; aprobar congela una versión inmutable. Una
  configuración deriva su coste de sus capas (material + mano de obra + transporte)
  y genera líneas de presupuesto; un estudio previo referencia una **licitación**
  (no una obra) por id, hasta que se adjudica.
- **Emite:** `budget.created`, `budget.line-added`, `budget.version-created`,
  `budget.approved`, `budget.rejected`, `configuration.priced`,
  `budget.line-generated`, `study.completed`.
- **Consume:** `projects.project.created` (a qué obra presupuestar),
  `tender.registered` (qué licitación estudiar).
- **Read models:** previsto vs. real por partida, margen por obra, coste por
  configuración.
- **Necesidad:** presupuestar por obra, reutilizar plantillas, comparar previsto
  vs. real y margen, configurar estructuras por capas con su coste, estudiar una
  licitación antes de presentarla. *(roadmap 1.4, 1.6)*

### Procurement & Inventory (`erpify.backoffice.procurement.*`)

- **Responsabilidad:** proveedores, compras y control de stock por obra.
- **Agregados/entidades:** ⬢ `Supplier` · ⬢ `PurchaseOrder` (+ `PurchaseOrderLine`)
  · ⬢ `Material` (catálogo) · `StockLocation` · ⬢ `StockMovement` (in/out/transfer)
  · `Reservation` (material reservado para obra).
- **Value objects:** `Sku`, `Quantity`+`Unit`, `Money`/`Currency`, `MovementType`,
  `ReorderPoint`.
- **Invariantes:** el stock por ubicación nunca es negativo; una reserva no supera
  el stock disponible; recibir un pedido genera un `StockMovement` de entrada.
- **Emite:** `supplier.registered`, `purchase-order.issued`, `goods.received`,
  `stock-movement.registered`, `material.reserved`, `stock.below-reorder-point`.
- **Consume:** `budgeting.budget.approved` (qué comprar), `projects.project.created`
  (a qué obra imputar/reservar).
- **Read models:** stock por almacén, materiales por obra, pedidos pendientes.
- **Necesidad:** saber qué material tengo y dónde, pedir a proveedores, reservar
  por obra, avisar de rotura de stock. *(roadmap 1.5)*

### Workforce & Time Tracking (`erpify.backoffice.workforce.*`)

- **Responsabilidad:** personas, subcontratas y partes de trabajo con coste.
- **Agregados/entidades:** ⬢ `Employee` · ⬢ `Subcontractor` · ⬢ `WorkLog` (parte) ·
  `TimeEntry` · `Schedule`.
- **Value objects:** `Hours`, `LaborRate` (`Money`/hora), `Shift`, `Skill`.
- **Invariantes:** una imputación de horas referencia tarea+persona (UUID); las
  horas de un parte cerrado no se editan; coste de mano de obra = horas × tarifa.
- **Emite:** `employee.registered`, `worklog.submitted`, `time.logged`,
  `schedule.published`.
- **Consume:** `projects.task.assigned` (contra qué imputar),
  `organization.member.added`.
- **Read models:** horas por obra/tarea, coste de mano de obra, planificación.
- **Necesidad:** registrar horas por obra, partes de empleados/subcontratas, coste
  real de mano de obra, planificar carga. *(roadmap 2.1)*

### Resources / Plant & Equipment (`erpify.backoffice.resources.*`)

- **Responsabilidad:** **activos** de la empresa (maquinaria y vehículos), su
  asignación a obra y su coste/mantenimiento. Hermano de Workforce: un activo no
  es ni persona (Workforce) ni material fungible (Procurement).
- **Agregados/entidades:** ⬢ `Machine` · ⬢ `Vehicle` · `Assignment` (activo↔obra/
  tarea, por id) · `MaintenanceLog`.
- **Value objects:** `AssetCode`, `MachineType`, `HourMeter`/`Odometer`,
  `InternalRate` (`Money`/hora o /día), `MaintenanceInterval`, `AssetStatus`.
- **Invariantes:** un activo no está asignado a dos obras en el mismo periodo; el
  coste imputado = horas/días × tarifa interna; un activo en mantenimiento no es
  asignable.
- **Emite:** `resource.registered`, `resource.assigned`, `resource.released`,
  `resource.maintenance-due`, `resource.maintenance-completed`.
- **Consume:** `projects.project.created` (a qué obra asignar).
- **Read models:** disponibilidad por activo, coste de maquinaria por obra,
  próximos mantenimientos.
- **Necesidad:** saber qué maquinaria/vehículos tengo y dónde, asignarlos a obra,
  su coste real y sus mantenimientos. *(roadmap 2.8)*

### SiteOperations / Construction Execution (`erpify.backoffice.site-operations.*`)

- **Responsabilidad:** el **día a día de la obra en campo** — diario, mediciones,
  **certificaciones** (a origen), incidencias, calidad y seguridad (PRL). Estos
  procesos son **núcleo** de un ERP de construcción (más que un marketing
  genérico); van en su contexto, no inflando Projects.
- **Agregados/entidades:** ⬢ `SiteDiary` (parte diario) · ⬢ `Certification`
  (certificación de avance) · `Measurement` (medición) · ⬢ `Incident` ·
  `QualityCheck` · `SafetyRecord` (PRL) · `SitePlanning`.
- **Value objects:** `MeasurementUnit`, `CertificationStatus`
  (draft/submitted/approved/billed), `ProgressPercentage`, `IncidentSeverity`,
  `SafetyInspectionResult`, `WeatherCondition`.
- **Invariantes:** una certificación referencia obra+periodo (por id), deriva de
  **mediciones aprobadas**, y aprobarla la **congela** y dispara facturación a
  origen (Finance); una medición aprobada no se edita; una incidencia es terminal
  al resolverse.
- **Emite:** `site-diary.recorded`, `measurement.approved`,
  `certification.submitted`, `certification.approved`, `incident.raised`,
  `incident.resolved`, `quality-check.failed`, `safety.inspection-recorded`.
- **Consume:** `projects.project.created` (qué obra), `resources.resource.assigned`
  y `workforce.time.logged` (para el diario), partes de Mobile Field Ops (2.4).
- **Read models:** certificaciones por obra (previsto/certificado/pendiente),
  diario por obra, incidencias abiertas, KPIs de calidad/seguridad.
- **Necesidad:** llevar el diario de obra, certificar avance para poder facturar,
  registrar mediciones, incidencias, calidad y PRL. *(roadmap 1.3b)*

### Document Management (`erpify.backoffice.dms.*`)

- **Responsabilidad:** documentos ligados a entidades (obra, cliente…), con
  versionado y permisos. Es la **capa de negocio (F3)** del Media & Document
  System transversal — `Document` (agregado versionado) ≠ `MediaFile` (fichero
  técnico). Backlog por capas: [`media-document-system.md`](media-document-system.md).
- **Agregados/entidades:** ⬢ `Document` · `DocumentVersion` · `DocumentTemplate` ·
  `AccessGrant`.
- **Value objects:** `FileRef` (storage key + content hash, ya existe el patrón en
  Bank), `MimeType`, `DocumentKind`.
- **Invariantes:** cada documento referencia su entidad por UUID (sin FK); la
  última versión es inmutable una vez publicada; el borrado físico es posible
  (GDPR).
- **Emite:** `document.uploaded`, `document.version-added`, `document.shared`.
- **Consume:** eventos de cualquier contexto que adjunte documentos (p. ej.
  `projects.project.created` para la carpeta de obra).
- **Read models:** documentos por obra/cliente, última versión.
- **Necesidad:** documentos de la obra juntos, última versión siempre, control de
  quién ve qué. *(roadmap 2.2)*

### Finance (`erpify.backoffice.finance.*`)

- **Responsabilidad:** núcleo financiero: facturación, cobros/pagos, tesorería,
  coste por proyecto. **Banks/Treasury ya entregado** como vertical de referencia.
- **Agregados/entidades:** ⬢ `Bank` *(hecho)* · ⬢ `BankAccount` · ⬢ `Invoice`
  (+ `InvoiceLine`) · ⬢ `Payment` · `CostEntry` (imputación a obra) · `TaxRate`.
- **Value objects:** `Money`/`Currency` *(existe)*, `Iban`/`Bic`, `TaxId`,
  `InvoiceStatus`, `DueDate`.
- **Invariantes:** una factura referencia cliente y (opcional) obra por UUID; total
  = líneas + impuestos; un pago no supera el pendiente; no se edita una factura
  emitida (se rectifica).
- **Emite:** `invoice.drafted`, `invoice.issued`, `invoice.paid`,
  `payment.received`, `project.cost-accrued`, `project.budget-exceeded`
  (coste real > presupuesto aprobado), `bank.created`/`updated`/`deleted`
  *(ya activos)*.
- **Consume:** `proposal.accepted` / `commercial.opportunity.won` (borrador de
  factura), `site-operations.certification.approved` (factura a origen),
  `procurement.goods.received` + `workforce.time.logged` + `resource.assigned`
  (coste por obra), `budgeting.budget.approved`.
- **Read models:** cashflow, coste/margen por proyecto, aging de cobros.
- **Necesidad:** facturar a cliente/proveedor, seguir cobros y pagos, ver cashflow,
  saber margen por proyecto. *(roadmap 2.3)*

### Cost Allocation (`erpify.backoffice.cost-allocation.*`)

- **Responsabilidad:** centros de coste y reglas de imputación que reparten costes
  reales sobre obras y objetos de coste. Motor **event-driven**: consume eventos de
  coste y produce imputaciones auditables; no posee el coste, lo distribuye.
- **Agregados/entidades:** ⬢ `CostCenter` (jerárquico) · ⬢ `AllocationRule`
  (driver + método) · `AllocationRun` · `AllocationEntry` (resultado) · `CostPool`.
- **Value objects:** `AllocationMethod` (directa/indirecta/%/volumen/horas/
  consumo/fórmula), `CostDriver`, `Weight`, `Money`/`Currency`.
- **Invariantes:** la suma de las imputaciones de un pool = el coste del pool (sin
  fugas ni duplicados); una regla es idempotente por `eventId`; toda imputación
  guarda su traza de cálculo (driver, método, factor).
- **Emite:** `cost-center.created`, `allocation-rule.defined`, `allocation.run`,
  `cost.allocated`.
- **Consume:** `workforce.time.logged`, `procurement.goods.received`,
  `finance.project.cost-accrued` (ACL → driver de coste).
- **Read models:** coste por centro/obra, indirectos repartidos, deriva de coste.
- **Necesidad:** repartir indirectos con reglas, imputar cada coste a su obra,
  auditar cómo se calculó. *(roadmap 2.5)*

### Commissions (`erpify.backoffice.commissions.*`)

- **Responsabilidad:** planes de comisión por beneficiario, con devengo por evento
  y liquidación. Suscriptor del bus comercial/financiero.
- **Agregados/entidades:** ⬢ `CommissionPlan` (reglas) · `Beneficiary`
  (cliente/proveedor/empleado/empresa, por id) · ⬢ `CommissionAccrual` (devengo) ·
  `Settlement` (liquidación).
- **Value objects:** `CommissionRate` (%/fijo/escalado), `Tier`, `Money`/`Currency`,
  `AccrualStatus`.
- **Invariantes:** un devengo referencia su hecho origen (`opportunity`/`invoice`,
  por id) y es idempotente por `eventId`; una liquidación congela los devengos que
  incluye; no se devenga dos veces el mismo hecho.
- **Emite:** `commission.accrued`, `commission.settled`, `commission-plan.updated`.
- **Consume:** `commercial.opportunity.won`, `finance.invoice.paid` (ACL → base de
  cálculo).
- **Read models:** comisiones devengadas por beneficiario, pendientes de liquidar.
- **Necesidad:** definir comisiones %/fijo/escalado, calcularlas solas al ganar o
  cobrar, liquidar con desglose. *(roadmap 2.7)*

### Automation Engine (`erpify.backoffice.automation.*`)

- **Responsabilidad:** reglas y workflows que reaccionan a eventos del dominio y
  disparan acciones. **Orquestador**: escucha eventos de cualquier contexto y emite
  comandos (nunca toca el `Domain/` de otro directamente).
- **Agregados/entidades:** ⬢ `AutomationRule` (trigger + condición + acciones) ·
  ⬢ `Workflow` (máquina de estados / DAG) · `WorkflowInstance` · `ActionLog`.
- **Value objects:** `EventSelector`, `Condition` (IF/THEN), `ActionSpec` (enviar
  email, crear tarea, actualizar estado), `RetryPolicy`.
- **Invariantes:** una regla es idempotente por `eventId`; las acciones con efectos
  externos llevan compensación (saga ligera).
- **Emite:** `automation.rule-fired`, `automation.action-executed`,
  `automation.action-failed`.
- **Consume:** **cualquier** evento (suscriptor genérico configurable).
- **Necesidad:** automatizar tareas repetitivas, diseñar flujos sin programar, que
  el sistema cree tareas/avise solo. *(roadmap 3.1)*

### Notifications (`erpify.backoffice.notifications.*`)

- **Responsabilidad:** centro de notificaciones in-app, email y push con
  preferencias por usuario/rol. Suscriptor genérico del bus.
- **Agregados/entidades:** ⬢ `Notification` · `Subscription` (qué eventos, qué
  canal) · `NotificationPreference`.
- **Value objects:** `Channel` (in-app/email/push), `NotificationKind`,
  `DeliveryStatus`.
- **Invariantes:** no se notifica dos veces el mismo `eventId` por canal; respeta
  las preferencias del destinatario.
- **Emite:** `notification.created`, `notification.delivered`, `notification.read`.
- **Consume:** eventos suscritos de cualquier contexto (hoy ya:
  `bank.created/updated` por email + realtime).
- **Necesidad:** enterarse al momento, elegir avisos y canal, verlos en un centro.
  *(roadmap 3.2)*

### Feature Flags (`erpify.backoffice.feature-flags.*`)

- **Responsabilidad:** activar funciones por tenant/usuario y rollout gradual.
- **Agregados/entidades:** ⬢ `FeatureFlag` · `FlagRule` (targeting) · `Rollout`.
- **Value objects:** `FlagKey`, `TargetingRule`, `RolloutPercentage`.
- **Invariantes:** evaluación determinista por (tenant, usuario, flag).
- **Emite:** `feature-flag.toggled`, `rollout.advanced`.
- **Consume:** `organization.company.registered` (defaults por tenant).
- **Necesidad:** activar/desactivar funciones por empresa sin desplegar, probar con
  un grupo antes que todos. *(roadmap 3.3)*

### External Portals (`erpify.backoffice.portals.*`)

- **Responsabilidad:** superficies self-service por tipo de actor externo (cliente,
  proveedor, subcontrata, empleado). **No posee dominio de negocio**: expone, vía
  RBAC + ABAC, vistas y comandos acotados de otros contextos al actor dueño de esos
  datos. Cada portal es un *conformist* de los contextos que consume.
- **Agregados/entidades:** ⬢ `PortalAccess` (actor↔alcance) · `PortalInvitation` ·
  `ScopedSession`.
- **Value objects:** `ActorType` (client/provider/subcontractor/employee),
  `AccessScope`, `BusinessContext` (employeeStatus/customerStatus — ya en el front).
- **Invariantes:** un acceso solo ve los datos de su actor (tenant + propietario);
  el alcance lo decide una policy ABAC, no la pantalla; una invitación caduca.
- **Emite:** `portal-access.granted`, `portal-invitation.sent`,
  `portal.action-submitted` (parte, aprobación o factura subida).
- **Consume:** `organization.user.invited`, y por portal los Application services /
  read models de Projects + SiteOperations (avance, certificaciones), Finance
  (facturas), Procurement (pedidos, albaranes) y Workforce (partes).
- **Read models:** «mis proyectos / facturas / pedidos / partes» por actor.
- **Necesidad:** que cada actor externo opere lo suyo sin pasar por oficina.
  *(roadmap 2.6)*

### Reporting & Analytics (`erpify.backoffice.reporting.*`)

- **Responsabilidad:** KPIs, dashboards y analítica. **Solo read models**: no posee
  estado de negocio, proyecta eventos de otros contextos.
- **Agregados/entidades:** `Kpi` (definición) · `Dashboard` · `ReportExport` ·
  **read models** materializados (coste por obra, rentabilidad, embudo…).
- **Value objects:** `Metric`, `Aggregation`, `DateBucket`, `ExportFormat`
  (PDF/Excel).
- **Invariantes:** los read models se reconstruyen replayando eventos; nunca
  escriben en los contextos origen.
- **Emite:** `report.generated`, `kpi.threshold-crossed` (alimenta Automation).
- **Consume:** eventos financieros, de obra, de stock y de horas (todos).
- **Necesidad:** dashboards de obra/financieros, KPIs propios, export; detectar
  desviaciones, rentabilidad y cuellos de botella. *(roadmap 4.1 / 4.2)*

### Audit & Activity Trail (`erpify.backoffice.audit.*`)

- **Responsabilidad:** trazabilidad de quién cambió qué y cuándo. Suscriptor
  **universal**; la base (`StoredDomainEvent`) ya existe.
- **Agregados/entidades:** `AuditEntry` (proyección de `StoredDomainEvent`) ·
  `RetentionPolicy`.
- **Invariantes:** registro append-only; consultas tenant-scoped; integridad
  verificable (tamper-evidence).
- **Emite:** — (consume y expone).
- **Consume:** **todos** los domain events (vía el store/outbox compartido).
- **Necesidad:** saber quién cambió qué, recuperar historial, exportar para
  auditoría. *(roadmap 0.7)*

### Integration Layer (`erpify.backoffice.integration.*`)

- **Responsabilidad:** puente con el exterior. ACL en ambos sentidos: traduce
  eventos internos → sistemas externos y webhooks externos → comandos internos.
- **Agregados/entidades:** ⬢ `Webhook` (in/out) · `Connector` (contabilidad,
  banco…) · `ImportJob`/`ExportJob`.
- **Value objects:** `EndpointUrl` (validada/same-origin donde aplique), `Secret`
  (nunca logueado), `Mapping`.
- **Invariantes:** entregas idempotentes y con reintentos; payloads saneados de
  secretos.
- **Emite:** `webhook.delivered`, `import.completed`, `export.completed`.
- **Consume:** eventos suscritos para reenviar fuera.
- **Necesidad:** conectar con contabilidad/banco, importar/exportar, eventos
  entrantes/salientes. *(roadmap 5.1)*

### Plugin / Extension (`erpify.backoffice.extensions.*`)

- **Responsabilidad:** registro de módulos activables por tenant con hooks de
  extensión sobre el bus de eventos.
- **Agregados/entidades:** ⬢ `ModuleRegistration` · `ExtensionHook`.
- **Invariantes:** habilitar/deshabilitar por tenant no rompe el core; los hooks se
  enganchan a eventos, no al `Domain/` ajeno.
- **Emite:** `module.enabled`, `module.disabled`.
- **Consume:** `feature-flags.*`, `organization.company.registered`.
- **Necesidad:** añadir/quitar módulos según la empresa, pagar por lo que se usa.
  *(roadmap 5.2)*

---

## Catálogo de eventos (contrato de integración)

Tabla maestra de los eventos **de integración** que cruzan fronteras. El payload
lista los campos clave (IDs siempre UUID v7); el resto es interno al emisor.

| Evento                                     | Emite             | Payload (clave)                                    | Consumidores                                        |
|--------------------------------------------|-------------------|----------------------------------------------------|-----------------------------------------------------|
| `organization.company.registered`          | Organization      | companyId, name, locale                            | todos (provisión tenant)                            |
| `organization.user.invited`                | Organization      | userId, companyId, role                            | Commercial, Projects, Notifications                 |
| `party.registered`                         | Party (kernel)    | partyId, legalName, taxId, kinds[]                 | Commercial, Procurement, Workforce, Finance         |
| `commercial.opportunity.won`               | Commercial        | opportunityId, partyId, amount, companyId          | CommercialProposal, Projects, Finance, Commissions  |
| `commercial.campaign.launched`             | Commercial        | campaignId, channel, companyId                     | Reporting, Notifications                            |
| `tender.awarded`                           | TenderManagement  | tenderId, partyId, studyId, proposalId             | Projects, Budgeting, Notifications                  |
| `proposal.accepted`                        | CommercialProposal| proposalId, opportunityId/tenderId, budgetId, total| Projects, Finance                                   |
| `projects.project.created`                 | Projects          | projectId, partyId, companyId                      | Budgeting, Procurement, Workforce, Resources, SiteOps, DMS |
| `projects.task.completed`                  | Projects          | taskId, projectId                                  | Workforce, Reporting                                |
| `budgeting.budget.approved`                | Budgeting         | budgetId, projectId, total                         | Procurement, Finance, CommercialProposal            |
| `site-operations.certification.approved`   | SiteOperations    | certificationId, projectId, period, amount         | Finance, Reporting                                  |
| `site-operations.incident.raised`          | SiteOperations    | incidentId, projectId, severity                    | Notifications, Automation                           |
| `procurement.goods.received`               | Procurement       | poId, projectId, lines[]                           | Finance, Reporting                                  |
| `procurement.delivery.scheduled`           | Procurement       | poId, projectId, expectedDate                      | Notifications, SiteOperations                       |
| `procurement.stock.below-reorder-point`    | Procurement       | materialId, locationId                             | Notifications, Automation                           |
| `workforce.time.logged`                    | Workforce         | timeEntryId, taskId, projectId, hours, cost        | Finance, Projects, Cost Allocation, Reporting       |
| `resource.assigned`                        | Resources         | assignmentId, resourceId, projectId, rate          | Finance, Cost Allocation, SiteOperations            |
| `finance.invoice.issued`                   | Finance           | invoiceId, partyId, projectId, total               | Reporting, Notifications                            |
| `finance.invoice.paid`                     | Finance           | invoiceId, partyId, amount                         | Commissions, Reporting, Notifications               |
| `finance.payment.received`                 | Finance           | paymentId, invoiceId, amount                       | Reporting, Notifications                            |
| `finance.project.budget-exceeded`          | Finance           | projectId, budget, actual                          | Notifications, Automation, Reporting                |
| `cost-allocation.cost.allocated`           | Cost Allocation   | allocationEntryId, costCenterId, projectId, amount | Reporting, Finance                                  |
| `commissions.commission.accrued`           | Commissions       | accrualId, beneficiaryId, sourceId, amount         | Finance, Reporting, Notifications                   |
| `*` (cualquiera)                           | todos             | —                                                  | Audit, Reporting, Automation, Notifications         |

> Cada consumidor traduce el evento ajeno en su propio modelo mediante un ACL; no
> comparte tipos con el emisor más allá de este contrato.

## Cómo mantener este doc

- Es **diseño**, no código: se edita cuando cambia el **mapa** (contexto nuevo,
  agregado nuevo, evento de integración nuevo), no en cada ajuste de
  implementación.
- Al construir un contexto, su evento de integración debe coincidir con la fila del
  **catálogo de eventos**; si cambia el contrato, actualiza la tabla en el mismo PR.
- El estado de avance (hecho/en curso/pendiente) **no** se duplica aquí: vive en
  [`product-roadmap.md`](product-roadmap.md) y en el `roadmap.ts` que alimenta la
  página `/backoffice/roadmap`.
