/**
 * Single source of truth for the "About ERPify" page ({@link AboutPage} at
 * `/backoffice/about`) — what ERPify is, who it serves, and the domain model
 * (bounded contexts, ubiquitous language and the integration events that wire
 * them). It mirrors `docs/bounded-contexts.md` and `docs/project-overview.md`
 * the same way `roadmap.ts` mirrors `docs/product-roadmap.md`: plain typed data,
 * no React or framework imports, so the page can never drift from the model and
 * a future export (API seed, slide deck) can consume the same shape.
 *
 * Narrative fields are Spanish (matching `roadmap.ts` / `flows.ts`) until the
 * i18n module makes the surface translatable; context/aggregate/event
 * identifiers stay in the codebase's ubiquitous language.
 *
 * Optional `flows` is a growth seam: the page can later render per-context step
 * flows without reshaping the data (today flows live at `/backoffice/docs/flow`).
 */

export interface Persona {
  name: string;
  focus: string;
}

export interface ErpifyOverview {
  tagline: string;
  whatItIs: string;
  whatFor: string;
  strategicNorth: string;
  personas: Persona[];
}

/** Coarse area used to group context cards on the page. */
export type ContextArea = "commercial" | "site" | "operations" | "finance" | "platform";

export interface UbiquitousTerm {
  term: string;
  definition: string;
}

export interface ContextFlowStep {
  title: string;
  detail: string;
}

export interface DomainContext {
  /** Stable key; matches `roadmap.ts` `boundedContext` where one exists. */
  key: string;
  name: string;
  area: ContextArea;
  responsibility: string;
  /** Domain terms the user speaks — the ubiquitous language of this context. */
  ubiquitousLanguage: UbiquitousTerm[];
  /** Aggregate roots / key entities (display only). */
  aggregates: string[];
  emits: string[];
  consumes: string[];
  /** Roadmap module code(s) that build this context, when decided. */
  roadmap?: string;
  /** Growth seam — per-context step flow; unused in v1. */
  flows?: ContextFlowStep[];
}

/** A boundary-crossing integration event (mirror of the catalog in the doc). */
export interface IntegrationEvent {
  name: string;
  emitter: string;
  payload: string;
  consumers: string;
}

export const erpifyOverview: ErpifyOverview = {
  tagline:
    "El ERP del sector de la construcción — del lead a la certificación, todo guiado por eventos.",
  whatItIs:
    "ERPify es un ERP verticalizado para empresas de construcción (obra pública y privada) que cubre el ciclo de vida completo de una obra: prospección comercial → estudio económico → ejecución en campo → facturación → control de costes → cierre financiero.",
  whatFor:
    "Gestiona actores, proyectos, presupuestos, compras, stock, maquinaria, tesorería, certificaciones y reparto de costes en un único sistema que habla el lenguaje del sector, no la jerga genérica de un ERP horizontal.",
  strategicNorth:
    "ERPify no compite como 'un conjunto de módulos' (CRM + facturación + obras) ni como un Odoo verticalizado, sino como un motor de ejecución de procesos de construcción: automatización basada en eventos + reglas + datos reales de obra. Todo emite eventos y todo es automatizable; la obra es la fuente de datos y el Automation Engine, el diferenciador.",
  personas: [
    {
      name: "Ingeniero de caminos",
      focus:
        "Herramientas técnicas: configurador de pavimentos, estudios económicos y la ejecución de obra (mediciones, certificaciones, calidad, PRL).",
    },
    {
      name: "Gestor comercial",
      focus:
        "Licitaciones públicas, propuestas a cliente, embudo de oportunidades y campañas de marketing.",
    },
    {
      name: "Gestor financiero",
      focus: "Tesorería, cashflow, reparto de costes directos e indirectos y facturación.",
    },
    {
      name: "Desarrollador",
      focus:
        "Mantiene y extiende el sistema sobre DDD + arquitectura hexagonal, contexto a contexto.",
    },
  ],
};

export const AREA_LABEL: Record<ContextArea, string> = {
  commercial: "Comercial",
  site: "Obra",
  operations: "Operaciones",
  finance: "Finanzas",
  platform: "Plataforma",
};

/** Display order of the areas on the page. */
export const AREA_ORDER: readonly ContextArea[] = [
  "commercial",
  "site",
  "operations",
  "finance",
  "platform",
];

export const domainContexts: DomainContext[] = [
  {
    key: "commercial",
    name: "Commercial",
    area: "commercial",
    responsibility:
      "Prospección, embudo de venta privada e historial de relación con el cliente. Sustituye al 'CRM' genérico con lenguaje del dominio.",
    ubiquitousLanguage: [
      { term: "Lead", definition: "Contacto potencial sin cualificar todavía." },
      { term: "Opportunity", definition: "Oportunidad de venta privada en el embudo (deal)." },
      { term: "Campaign", definition: "Acción de marketing a la que se atribuyen leads." },
      { term: "Account", definition: "Rol comercial de una Party (cliente)." },
    ],
    aggregates: ["Lead", "Opportunity", "Account", "Campaign", "Pipeline"],
    emits: ["opportunity.won", "lead.qualified", "campaign.launched"],
    consumes: ["organization.user.invited", "party.registered"],
    roadmap: "1.2",
  },
  {
    key: "tender",
    name: "TenderManagement",
    area: "commercial",
    responsibility:
      "Seguimiento de licitaciones públicas con su ciclo propio: convocatoria → estudio → propuesta → adjudicación, con plazos y documentación reglada.",
    ubiquitousLanguage: [
      {
        term: "PublicTender",
        definition: "Licitación pública a la que la empresa puede presentarse.",
      },
      { term: "BidDecision", definition: "Decisión de presentarse o no (bid / no-bid)." },
      { term: "AwardStatus", definition: "Resultado terminal: ganada, perdida o desierta." },
    ],
    aggregates: ["PublicTender", "SubmissionDeadline", "TenderDocument", "BidDecision"],
    emits: ["tender.registered", "tender.awarded", "tender.lost"],
    consumes: ["budgeting.study.completed", "proposal.issued"],
    roadmap: "1.2b",
  },
  {
    key: "proposal",
    name: "CommercialProposal",
    area: "commercial",
    responsibility:
      "Generación de la oferta económica y técnica cara a cliente, con validez, distinta del presupuesto interno.",
    ubiquitousLanguage: [
      { term: "Proposal", definition: "Oferta enviada al cliente (técnica + económica)." },
      { term: "ValidityPeriod", definition: "Plazo durante el que la oferta es válida." },
      {
        term: "CostEstimate",
        definition: "Resumen económico cara a cliente, derivado de un Budget.",
      },
    ],
    aggregates: ["Proposal", "ProposalLine", "CostEstimate"],
    emits: ["proposal.issued", "proposal.accepted", "proposal.rejected"],
    consumes: ["budgeting.budget.approved", "commercial.opportunity.won"],
    roadmap: "1.2c",
  },
  {
    key: "operations",
    name: "Projects",
    area: "site",
    responsibility:
      "Gestión de la obra de principio a fin: estructura (fases, hitos, tareas) y avance. La ejecución física vive en SiteOperations.",
    ubiquitousLanguage: [
      { term: "Project", definition: "La obra, raíz del trabajo de construcción." },
      { term: "Phase", definition: "Etapa de la obra que agrupa tareas." },
      { term: "ProgressEntry", definition: "Registro de avance del que deriva el % de la obra." },
    ],
    aggregates: ["Project", "Phase", "Milestone", "Task"],
    emits: ["project.created", "phase.completed", "task.completed"],
    consumes: ["proposal.accepted", "tender.awarded", "commercial.opportunity.won"],
    roadmap: "1.3",
  },
  {
    key: "site-operations",
    name: "SiteOperations",
    area: "site",
    responsibility:
      "El día a día de la obra en campo: diario, mediciones, certificaciones (a origen), incidencias, calidad y seguridad (PRL). Núcleo del negocio.",
    ubiquitousLanguage: [
      {
        term: "Certification",
        definition: "Certificación de avance que habilita la factura a origen.",
      },
      { term: "Measurement", definition: "Medición de obra; aprobada, no se edita." },
      { term: "SiteDiary", definition: "Parte diario de lo ejecutado en la obra." },
      { term: "SafetyRecord", definition: "Registro de seguridad y salud (PRL)." },
    ],
    aggregates: ["SiteDiary", "Certification", "Measurement", "Incident", "QualityCheck"],
    emits: ["certification.approved", "incident.raised", "measurement.approved"],
    consumes: ["projects.project.created", "workforce.time.logged", "resource.assigned"],
    roadmap: "1.3b",
  },
  {
    key: "operations-budgeting",
    name: "Budgeting & Configurator",
    area: "operations",
    responsibility:
      "Presupuestos por obra con versionado y márgenes, e incluye el configurador por capas (pavimentos) que calcula coste y genera partidas.",
    ubiquitousLanguage: [
      { term: "Budget", definition: "Presupuesto de una obra; aprobarlo congela una versión." },
      {
        term: "Configuration",
        definition: "Estructura por capas que deriva su coste (configurador).",
      },
      { term: "PreBidStudy", definition: "Estudio económico previo a presentar una licitación." },
    ],
    aggregates: ["Budget", "BudgetLine", "Configuration", "PreBidStudy"],
    emits: ["budget.approved", "study.completed", "configuration.priced"],
    consumes: ["projects.project.created", "tender.registered"],
    roadmap: "1.4 · 1.6",
  },
  {
    key: "operations-procurement",
    name: "Procurement & Inventory",
    area: "operations",
    responsibility: "Proveedores, compras y control de stock por obra.",
    ubiquitousLanguage: [
      { term: "PurchaseOrder", definition: "Pedido a un proveedor." },
      { term: "StockMovement", definition: "Entrada / salida / traspaso de material." },
      { term: "Reservation", definition: "Material reservado para una obra concreta." },
    ],
    aggregates: ["Supplier", "PurchaseOrder", "Material", "StockMovement"],
    emits: ["goods.received", "delivery.scheduled", "stock.below-reorder-point"],
    consumes: ["budgeting.budget.approved", "projects.project.created"],
    roadmap: "1.5",
  },
  {
    key: "resources",
    name: "Resources (Plant & Equipment)",
    area: "operations",
    responsibility:
      "Maquinaria y vehículos como activos: asignación a obra, coste interno y mantenimiento. Ni personas (Workforce) ni material fungible (Procurement).",
    ubiquitousLanguage: [
      { term: "Machine", definition: "Activo de maquinaria de la empresa." },
      { term: "Assignment", definition: "Asignación de un activo a una obra/tarea por periodo." },
      { term: "InternalRate", definition: "Tarifa interna (coste por hora o día) del activo." },
    ],
    aggregates: ["Machine", "Vehicle", "Assignment", "MaintenanceLog"],
    emits: ["resource.assigned", "resource.maintenance-due"],
    consumes: ["projects.project.created"],
    roadmap: "2.8",
  },
  {
    key: "operations-workforce",
    name: "Workforce & Time Tracking",
    area: "operations",
    responsibility: "Personas, subcontratas y partes de trabajo con coste por hora.",
    ubiquitousLanguage: [
      { term: "WorkLog", definition: "Parte de trabajo de un empleado o subcontrata." },
      { term: "LaborRate", definition: "Coste por hora de mano de obra." },
    ],
    aggregates: ["Employee", "Subcontractor", "WorkLog", "TimeEntry"],
    emits: ["time.logged", "worklog.submitted"],
    consumes: ["projects.task.assigned", "organization.member.added"],
    roadmap: "2.1",
  },
  {
    key: "backoffice",
    name: "Finance",
    area: "finance",
    responsibility:
      "Núcleo financiero: facturación, cobros/pagos, tesorería y coste por proyecto. Banks/Treasury es la vertical de referencia ya entregada.",
    ubiquitousLanguage: [
      {
        term: "Invoice",
        definition: "Factura de cliente o proveedor; emitida no se edita, se rectifica.",
      },
      { term: "Payment", definition: "Cobro o pago; no supera el pendiente." },
      { term: "CostEntry", definition: "Imputación de coste a una obra." },
    ],
    aggregates: ["Bank", "BankAccount", "Invoice", "Payment", "CostEntry"],
    emits: ["invoice.issued", "invoice.paid", "project.budget-exceeded"],
    consumes: ["proposal.accepted", "certification.approved", "goods.received"],
    roadmap: "2.3",
  },
  {
    key: "cost-allocation",
    name: "Cost Allocation",
    area: "finance",
    responsibility:
      "Centros de coste y reglas de imputación auditables que reparten costes reales (horas, compras, indirectos) sobre obras.",
    ubiquitousLanguage: [
      { term: "CostCenter", definition: "Centro de coste jerárquico." },
      { term: "AllocationRule", definition: "Regla driver + método que reparte un coste." },
    ],
    aggregates: ["CostCenter", "AllocationRule", "AllocationRun", "AllocationEntry"],
    emits: ["cost.allocated", "allocation.run"],
    consumes: ["time.logged", "goods.received", "resource.assigned"],
    roadmap: "2.5",
  },
  {
    key: "commissions",
    name: "Commissions",
    area: "finance",
    responsibility: "Planes de comisión por beneficiario con devengo por evento y liquidación.",
    ubiquitousLanguage: [
      { term: "CommissionPlan", definition: "Reglas de comisión (%, fijo o escalado)." },
      { term: "CommissionAccrual", definition: "Devengo de comisión, idempotente por evento." },
    ],
    aggregates: ["CommissionPlan", "Beneficiary", "CommissionAccrual", "Settlement"],
    emits: ["commission.accrued", "commission.settled"],
    consumes: ["commercial.opportunity.won", "finance.invoice.paid"],
    roadmap: "2.7",
  },
  {
    key: "organization",
    name: "Organization & Party",
    area: "platform",
    responsibility:
      "Frontera multi-tenant (empresas, usuarios, equipos) y el directorio fino de actores (Party) referenciable por id, con roles por contexto.",
    ubiquitousLanguage: [
      { term: "Company", definition: "Empresa-tenant; frontera de aislamiento de datos." },
      { term: "Party", definition: "Identidad legal mínima de un actor, con roles simultáneos." },
      {
        term: "PartyRole",
        definition: "Cliente / proveedor / subcontrata / empleado / autónomo sobre una Party.",
      },
    ],
    aggregates: ["Company", "User", "Team", "Party", "PartyRole"],
    emits: ["company.registered", "user.invited", "party.registered"],
    consumes: [],
    roadmap: "1.1",
  },
  {
    key: "portals",
    name: "External Portals",
    area: "platform",
    responsibility:
      "Superficies self-service por tipo de actor externo (cliente, proveedor, subcontrata, empleado); exponen vía RBAC + ABAC datos de otros contextos.",
    ubiquitousLanguage: [
      { term: "PortalAccess", definition: "Vínculo actor ↔ alcance de datos visible." },
      {
        term: "AccessScope",
        definition: "Qué puede ver/hacer el actor, decidido por policy ABAC.",
      },
    ],
    aggregates: ["PortalAccess", "PortalInvitation", "ScopedSession"],
    emits: ["portal-access.granted", "portal.action-submitted"],
    consumes: ["organization.user.invited"],
    roadmap: "2.6",
  },
  {
    key: "shared",
    name: "Platform services",
    area: "platform",
    responsibility:
      "Servicios transversales suscritos al bus de eventos: notificaciones, feature flags, automatización, reporting, auditoría y documentos.",
    ubiquitousLanguage: [
      { term: "FeatureFlag", definition: "Activa funciones por tenant/usuario sin desplegar." },
      {
        term: "AutomationRule",
        definition: "Trigger + condición + acciones sobre eventos del dominio.",
      },
      { term: "Notification", definition: "Aviso in-app / email / push según preferencias." },
    ],
    aggregates: ["Notification", "FeatureFlag", "AutomationRule", "AuditEntry", "Document"],
    emits: ["notification.created", "feature-flag.toggled", "automation.rule-fired"],
    consumes: ["* (cualquier evento del dominio)"],
    roadmap: "0.7 · 3.x · 4.x",
  },
];

export const integrationEvents: IntegrationEvent[] = [
  {
    name: "organization.company.registered",
    emitter: "Organization",
    payload: "companyId, name, locale",
    consumers: "todos (provisión tenant)",
  },
  {
    name: "party.registered",
    emitter: "Party (kernel)",
    payload: "partyId, legalName, taxId, kinds[]",
    consumers: "Commercial, Procurement, Workforce, Finance",
  },
  {
    name: "commercial.opportunity.won",
    emitter: "Commercial",
    payload: "opportunityId, partyId, amount",
    consumers: "CommercialProposal, Projects, Finance, Commissions",
  },
  {
    name: "tender.awarded",
    emitter: "TenderManagement",
    payload: "tenderId, partyId, studyId, proposalId",
    consumers: "Projects, Budgeting, Notifications",
  },
  {
    name: "proposal.accepted",
    emitter: "CommercialProposal",
    payload: "proposalId, budgetId, total",
    consumers: "Projects, Finance",
  },
  {
    name: "projects.project.created",
    emitter: "Projects",
    payload: "projectId, partyId, companyId",
    consumers: "Budgeting, Procurement, Workforce, Resources, SiteOps, DMS",
  },
  {
    name: "budgeting.budget.approved",
    emitter: "Budgeting",
    payload: "budgetId, projectId, total",
    consumers: "Procurement, Finance, CommercialProposal",
  },
  {
    name: "site-operations.certification.approved",
    emitter: "SiteOperations",
    payload: "certificationId, projectId, period, amount",
    consumers: "Finance, Reporting",
  },
  {
    name: "procurement.goods.received",
    emitter: "Procurement",
    payload: "poId, projectId, lines[]",
    consumers: "Finance, Reporting",
  },
  {
    name: "procurement.delivery.scheduled",
    emitter: "Procurement",
    payload: "poId, projectId, expectedDate",
    consumers: "Notifications, SiteOperations",
  },
  {
    name: "workforce.time.logged",
    emitter: "Workforce",
    payload: "timeEntryId, taskId, projectId, hours, cost",
    consumers: "Finance, Projects, Cost Allocation, Reporting",
  },
  {
    name: "resource.assigned",
    emitter: "Resources",
    payload: "assignmentId, resourceId, projectId, rate",
    consumers: "Finance, Cost Allocation, SiteOperations",
  },
  {
    name: "finance.invoice.paid",
    emitter: "Finance",
    payload: "invoiceId, partyId, amount",
    consumers: "Commissions, Reporting, Notifications",
  },
  {
    name: "finance.project.budget-exceeded",
    emitter: "Finance",
    payload: "projectId, budget, actual",
    consumers: "Notifications, Automation, Reporting",
  },
  {
    name: "* (cualquiera)",
    emitter: "todos",
    payload: "—",
    consumers: "Audit, Reporting, Automation, Notifications",
  },
];

/** Contexts in the given area, in declaration order. */
export function contextsByArea(area: ContextArea): DomainContext[] {
  return domainContexts.filter((context) => context.area === area);
}
