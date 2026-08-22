/**
 * Single source of truth for the "About ERPify" page ({@link AboutPage} at
 * `/backoffice/about`) — what ERPify is, who it serves, and the domain model
 * (bounded contexts, ubiquitous language and the integration events that wire
 * them). It mirrors `docs/bounded-contexts.md` and `docs/project-overview.md`
 * the same way `roadmap.ts` mirrors `docs/product-roadmap.md`: plain typed data,
 * no React or framework imports, so the page can never drift from the model and
 * a future export (API seed, slide deck) can consume the same shape.
 *
 * Narrative fields are English, matching the `lang` the root layout declares;
 * context/aggregate/event identifiers stay in the codebase's ubiquitous
 * language.
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
  tagline: "The ERP for the construction industry — from lead to certification, all event-driven.",
  whatItIs:
    "ERPify is a vertical ERP for construction companies (public and private works) covering the full life cycle of a site: commercial prospecting → cost study → execution in the field → invoicing → cost control → financial close.",
  whatFor:
    "It manages parties, projects, budgets, purchasing, stock, plant, treasury, certifications and cost allocation in a single system that speaks the language of the industry, not the generic jargon of a horizontal ERP.",
  strategicNorth:
    "ERPify does not compete as 'a set of modules' (CRM + invoicing + sites), nor as a verticalised Odoo, but as an execution engine for construction processes: automation built on events + rules + real site data. Everything emits events and everything is automatable; the site is the data source and the Automation Engine is the differentiator.",
  personas: [
    {
      name: "Civil engineer",
      focus:
        "Technical tools: pavement configurator, cost studies and site execution (measurements, certifications, quality, health & safety).",
    },
    {
      name: "Sales manager",
      focus: "Public tenders, client proposals, the opportunity funnel and marketing campaigns.",
    },
    {
      name: "Finance manager",
      focus: "Treasury, cashflow, direct and indirect cost allocation, and invoicing.",
    },
    {
      name: "Developer",
      focus:
        "Maintains and extends the system on DDD + hexagonal architecture, context by context.",
    },
  ],
};

export const AREA_LABEL: Record<ContextArea, string> = {
  commercial: "Commercial",
  site: "Site",
  operations: "Operations",
  finance: "Finance",
  platform: "Platform",
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
      "Prospecting, the private-sales funnel and the history of the client relationship. Replaces the generic 'CRM' with domain language.",
    ubiquitousLanguage: [
      { term: "Lead", definition: "A potential contact, not yet qualified." },
      { term: "Opportunity", definition: "A private-sales opportunity in the funnel (deal)." },
      { term: "Campaign", definition: "A marketing action leads are attributed to." },
      { term: "Account", definition: "The commercial role of a Party (client)." },
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
      "Tracking of public tenders through their own cycle: call → study → proposal → award, with regulated deadlines and documentation.",
    ubiquitousLanguage: [
      {
        term: "PublicTender",
        definition: "A public tender the company may bid for.",
      },
      { term: "BidDecision", definition: "The decision to bid or not (bid / no-bid)." },
      { term: "AwardStatus", definition: "Terminal outcome: won, lost or unawarded." },
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
      "Generation of the client-facing technical and commercial offer, with a validity period, distinct from the internal budget.",
    ubiquitousLanguage: [
      { term: "Proposal", definition: "The offer sent to the client (technical + commercial)." },
      { term: "ValidityPeriod", definition: "The period during which the offer stands." },
      {
        term: "CostEstimate",
        definition: "Client-facing cost summary, derived from a Budget.",
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
      "Managing the site end to end: structure (phases, milestones, tasks) and progress. Physical execution lives in SiteOperations.",
    ubiquitousLanguage: [
      { term: "Project", definition: "The site — the root of the construction work." },
      { term: "Phase", definition: "A stage of the site grouping tasks." },
      { term: "ProgressEntry", definition: "A progress record the site's % is derived from." },
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
      "The day to day of the site in the field: diary, measurements, certifications (to date), incidents, quality and safety. The core of the business.",
    ubiquitousLanguage: [
      {
        term: "Certification",
        definition: "A progress certification that enables the to-date invoice.",
      },
      { term: "Measurement", definition: "A site measurement; once approved it is not edited." },
      { term: "SiteDiary", definition: "The daily report of what was executed on site." },
      { term: "SafetyRecord", definition: "A health and safety record." },
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
      "Per-site budgets with versioning and margins, including the layer-based configurator (pavements) that prices and generates line items.",
    ubiquitousLanguage: [
      { term: "Budget", definition: "A site budget; approving it freezes a version." },
      {
        term: "Configuration",
        definition: "A layered structure that derives its cost (configurator).",
      },
      { term: "PreBidStudy", definition: "The cost study made before bidding for a tender." },
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
    responsibility: "Suppliers, purchasing and per-site stock control.",
    ubiquitousLanguage: [
      { term: "PurchaseOrder", definition: "An order placed with a supplier." },
      { term: "StockMovement", definition: "A material receipt / issue / transfer." },
      { term: "Reservation", definition: "Material reserved for one specific site." },
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
      "Plant and vehicles as assets: assignment to a site, internal cost and maintenance. Neither people (Workforce) nor consumable material (Procurement).",
    ubiquitousLanguage: [
      { term: "Machine", definition: "A plant asset owned by the company." },
      { term: "Assignment", definition: "An asset assigned to a site/task for a period." },
      { term: "InternalRate", definition: "The asset's internal rate (cost per hour or day)." },
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
    responsibility: "People, subcontractors and work logs with an hourly cost.",
    ubiquitousLanguage: [
      { term: "WorkLog", definition: "A work log for an employee or subcontractor." },
      { term: "LaborRate", definition: "The hourly cost of labour." },
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
      "The financial core: invoicing, receipts/payments, treasury and per-project cost. Banks/Treasury is the reference vertical already delivered.",
    ubiquitousLanguage: [
      {
        term: "Invoice",
        definition: "A client or supplier invoice; once issued it is not edited but credited.",
      },
      {
        term: "Payment",
        definition: "A receipt or payment; never exceeds the outstanding amount.",
      },
      { term: "CostEntry", definition: "A cost charged to a site." },
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
      "Cost centres and auditable allocation rules that spread real costs (hours, purchases, overheads) across sites.",
    ubiquitousLanguage: [
      { term: "CostCenter", definition: "A hierarchical cost centre." },
      { term: "AllocationRule", definition: "A driver + method rule that spreads a cost." },
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
    responsibility: "Per-beneficiary commission plans with event-driven accrual and settlement.",
    ubiquitousLanguage: [
      { term: "CommissionPlan", definition: "Commission rules (%, fixed or tiered)." },
      { term: "CommissionAccrual", definition: "A commission accrual, idempotent per event." },
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
      "The multi-tenant boundary (companies, users, teams) and the fine-grained directory of parties, referenceable by id, with per-context roles.",
    ubiquitousLanguage: [
      { term: "Company", definition: "A tenant company; the data-isolation boundary." },
      {
        term: "Party",
        definition: "The minimal legal identity of an actor, holding simultaneous roles.",
      },
      {
        term: "PartyRole",
        definition: "Client / supplier / subcontractor / employee / freelancer over a Party.",
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
      "Self-service surfaces per type of external actor (client, supplier, subcontractor, employee); they expose other contexts' data through RBAC + ABAC.",
    ubiquitousLanguage: [
      {
        term: "PortalAccess",
        definition: "The link between an actor and the data scope they see.",
      },
      {
        term: "AccessScope",
        definition: "What the actor may see/do, decided by an ABAC policy.",
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
      "Cross-cutting services subscribed to the event bus: notifications, feature flags, automation, reporting, audit and documents.",
    ubiquitousLanguage: [
      { term: "FeatureFlag", definition: "Turns features on per tenant/user without a deploy." },
      {
        term: "AutomationRule",
        definition: "Trigger + condition + actions over domain events.",
      },
      { term: "Notification", definition: "An in-app / email / push alert, per preferences." },
    ],
    aggregates: ["Notification", "FeatureFlag", "AutomationRule", "AuditEntry", "Document"],
    emits: ["notification.created", "feature-flag.toggled", "automation.rule-fired"],
    consumes: ["* (any domain event)"],
    roadmap: "0.7 · 3.x · 4.x",
  },
];

export const integrationEvents: IntegrationEvent[] = [
  {
    name: "organization.company.registered",
    emitter: "Organization",
    payload: "companyId, name, locale",
    consumers: "all (tenant provisioning)",
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
    name: "* (any)",
    emitter: "all",
    payload: "—",
    consumers: "Audit, Reporting, Automation, Notifications",
  },
];

/** Contexts in the given area, in declaration order. */
export function contextsByArea(area: ContextArea): DomainContext[] {
  return domainContexts.filter((context) => context.area === area);
}
