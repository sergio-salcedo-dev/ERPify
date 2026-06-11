# ERPify — Mapa de bounded contexts (alta granularidad)

> Artefacto de **roadmap / diseño**, no de implementación. Modela los bounded
> contexts de ERPify con detalle de agregados, value objects, invariantes y —
> sobre todo— los **eventos** por los que se integran entre sí. Es el "qué" y el
> "cómo se hablan"; el "cuándo" (secuencia de entrega) vive en
> [`product-roadmap.md`](product-roadmap.md), y el patrón de código real está en
> la vertical de referencia `Backoffice/Bank` descrita en
> [`architecture-api.md`](architecture-api.md).
>
> **Nada aquí implica código todavía.** Cuando un contexto entre en construcción
> seguirá la estrategia de datos por etapas de
> [`product-roadmap.md`](product-roadmap.md#estrategia-de-implementación--modelado-de-datos-muy-importante).

## Principios (vinculantes para todo contexto)

1. **Modular monolith, una sola DB física.** Separación **lógica** estricta:
   schema por bounded context o convención de nombres fuerte (`<contexto>_<tabla>`).
2. **Sin FKs cross-context.** Una referencia a otro contexto es un **UUID v7**
   sin constraint de FK física. La integridad cross-context se valida por
   eventos/políticas, no por la DB.
3. **Sin cross-repository queries.** Un contexto nunca consulta el repositorio
   de otro; obtiene datos ajenos por un Application service publicado o por un
   **read model** alimentado por eventos.
4. **Todo pasa por eventos desde el inicio.** Cada cambio de estado de un
   agregado **registra un domain event** (`AggregateRoot::record`), se persiste
   en la tabla de auditoría/outbox (`PersistDomainEventMiddleware` →
   `StoredDomainEvent`) y se publica por Messenger. La integración entre
   contextos es **siempre** vía esos eventos.
5. **Convención de nombres de evento:** `erpify.<contexto>.<entidad>.<acción>`
   (ej. `erpify.backoffice.crm.opportunity.won`). Pasado, en minúsculas.
6. **Identidad y borrado:** UUID v7 asignado en la capa de aplicación;
   **hard delete por defecto** (estados de negocio como `archived`/`cancelled`
   se modelan como estado del agregado, no como soft delete) — ver
   [`rules/database.md`](rules/database.md).
7. **Errores:** excepciones de dominio con markers (`NotFound` → 404,
   `Conflict` → 409, etc.) y respuesta RFC 9457.

> Los principios 1–4 son **vinculantes** (defecto que bloquea revisión), no
> orientativos — enunciado completo en
> [`rules/database.md`](rules/database.md#bounded-context-data-isolation-modular-monolith--binding)
> y [`architecture-api.md`](architecture-api.md). Un gate estático (FK / import
> cross-context) está registrado como deferred work hasta que se implemente.

> **Eventos de dominio vs. de integración.** Un *domain event* es interno al
> contexto (rico, puede cambiar). Un *integration event* es el contrato público
> que otros contextos consumen (estable, payload mínimo de IDs + datos
> imprescindibles). Un contexto downstream traduce el evento ajeno en su propio
> lenguaje mediante un **Anti-Corruption Layer (ACL)** — nunca importa el evento
> del otro tal cual en su `Domain/`.

## Context map (relaciones)

```text
                 ┌─────────────────────────────────────────────┐
                 │  Shared Kernel (identidad, eventos, auditoría,│
                 │  UUID, ProblemDetails, búsqueda/paginación)   │
                 └─────────────────────────────────────────────┘
                                   ▲ usado por todos
   Organization ──(tenant/usuarios, upstream de TODO)──► (todos)
        │
        ▼ eventos
      CRM ──OpportunityWon──► Projects ──ProjectCreated──► Budgeting
        │                        │  │                          │
        │                        │  └─► Workforce              ▼ BudgetApproved
        │                        ▼                          Procurement
        │                   Procurement ◄──────────────────────┘
        ▼                        │
     Finance ◄──ProjectCostAccrued / TimeLogged / GoodsReceived──┘
        │
        ▼ InvoiceIssued / PaymentReceived
   Reporting (read models)   Notifications (fan-out)   Audit (todos los eventos)
        ▲                          ▲                        ▲
        └───── suscriptores genéricos del bus de eventos ───┘

   Automation ── escucha eventos de cualquier contexto y dispara acciones (comandos)
   Integration ── traduce eventos ⇄ sistemas externos (webhooks/conectores)
```

**Tipos de relación:**

| Upstream → Downstream | Tipo | Integración |
|---|---|---|
| Organization → todos | Customer/Supplier | tenant id + `UserInvited`/`CompanyRegistered` |
| CRM → Projects, Finance | Customer/Supplier (ACL en downstream) | `OpportunityWon` |
| Projects → Budgeting, Procurement, Workforce, Finance | Partnership | `ProjectCreated`, `TaskCompleted` |
| Budgeting → Procurement, Finance | Customer/Supplier | `BudgetApproved` |
| Procurement → Finance | Customer/Supplier | `GoodsReceived`, `StockMovementRegistered` |
| Workforce → Finance, Projects | Customer/Supplier | `TimeLogged` |
| Finance → Reporting, Notifications | Publisher/Subscriber | `InvoiceIssued`, `PaymentReceived` |
| (todos) → Audit, Notifications, Reporting | Publisher/Subscriber (conformist) | bus de eventos |
| Automation → (todos) | Orquestador | escucha eventos / emite comandos |

---

## Contextos

Para cada uno: **responsabilidad**, **agregados/entidades** (raíz marcada con ⬢),
**value objects**, **invariantes**, **eventos que emite**, **eventos que
consume** (de quién), **read models** propios y la **necesidad de usuario** que
cubre (resumen; detalle en `roadmap.ts`).

### Shared Kernel (no es un contexto de negocio)

- **Responsabilidad:** primitivas compartidas — identidad (`Identifiable`,
  `Uuid` v7), `AggregateRoot`, `DomainEvent`, store/outbox de eventos
  (`StoredDomainEvent`), `ProblemDetails`, criteria de búsqueda/paginación,
  `NormalizedText`, validación, mailer.
- **Regla:** estable y mínimo. Nada específico de un dominio entra aquí. Lo que
  hoy vive en `api/src/Shared` ya es la base.

### Organization (`erpify.backoffice.organization.*`)

- **Responsabilidad:** la **frontera multi-tenant**. Empresas, usuarios, equipos
  y la pertenencia usuario↔empresa. Upstream de todos.
- **Agregados/entidades:** ⬢ `Company` · ⬢ `User` · `Team` · `Membership`
  (user↔company↔role) · `Invitation`.
- **Value objects:** `CompanyName` (NormalizedText), `Email`, `TaxId` (NIF/CIF),
  `Locale` (es/en), `Role` (enum), `PermissionSet`.
- **Invariantes:** email único por empresa; una empresa tiene ≥1 owner; no se
  puede borrar el último owner; el `company_id` es obligatorio en todo agregado
  tenant-owned del resto de contextos.
- **Emite:** `company.registered`, `company.archived`, `user.invited`,
  `user.activated`, `user.role-changed`, `team.created`, `member.added`,
  `member.removed`.
- **Consume:** — (raíz; solo Shared).
- **Read models:** directorio de usuarios por empresa.
- **Necesidad:** gestionar usuarios/equipos, invitar y asignar roles, perfil y
  preferencias. *(roadmap 1.1)*

### CRM (`erpify.frontoffice.crm.*`)

- **Responsabilidad:** captación y gestión comercial hasta el cierre.
- **Agregados/entidades:** ⬢ `Lead` · ⬢ `Contact` · ⬢ `Account` (cliente/empresa
  CRM) · ⬢ `Opportunity` (deal) · `Activity` (llamada/tarea/email) · `Note` ·
  `Attachment` · `Pipeline`/`Stage` (configurable).
- **Value objects:** `PipelineStage`, `Money` + `Currency`, `Probability`,
  `ContactChannel`.
- **Invariantes:** una oportunidad pertenece a un `Account`; su `Stage` avanza
  por la máquina del `Pipeline`; cerrar como ganada exige importe y cliente.
- **Emite:** `lead.captured`, `lead.qualified`, `opportunity.created`,
  `opportunity.stage-changed`, `opportunity.won`, `opportunity.lost`,
  `activity.logged`.
- **Consume:** `organization.company.registered` (provisión de tenant),
  `organization.user.invited` (propietario comercial).
- **Read models:** pipeline board, embudo por fase, próximas actividades.
- **Necesidad:** todo el cliente en un sitio, seguir oportunidades, no olvidar
  seguimientos. *(roadmap 1.2)*

### Projects / Construction (`erpify.backoffice.projects.*`)

- **Responsabilidad:** gestión de obra de principio a fin (vertical core).
- **Agregados/entidades:** ⬢ `Project` (obra) · `Phase` · `Milestone` · ⬢ `Task`
  · `Assignment` · `ProgressEntry`.
- **Value objects:** `ProjectStatus` (lifecycle), `Percentage` (avance),
  `DateRange`, `ProjectCode`.
- **Invariantes:** una tarea pertenece a una fase; el avance del proyecto deriva
  de sus fases/tareas; no se cierra una obra con tareas abiertas.
- **Emite:** `project.created`, `project.status-changed`, `phase.started`,
  `phase.completed`, `task.assigned`, `task.completed`, `progress.updated`.
- **Consume:** `crm.opportunity.won` (ACL → crea borrador de obra),
  `organization.member.added` (asignables).
- **Read models:** tablero de obra, timeline de hitos, carga por persona.
- **Necesidad:** controlar la obra, ver avance real, saber quién hace qué.
  *(roadmap 1.3)*

### Budgeting & Estimation (`erpify.backoffice.budgeting.*`)

- **Responsabilidad:** presupuestos y estimación por obra, con versionado y
  márgenes.
- **Agregados/entidades:** ⬢ `Budget` · `BudgetLine` (partida) · `EstimationTemplate`
  · `BudgetVersion`.
- **Value objects:** `Money`/`Currency`, `Markup`/`Margin`, `Quantity`+`Unit`,
  `CostType`.
- **Invariantes:** un presupuesto referencia una obra (UUID, sin FK); el total
  es la suma de líneas × cantidades; aprobar congela una versión inmutable.
- **Emite:** `budget.created`, `budget.line-added`, `budget.version-created`,
  `budget.approved`, `budget.rejected`.
- **Consume:** `projects.project.created` (a qué obra presupuestar).
- **Read models:** previsto vs. real por partida, margen por obra.
- **Necesidad:** presupuestar por obra, reutilizar plantillas, comparar previsto
  vs. real y margen. *(roadmap 1.4)*

### Procurement & Inventory (`erpify.backoffice.procurement.*`)

- **Responsabilidad:** proveedores, compras y control de stock por obra.
- **Agregados/entidades:** ⬢ `Supplier` · ⬢ `PurchaseOrder` (+ `PurchaseOrderLine`)
  · ⬢ `Material` (catálogo) · `StockLocation` · ⬢ `StockMovement`
  (in/out/transfer) · `Reservation` (material reservado para obra).
- **Value objects:** `Sku`, `Quantity`+`Unit`, `Money`/`Currency`,
  `MovementType`, `ReorderPoint`.
- **Invariantes:** el stock por ubicación nunca es negativo; una reserva no
  supera el stock disponible; recibir un pedido genera un `StockMovement` de
  entrada.
- **Emite:** `supplier.registered`, `purchase-order.issued`,
  `goods.received`, `stock-movement.registered`, `material.reserved`,
  `stock.below-reorder-point`.
- **Consume:** `budgeting.budget.approved` (qué comprar), `projects.project.created`
  (a qué obra imputar/reservar).
- **Read models:** stock por almacén, materiales por obra, pedidos pendientes.
- **Necesidad:** saber qué material tengo y dónde, pedir a proveedores, reservar
  por obra, avisar de rotura de stock. *(roadmap 1.5)*

### Workforce & Time Tracking (`erpify.backoffice.workforce.*`)

- **Responsabilidad:** personas, subcontratas y partes de trabajo con coste.
- **Agregados/entidades:** ⬢ `Employee` · ⬢ `Subcontractor` · ⬢ `WorkLog` (parte)
  · `TimeEntry` · `Schedule`.
- **Value objects:** `Hours`, `LaborRate` (`Money`/hora), `Shift`, `Skill`.
- **Invariantes:** una imputación de horas referencia tarea+persona (UUID); las
  horas de un parte cerrado no se editan; el coste de mano de obra = horas ×
  tarifa.
- **Emite:** `employee.registered`, `worklog.submitted`, `time.logged`,
  `schedule.published`.
- **Consume:** `projects.task.assigned` (contra qué imputar),
  `organization.member.added`.
- **Read models:** horas por obra/tarea, coste de mano de obra, planificación.
- **Necesidad:** registrar horas por obra, partes de empleados/subcontratas,
  coste real de mano de obra, planificar carga. *(roadmap 2.1)*

### Document Management (`erpify.backoffice.dms.*`)

- **Responsabilidad:** documentos ligados a entidades (obra, cliente…), con
  versionado y permisos.
- **Agregados/entidades:** ⬢ `Document` · `DocumentVersion` · `DocumentTemplate`
  · `AccessGrant`.
- **Value objects:** `FileRef` (storage key + content hash, ya existe el patrón
  en Bank), `MimeType`, `DocumentKind`.
- **Invariantes:** cada documento referencia su entidad por UUID (sin FK); la
  última versión es inmutable una vez publicada; el borrado físico es posible
  (GDPR).
- **Emite:** `document.uploaded`, `document.version-added`, `document.shared`.
- **Consume:** eventos de cualquier contexto que adjunte documentos (p. ej.
  `projects.project.created` para la carpeta de obra).
- **Read models:** documentos por obra/cliente, última versión.
- **Necesidad:** documentos de la obra juntos, última versión siempre, control
  de quién ve qué. *(roadmap 2.2)*

### Finance (`erpify.backoffice.finance.*`)

- **Responsabilidad:** núcleo financiero: facturación, cobros/pagos, tesorería,
  coste por proyecto. **Banks/Treasury ya entregado** como vertical de referencia.
- **Agregados/entidades:** ⬢ `Bank` *(hecho)* · ⬢ `BankAccount` · ⬢ `Invoice`
  (+ `InvoiceLine`) · ⬢ `Payment` · `CostEntry` (imputación a obra) ·
  `TaxRate`.
- **Value objects:** `Money`/`Currency` *(existe)*, `Iban`/`Bic`, `TaxId`,
  `InvoiceStatus`, `DueDate`.
- **Invariantes:** una factura referencia cliente y (opcional) obra por UUID; el
  total = líneas + impuestos; un pago no supera el pendiente; no se edita una
  factura emitida (se rectifica).
- **Emite:** `invoice.drafted`, `invoice.issued`, `payment.received`,
  `project.cost-accrued`, `bank.created`/`updated`/`deleted` *(ya activos)*.
- **Consume:** `crm.opportunity.won` (borrador de factura),
  `procurement.goods.received` + `workforce.time.logged` (coste por obra),
  `budgeting.budget.approved`.
- **Read models:** cashflow, coste/margen por proyecto, aging de cobros.
- **Necesidad:** facturar a cliente/proveedor, seguir cobros y pagos, ver
  cashflow, saber margen por proyecto. *(roadmap 2.3)*

### Automation Engine (`erpify.backoffice.automation.*`)

- **Responsabilidad:** reglas y workflows que reaccionan a eventos del dominio y
  disparan acciones. **Orquestador**: escucha eventos de cualquier contexto y
  emite comandos (nunca toca el `Domain/` de otro directamente).
- **Agregados/entidades:** ⬢ `AutomationRule` (trigger + condición + acciones) ·
  ⬢ `Workflow` (máquina de estados / DAG) · `WorkflowInstance` · `ActionLog`.
- **Value objects:** `EventSelector`, `Condition` (IF/THEN), `ActionSpec`
  (enviar email, crear tarea, actualizar estado), `RetryPolicy`.
- **Invariantes:** una regla es idempotente por `eventId`; las acciones con
  efectos externos llevan compensación (saga ligera).
- **Emite:** `automation.rule-fired`, `automation.action-executed`,
  `automation.action-failed`.
- **Consume:** **cualquier** evento (suscriptor genérico configurable).
- **Necesidad:** automatizar tareas repetitivas, diseñar flujos sin programar,
  que el sistema cree tareas/avise solo. *(roadmap 3.1)*

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
- **Necesidad:** enterarse al momento, elegir avisos y canal, verlos en un
  centro. *(roadmap 3.2)*

### Feature Flags (`erpify.backoffice.feature-flags.*`)

- **Responsabilidad:** activar funciones por tenant/usuario y rollout gradual.
- **Agregados/entidades:** ⬢ `FeatureFlag` · `FlagRule` (targeting) · `Rollout`.
- **Value objects:** `FlagKey`, `TargetingRule`, `RolloutPercentage`.
- **Invariantes:** evaluación determinista por (tenant, usuario, flag).
- **Emite:** `feature-flag.toggled`, `rollout.advanced`.
- **Consume:** `organization.company.registered` (defaults por tenant).
- **Necesidad:** activar/desactivar funciones por empresa sin desplegar, probar
  con un grupo antes que todos. *(roadmap 3.3)*

### Reporting & Analytics (`erpify.backoffice.reporting.*`)

- **Responsabilidad:** KPIs, dashboards y analítica. **Solo read models**: no
  posee estado de negocio, proyecta eventos de otros contextos.
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
  auditoría. *(roadmap 0.9)*

### Integration Layer (`erpify.backoffice.integration.*`)

- **Responsabilidad:** puente con el exterior. ACL en ambos sentidos:
  traduce eventos internos → sistemas externos y webhooks externos → comandos
  internos.
- **Agregados/entidades:** ⬢ `Webhook` (in/out) · `Connector` (contabilidad,
  banco…) · `ImportJob`/`ExportJob`.
- **Value objects:** `EndpointUrl` (validada/same-origin donde aplique),
  `Secret` (nunca logueado), `Mapping`.
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
- **Invariantes:** habilitar/deshabilitar por tenant no rompe el core; los hooks
  se enganchan a eventos, no al `Domain/` ajeno.
- **Emite:** `module.enabled`, `module.disabled`.
- **Consume:** `feature-flags.*`, `organization.company.registered`.
- **Necesidad:** añadir/quitar módulos según la empresa, pagar por lo que se usa.
  *(roadmap 5.2)*

---

## Catálogo de eventos (contrato de integración)

Tabla maestra de los eventos **de integración** que cruzan fronteras. El payload
lista los campos clave (IDs siempre UUID v7); el resto es interno al emisor.

| Evento | Emite | Payload (clave) | Consumidores |
|---|---|---|---|
| `organization.company.registered` | Organization | companyId, name, locale | todos (provisión tenant) |
| `organization.user.invited` | Organization | userId, companyId, role | CRM, Projects, Notifications |
| `crm.opportunity.won` | CRM | opportunityId, accountId, amount, companyId | Projects, Finance |
| `projects.project.created` | Projects | projectId, accountId, companyId | Budgeting, Procurement, Workforce, DMS |
| `projects.task.completed` | Projects | taskId, projectId | Workforce, Reporting |
| `budgeting.budget.approved` | Budgeting | budgetId, projectId, total | Procurement, Finance |
| `procurement.goods.received` | Procurement | poId, projectId, lines[] | Finance, Reporting |
| `procurement.stock.below-reorder-point` | Procurement | materialId, locationId | Notifications, Automation |
| `workforce.time.logged` | Workforce | timeEntryId, taskId, projectId, hours, cost | Finance, Projects, Reporting |
| `finance.invoice.issued` | Finance | invoiceId, accountId, projectId, total | Reporting, Notifications |
| `finance.payment.received` | Finance | paymentId, invoiceId, amount | Reporting, Notifications |
| `*` (cualquiera) | todos | — | Audit, Reporting, Automation, Notifications |

> Cada consumidor traduce el evento ajeno en su propio modelo mediante un ACL;
> no comparte tipos con el emisor más allá de este contrato.

## Cómo mantener este doc

- Es **diseño**, no código: se edita cuando cambia el **mapa** (un contexto
  nuevo, un agregado nuevo, un evento de integración nuevo), no en cada ajuste de
  implementación.
- Al construir un contexto, su evento de integración debe coincidir con la fila
  del **catálogo de eventos**; si cambia el contrato, actualiza la tabla en el
  mismo PR.
- El estado de avance (qué está hecho/en curso/pendiente) **no** se duplica aquí:
  vive en [`product-roadmap.md`](product-roadmap.md) y en el `roadmap.ts` que
  alimenta la página `/backoffice/roadmap`.
