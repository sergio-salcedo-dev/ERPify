import {
  LucideIcon,
  Workflow,
  Inbox,
  Database,
  ShieldCheck,
  Activity,
  UsersRound,
  HeartHandshake,
  HardHat,
  Calculator,
  PackageSearch,
  Clock,
  FileStack,
  Banknote,
  Cog,
  Bell,
  Flag,
  BarChart3,
  TrendingUp,
  Webhook,
  Puzzle,
  Rocket,
  FlaskConical,
  Container,
  Languages,
  ScrollText,
  FolderArchive,
  LayoutTemplate,
  Smartphone,
  Layers,
  Scale,
  DoorOpen,
  Percent,
  Gavel,
  FileSignature,
  Construction,
  Truck,
} from "lucide-react";

/**
 * Single source of truth for the product + engineering roadmap. The in-app
 * "backlog vivo" at `/backoffice/roadmap` ({@link RoadmapPage}) renders straight
 * from this data, mirroring how the sidebar is built from `backofficeMenu.ts`,
 * so the UI and the plan can never drift. Kept as plain typed data — no React,
 * no framework imports — so it stays portable (a future API/DB seed or a
 * GitHub-Projects export can consume the same shape).
 *
 * `status` is flipped EXPLICITLY, task by task, as each piece is closed and
 * verified — the backlog ships fully unmarked and is never pre-marked from
 * assumptions about the codebase:
 * - `done`        — delivered and verified by a closed task.
 * - `in-progress` — actively being worked on.
 * - `planned`     — not started (the default).
 *
 * Status is declared ONLY at the submodule level; an omitted submodule status
 * means `planned` — never an inherited optimism. A module's status is DERIVED
 * from its submodules ({@link moduleStatus}), so a module can never look more
 * advanced than the work inside it.
 *
 * Language: the whole surface is English, matching the `lang` the root layout
 * declares. Module/submodule names are technical identifiers that also match
 * how the codebase and docs refer to them; narrative fields (`summary`,
 * `objective`, `userNeeds`, notes) are prose. Module 0.6 is what makes the
 * surface translatable — until it lands, English is the only locale, so a
 * Spanish string here is a defect rather than a placeholder.
 *
 * `objective` is the engineering "definition of done"; `userNeeds` is the
 * complementary user-facing view — the jobs a user expects ERPify to solve for
 * them. Foundation/ops modules frame the need from the operator/admin's seat.
 */
export type RoadmapStatus = "done" | "in-progress" | "planned";

export type RoadmapPriority = "critical" | "high" | "medium" | "low";

/**
 * Implementation-ease bucket, orthogonal to priority. The scale is about how
 * hard the module is to BUILD, mirroring the estimation buckets in
 * `docs/product-roadmap.md`:
 * - `low`    — CRUD without DB relations; clone the Banks template.
 * - `medium` — DB relations + business rules.
 * - `high`   — engines, integrations or interactive UI.
 * Pick the next task by priority AND ease: high priority + low complexity
 * first; a high-complexity module deserves slicing before starting.
 */
export type RoadmapComplexity = "low" | "medium" | "high";

export interface RoadmapSubmodule {
  name: string;
  /** Defaults to `planned` when omitted — progress is always opt-in. */
  status?: RoadmapStatus;
  /** Short clarifier shown as a muted note. */
  note?: string;
}

export interface RoadmapModule {
  /** Stable dotted code used for cross-references and test ids, e.g. "0.1". */
  code: string;
  name: string;
  icon: LucideIcon;
  priority: RoadmapPriority;
  /** How hard it is to build — see {@link RoadmapComplexity}. */
  complexity: RoadmapComplexity;
  /** What "done" means for this module, one line (engineering view). */
  objective: string;
  /** What a user expects ERPify to solve here — the needs this module covers. */
  userNeeds: string[];
  /** Bounded context this module lives in, when decided. */
  boundedContext?: string;
  /** Module codes this one depends on. */
  dependsOn?: string[];
  submodules: RoadmapSubmodule[];
}

export interface RoadmapPhase {
  /** Single-digit phase code, e.g. "0". */
  code: string;
  label: string;
  summary: string;
  modules: RoadmapModule[];
}

export const roadmapPhases: RoadmapPhase[] = [
  {
    code: "0",
    label: "Platform foundation",
    summary:
      "The enterprise-grade base everything else rests on. Proven by the two verticals already delivered (Banks, System Health); what is missing is generalising it into reusable capabilities.",
    modules: [
      {
        code: "0.1",
        name: "Event-Driven Backbone",
        icon: Workflow,
        priority: "critical",
        complexity: "high",
        objective: "The whole system reacts to internal events without coupling.",
        userNeeds: [
          "That my actions trigger automatic effects (alerts, tasks) with no waiting.",
          "That nothing is lost even if a process fails along the way.",
          "That the app stays responsive even as the volume of work grows.",
        ],
        boundedContext: "shared",
        submodules: [
          { name: "Command Bus / Query Bus (CQRS light)" },
          { name: "Domain Event Dispatcher" },
          { name: "Async Job Pipeline (Messenger transports)" },
          { name: "Retry / failure strategy (DLQ)" },
          { name: "Idempotency layer", note: "critical for an ERP" },
          { name: "Event versioning strategy" },
          { name: "Integration Events vs Domain Events" },
        ],
      },
      {
        code: "0.2",
        name: "Transactional Outbox System",
        icon: Inbox,
        priority: "critical",
        complexity: "high",
        objective: "Strong consistency between the database and the events published.",
        userNeeds: [
          "That a saved change is never left half-done with respect to its alerts or integrations.",
          "That I do not get duplicate notifications for one and the same fact.",
        ],
        boundedContext: "shared",
        dependsOn: ["0.1"],
        submodules: [
          { name: "Outbox table schema" },
          { name: "Outbox writer (persistence decorator)" },
          { name: "Outbox publisher worker (Messenger consumer)" },
          { name: "Retry + backoff strategy" },
          { name: "Deduplication / idempotency keys" },
          { name: "Event serialization registry" },
        ],
      },
      {
        code: "0.3",
        name: "API & Query Infrastructure",
        icon: Database,
        priority: "critical",
        complexity: "medium",
        objective: "Standardise data access in a composable way.",
        userNeeds: [
          "Search, filter and sort any list instantly.",
          "Work with large lists without the screen freezing.",
          "Get clear, understandable errors rather than technical dumps.",
        ],
        boundedContext: "shared",
        submodules: [
          { name: "Criteria Query System (composable filters)" },
          { name: "Pagination (cursor-based + offset fallback)" },
          { name: "Sorting DSL" },
          { name: "API Response Standard (RFC 9457)" },
          { name: "DTO mapping layer (never expose entities)" },
          { name: "Read model separation (future CQRS)" },
        ],
      },
      {
        code: "0.4",
        name: "Security & Multi-Tenant Core",
        icon: ShieldCheck,
        priority: "critical",
        complexity: "high",
        objective: "Enterprise SaaS base: per-tenant isolation and authorization.",
        userNeeds: [
          "Sign in securely with my account.",
          "That each company can only see and touch its own data.",
          "Permissions by role: each person sees and does exactly what they should.",
        ],
        boundedContext: "shared",
        submodules: [
          { name: "Tenant Context Resolver" },
          { name: "RBAC engine (roles + permissions)" },
          { name: "Future ABAC hooks (policies)" },
          { name: "JWT auth + refresh strategy" },
          { name: "Session / device tracking" },
          { name: "Audit log base system", note: "tabla domain_event" },
        ],
      },
      {
        code: "0.5",
        name: "Observability Foundation",
        icon: Activity,
        priority: "high",
        complexity: "medium",
        objective: "A system observable from day one.",
        userNeeds: [
          "That if something breaks it is detected before it affects me.",
          "That support can trace my specific incident by an identifier.",
        ],
        boundedContext: "shared",
        submodules: [
          { name: "Structured logging standard" },
          { name: "Correlation ID propagation", note: "critical with Messenger" },
          { name: "Sentry integration (backend + frontend)" },
          { name: "Metrics hooks (future Prometheus)" },
          { name: "Event tracing hooks (domain → async)" },
        ],
      },
      {
        code: "0.6",
        name: "Internationalization (i18n)",
        icon: Languages,
        priority: "high",
        complexity: "medium",
        objective:
          "The whole app available in English and Spanish, with language as a user preference.",
        userNeeds: [
          "Use the whole application in my language (Spanish or English).",
          "That dates, numbers and currency are shown in my local format.",
          "Receive emails and error messages in my language too.",
        ],
        boundedContext: "shared",
        submodules: [
          { name: "PWA translation catalogues (next-intl)" },
          { name: "Locale detection + switching (EN/ES)" },
          { name: "Language selector + persistence (user preference)" },
          { name: "Backend translations (Symfony Translation: validation, emails)" },
          { name: "Localised RFC 9457 error messages" },
          {
            name: "Date/number/currency formatting per locale",
            note: "dateTimeProvider already formats per locale",
          },
          { name: "Translation coverage in CI (missing keys fail)" },
        ],
      },
      {
        code: "0.7",
        name: "Audit & Activity Trail",
        icon: ScrollText,
        priority: "high",
        complexity: "medium",
        objective: "Full traceability: who changed what and when, queryable and exportable.",
        userNeeds: [
          "Know who changed what and when on any record.",
          "Recover the full change history of a piece of data.",
          "Export the activity trail for an audit or a client.",
        ],
        boundedContext: "shared",
        dependsOn: ["0.4"],
        submodules: [
          { name: "Audit table schema (domain_event)" },
          { name: "Audit log viewer (UI /backoffice/audit)" },
          { name: "Filter + search by entity / user / action" },
          { name: "Per-entity change history (timeline)" },
          { name: "Tenant-scoped audit queries" },
          { name: "Retention + archiving policy" },
          { name: "Export (CSV / PDF)" },
          { name: "Tamper-evidence / log integrity" },
        ],
      },
      {
        code: "0.8",
        name: "Media & Document System",
        icon: FolderArchive,
        priority: "high",
        complexity: "high",
        objective:
          "Centralise file handling (images, PDFs, spreadsheets…) decoupled from the business domain. A cross-cutting core, not just another module.",
        userNeeds: [
          "Attach and retrieve files (logos, site photos, PDFs, invoices) from any module.",
          "Reuse one and the same file across several entities without duplicating it.",
          "Have my site documents versioned, with states (draft/approved/archived) and history.",
          "Fast, secure downloads (temporary links) without depending on whoever uploaded them.",
        ],
        boundedContext: "shared",
        submodules: [
          {
            name: "F0 · Model design (MediaFile, StorageProviderInterface, MIME)",
          },
          { name: "F1 · Local storage + per-tenant upload/download" },
          { name: "F2 · Generic Media Library (reuse, no duplication, tags)" },
          { name: "F3 · Document System (agregado Document, versionado, tipos, estados)" },
          { name: "F4 · Storage abstraction (S3/MinIO, swappable by config)" },
          { name: "F5 · SaaS optimisation (signed URLs, CDN, thumbnails, OCR)" },
        ],
      },
      {
        code: "0.9",
        name: "Frontend Platform (PWA)",
        icon: LayoutTemplate,
        priority: "high",
        complexity: "medium",
        objective:
          "A reusable frontend platform: design system, API client layer, state and per-module loading, with offline-first foundations.",
        userNeeds: [
          "That the whole app looks and behaves the same, screen after screen.",
          "That the app loads fast even as the number of modules grows.",
          "Be able to keep working with intermittent connectivity (the basis for site work).",
        ],
        boundedContext: "shared",
        submodules: [
          { name: "Design system (tokens, composites, patterns)" },
          { name: "API client layer (HTTP port + adapters)" },
          { name: "State management (preferences, list caching)" },
          { name: "Offline-first foundations (service worker, sync queue)" },
          { name: "Lazy loading / code splitting per module" },
        ],
      },
    ],
  },
  {
    code: "1",
    label: "Core ERP operativo",
    summary:
      "Where the real business value starts. Banks is the reference vertical already delivered.",
    modules: [
      {
        code: "1.1",
        name: "Identity & Organization",
        icon: UsersRound,
        priority: "high",
        complexity: "medium",
        objective: "Users, companies (the multi-tenant boundary) and teams.",
        userNeeds: [
          "Manage my company's users and teams.",
          "Invite people and give them the right role and permissions.",
          "Edit my profile, my password and my preferences.",
        ],
        boundedContext: "organization",
        dependsOn: ["0.4"],
        submodules: [
          { name: "Users" },
          { name: "Companies (multi-tenant boundary)" },
          { name: "Teams" },
          { name: "Roles & Permissions (UI layer)" },
          { name: "User profile & preferences" },
        ],
      },
      {
        code: "1.2",
        name: "Commercial (Sales & Prospecting)",
        icon: HeartHandshake,
        priority: "high",
        complexity: "medium",
        objective:
          "Prospecting, the private-sales funnel and the history of the client relationship (domain language, not a generic 'CRM').",
        userNeeds: [
          "Have all my clients, contacts and opportunities in a single place.",
          "Follow each opportunity through its stages until it closes.",
          "Log calls, tasks, emails and notes without losing the thread.",
          "Never forget a commercial follow-up.",
          "Launch campaigns and attribute the leads they generate.",
        ],
        boundedContext: "commercial",
        dependsOn: ["1.1"],
        submodules: [
          { name: "Leads ingestion (LeadSource)" },
          { name: "Contacts & Accounts (a Party's commercial role)" },
          { name: "Pipeline (configurable stages)" },
          { name: "Opportunities (deals)" },
          { name: "Campaigns (marketing) + lead attribution" },
          { name: "Activities / interactions (calls, meetings, emails)" },
          { name: "Notes & attachments" },
          { name: "Commercial documents", note: "on top of core 0.8" },
          { name: "Lead scoring" },
          { name: "Basic automation triggers (event-driven)" },
        ],
      },
      {
        code: "1.2b",
        name: "TenderManagement (public tenders)",
        icon: Gavel,
        priority: "high",
        complexity: "medium",
        objective:
          "Tracking of public tenders through their own cycle: call → study → proposal → award.",
        userNeeds: [
          "Never miss a submission deadline.",
          "Manage the specifications and documentation of each tender.",
          "Decide bid / no-bid and follow the award.",
        ],
        boundedContext: "tender",
        dependsOn: ["1.2"],
        submodules: [
          { name: "Public tenders registry" },
          { name: "Submission deadlines + alerts (72h)" },
          { name: "Tender documents (specifications)" },
          { name: "Bid / no-bid decision" },
          { name: "Award tracking (won/lost/unawarded)" },
          { name: "Kanban board (call → award)" },
        ],
      },
      {
        code: "1.2c",
        name: "CommercialProposal (offers)",
        icon: FileSignature,
        priority: "medium",
        complexity: "medium",
        objective:
          "The client-facing technical and commercial offer, with a validity period, derived from the internal budget (Budgeting).",
        userNeeds: [
          "Send a professional offer tied to the cost study.",
          "Control the validity and status of every proposal.",
          "That accepting a proposal creates the site automatically.",
        ],
        boundedContext: "proposal",
        dependsOn: ["1.2", "1.4"],
        submodules: [
          { name: "Proposal documents (technical + commercial)" },
          { name: "Cost estimate (client-facing, from a Budget)" },
          { name: "Validity period + lifecycle (issued/accepted/rejected)" },
          { name: "Accept → project creation trigger", note: "evento proposal.accepted" },
        ],
      },
      {
        code: "1.3",
        name: "Projects / Construction Management",
        icon: HardHat,
        priority: "high",
        complexity: "high",
        objective: "CORE vertical: managing a site from beginning to end.",
        userNeeds: [
          "Control every site from beginning to end in one place.",
          "See real progress by phase and milestone in real time.",
          "Know who is doing which task and when.",
        ],
        boundedContext: "operations",
        dependsOn: ["1.1"],
        submodules: [
          { name: "Projects (the site)" },
          { name: "Phases / milestones" },
          { name: "Chapter structure (WBS)" },
          { name: "Tasks & assignments" },
          { name: "Planning (tasks, Gantt)" },
          { name: "Progress tracking" },
          { name: "Project status lifecycle" },
          { name: "Resource allocation hooks" },
        ],
      },
      {
        code: "1.3b",
        name: "SiteOperations (site execution)",
        icon: Construction,
        priority: "high",
        complexity: "high",
        objective:
          "The day to day of the site in the field: diary, measurements, certifications (to date), incidents, quality and safety.",
        userNeeds: [
          "Keep the site diary day by day.",
          "Certify progress so it can be invoiced to date.",
          "Record measurements, incidents, quality checks and safety.",
        ],
        boundedContext: "site-operations",
        dependsOn: ["1.3"],
        submodules: [
          { name: "Site diary (daily report)" },
          { name: "Measurements" },
          {
            name: "Certifications (to date)",
            note: "measurement → certification → invoice (2.3)",
          },
          { name: "Incidents (incidencias)" },
          { name: "Quality checks" },
          { name: "Safety / PRL records" },
          { name: "Site planning" },
        ],
      },
      {
        code: "1.4",
        name: "Budgeting & Estimation Engine",
        icon: Calculator,
        priority: "high",
        complexity: "medium",
        objective: "Per-project budgets with a cost structure and versioning.",
        userNeeds: [
          "Budget each site with its own cost line items.",
          "Reuse templates instead of starting from scratch every time.",
          "Compare planned against actual and see the margin.",
          "Study the financial viability of a tender before bidding for it.",
        ],
        boundedContext: "operations",
        dependsOn: ["1.3"],
        submodules: [
          { name: "Budget creation per project" },
          { name: "Line-item cost structure" },
          { name: "Estimation templates" },
          { name: "Versioning of budgets" },
          { name: "Margin calculation" },
          { name: "Forecast vs actual structure" },
          {
            name: "Pre-bid cost study",
            note: "draft budget for a tender, before any award",
          },
        ],
      },
      {
        code: "1.5",
        name: "Procurement & Inventory (warehouse)",
        icon: PackageSearch,
        priority: "high",
        complexity: "medium",
        objective: "Suppliers, purchasing and per-project stock control.",
        userNeeds: [
          "Know what material I have and which warehouse it is in.",
          "Raise and track purchase orders with suppliers.",
          "Reserve material for one specific site.",
          "Be warned before I run out of stock.",
        ],
        boundedContext: "operations",
        dependsOn: ["1.3"],
        submodules: [
          { name: "Suppliers" },
          { name: "Purchase orders" },
          { name: "Material catalog" },
          { name: "Stock locations" },
          { name: "Stock movements (in/out/transfer)" },
          { name: "Reservation system per project" },
          { name: "Reorder logic (future automation)" },
        ],
      },
      {
        code: "1.6",
        name: "Configurator & Dynamic Pricing",
        icon: Layers,
        priority: "medium",
        complexity: "high",
        objective:
          "A visual layer-based configurator (pavements as the first vertical) that computes cost and price and generates budget line items.",
        userNeeds: [
          "Configure a layered structure and see its material, labour and transport cost instantly.",
          "Reuse standard configurations and adjust prices by rules rather than by hand.",
          "Push the configuration straight into a budget without recalculating anything.",
        ],
        boundedContext: "operations",
        dependsOn: ["1.4"],
        submodules: [
          { name: "Visual layer/structure configurator (pavements)" },
          { name: "Component & layer catalog (materials per layer)" },
          { name: "Cost computation (material + labour + transport)" },
          { name: "Productivity ratios" },
          { name: "Dynamic pricing rules engine" },
          { name: "Output → budget line generation", note: "alimenta 1.4" },
        ],
      },
    ],
  },
  {
    code: "2",
    label: "Operaciones avanzadas",
    summary:
      "Operational capabilities that leverage the core: people, documents, the financial core, cost allocation, external-party portals and commissions.",
    modules: [
      {
        code: "2.1",
        name: "Workforce & Time Tracking",
        icon: Clock,
        priority: "medium",
        complexity: "medium",
        objective: "Staff, subcontractors and work logs with an hourly cost.",
        userNeeds: [
          "Record the hours worked per task and per site.",
          "Keep the work logs of employees and subcontractors.",
          "Know the real labour cost of every project.",
          "Plan the team's workload.",
        ],
        boundedContext: "operations",
        dependsOn: ["1.3"],
        submodules: [
          { name: "Employees" },
          { name: "Subcontractors" },
          { name: "Work logs" },
          { name: "Time tracking (hours per task/project)" },
          { name: "Scheduling / workload planning" },
          { name: "Cost per labor unit" },
        ],
      },
      {
        code: "2.2",
        name: "Document Management System (DMS)",
        icon: FileStack,
        priority: "medium",
        complexity: "medium",
        objective:
          "The document business layer on top of the Media core (0.8): per-project documents, templates and permissions. Storage and versioning come from 0.8 — they are not duplicated here.",
        userNeeds: [
          "Keep all the documents of a site together and find them fast.",
          "Always reach the latest version of each document.",
          "Control who may view or edit each document.",
        ],
        boundedContext: "shared",
        dependsOn: ["0.4", "0.8"],
        submodules: [
          { name: "Project-linked documents" },
          { name: "Templates (contracts, reports)" },
          { name: "Permissions per document" },
          { name: "Electronic signatures" },
          { name: "Site licences and permits (compliance)" },
          { name: "Document audit trail", note: "on top of 0.7" },
        ],
      },
      {
        code: "2.3",
        name: "Finance Layer (ERP Financial Core)",
        icon: Banknote,
        priority: "high",
        complexity: "high",
        objective: "Invoicing, payments and per-project cost aggregation.",
        userNeeds: [
          "Issue and control client and supplier invoices.",
          "Track outstanding receipts and payments.",
          "See cashflow and treasury at a glance.",
          "Know the real cost and margin of every project.",
        ],
        boundedContext: "backoffice",
        dependsOn: ["1.4"],
        submodules: [
          { name: "Banks / Treasury (the reference vertical)" },
          { name: "Invoices (client/supplier)" },
          { name: "Payments tracking" },
          { name: "Certification-based invoicing (progress billing)", note: "from 1.3" },
          { name: "Bank reconciliation (automatic matching)" },
          { name: "Automatic journal entries (operation → accounting)" },
          { name: "Cashflow view" },
          { name: "Cost aggregation per project" },
          { name: "Tax abstraction layer (future-proof)" },
          { name: "Financial reporting engine" },
        ],
      },
      {
        code: "2.4",
        name: "Mobile Field Operations",
        icon: Smartphone,
        priority: "high",
        complexity: "high",
        objective:
          "The site as a real-time data source: capture work logs, photos and incidents from a phone, with or without connectivity.",
        userNeeds: [
          "Record work logs from the site, without going back to the office.",
          "Attach geolocated photos of progress and incidents to the project.",
          "That whatever is captured offline syncs by itself once connectivity returns.",
        ],
        boundedContext: "operations",
        dependsOn: ["0.9", "1.3"],
        submodules: [
          { name: "Work-log capture on site" },
          { name: "Fotos geolocalizadas e incidencias" },
          { name: "Offline mode + synchronisation" },
          { name: "Approvals from a phone" },
          { name: "Field measurements → certification", note: "alimenta 1.3 y 2.3" },
        ],
      },
      {
        code: "2.5",
        name: "Cost Allocation Engine",
        icon: Scale,
        priority: "high",
        complexity: "high",
        objective:
          "Cost centres and auditable allocation rules that spread real costs (hours, purchases, overheads) across sites and cost objects.",
        userNeeds: [
          "Define cost centres and spread overheads by rules rather than by eye.",
          "Charge every hour, purchase and expense to the right site automatically.",
          "Audit how each allocation was computed, step by step.",
        ],
        boundedContext: "cost-allocation",
        dependsOn: ["1.3", "2.3"],
        submodules: [
          { name: "Cost centers (hierarchical)" },
          {
            name: "Cost drivers registry (event-driven)",
            note: "time.logged, goods.received, cost-accrued",
          },
          {
            name: "Allocation rules engine",
            note: "direct / indirect / % / volume / hours / consumption / formula",
          },
          { name: "Allocation runs (on-event + batch)" },
          { name: "Overhead / indirect cost distribution" },
          { name: "Auditable allocation trail" },
        ],
      },
      {
        code: "2.6",
        name: "External Portals",
        icon: DoorOpen,
        priority: "high",
        complexity: "high",
        objective:
          "Self-service surfaces per type of party (client, supplier, subcontractor, employee); each exposes only its own data and flows through RBAC + ABAC.",
        userNeeds: [
          "As a client, see my projects, certifications and invoices and approve what is mine to approve.",
          "As a supplier or subcontractor, check orders and upload work logs or invoices without phoning the office.",
          "As an employee, record my hours and reach my documents through my own access.",
        ],
        boundedContext: "portals",
        dependsOn: ["0.4", "1.2", "2.1", "2.3"],
        submodules: [
          {
            name: "Portal shell + ABAC gating",
            note: "BusinessContext: employeeStatus / customerStatus",
          },
          { name: "Client Portal (projects, certifications, invoices, approvals)" },
          { name: "Provider Portal (orders, delivery notes, invoices)" },
          { name: "Subcontractor Portal (work logs, certifications)" },
          { name: "Employee Portal (work logs, hours, documents)" },
          { name: "Portal invitations & scoped sessions" },
        ],
      },
      {
        code: "2.7",
        name: "Commission Engine",
        icon: Percent,
        priority: "medium",
        complexity: "high",
        objective:
          "Per-beneficiary commission plans (client, supplier, employee, company) with event-driven accrual and settlement.",
        userNeeds: [
          "Define commissions by percentage, fixed amount or volume tiers.",
          "That the commission is computed by itself when a sale is won or an invoice is paid.",
          "Settle commissions with a clear breakdown of how they were computed.",
        ],
        boundedContext: "commissions",
        dependsOn: ["1.2", "2.3"],
        submodules: [
          { name: "Commission plans", note: "% / fixed / volume-tiered" },
          { name: "Beneficiaries (clients / suppliers / employees / companies)" },
          { name: "Trigger events", note: "opportunity.won, invoice.paid" },
          { name: "Accrual & settlement" },
          { name: "Commission statements (settlements)" },
        ],
      },
      {
        code: "2.8",
        name: "Resources (Plant & Equipment)",
        icon: Truck,
        priority: "medium",
        complexity: "medium",
        objective:
          "Plant and vehicles as assets: assignment to a site, internal cost and maintenance.",
        userNeeds: [
          "Know what plant and vehicles I have and where they are.",
          "Assign assets to a site and know their real cost.",
          "Control maintenance and availability.",
        ],
        boundedContext: "resources",
        dependsOn: ["1.3"],
        submodules: [
          { name: "Machinery registry" },
          { name: "Vehicles registry" },
          { name: "Assignments to project/task" },
          { name: "Internal cost rate (hour/day)" },
          { name: "Maintenance log + alerts" },
        ],
      },
    ],
  },
  {
    code: "3",
    label: "Automation & Intelligence",
    summary: "The product differentiator: automation engine, notifications and feature flags.",
    modules: [
      {
        code: "3.1",
        name: "Automation Engine",
        icon: Cog,
        priority: "high",
        complexity: "high",
        objective: "A rules and workflow engine reacting to domain events.",
        userNeeds: [
          "Automate repetitive tasks with 'if X happens, do Y' rules.",
          "Design my own workflows without programming.",
          "That the system creates tasks and sends alerts for me, on its own.",
        ],
        boundedContext: "shared",
        dependsOn: ["0.1"],
        submodules: [
          { name: "Event trigger registry" },
          { name: "Rules engine (IF/THEN)" },
          { name: "Workflow engine (state machine + DAG)" },
          { name: "Action system (email, update entity, create task)" },
          { name: "Retry + compensation (sagas light)" },
          { name: "Visual workflow builder (key UI)" },
          {
            name: "Automatic document generation",
            note: "budgets, certifications, invoices, purchase orders from templates + rules",
          },
          {
            name: "State synchronisation between modules",
            note: "sale won → project + budget + planning, with no manual handovers",
          },
          { name: "Automatic data capture (OCR / email ingestion)" },
          { name: "Threshold-based purchasing", note: "with optional approval" },
          {
            name: "Per-project autopilot",
            note: "automatic within limits; alerts only on exceptions",
          },
        ],
      },
      {
        code: "3.2",
        name: "Notification System",
        icon: Bell,
        priority: "medium",
        complexity: "medium",
        objective: "An in-app, email and push notification centre with per-user preferences.",
        userNeeds: [
          "Find out immediately about whatever affects me.",
          "Choose which alerts I want and through which channel (app, email or push).",
          "See my notifications in a single centre.",
        ],
        boundedContext: "shared",
        dependsOn: ["0.1"],
        submodules: [
          {
            name: "Notification center (in-app)",
            note: "toasts + Mercure realtime",
          },
          {
            name: "Email notifications",
            note: "BankChanged notify handler",
          },
          { name: "Event subscriptions per user/role" },
          { name: "Notification preferences engine" },
          { name: "Push notifications (PWA)" },
        ],
      },
      {
        code: "3.3",
        name: "Feature Flags System",
        icon: Flag,
        priority: "low",
        complexity: "medium",
        objective: "Per-tenant/user activation and gradual rollout.",
        userNeeds: [
          "Turn features on or off for my company without waiting for a deploy.",
          "Try new things with a small group before opening them to everyone.",
        ],
        boundedContext: "shared",
        dependsOn: ["0.4"],
        submodules: [
          { name: "Feature flag registry" },
          { name: "Tenant-based enablement" },
          { name: "User-based targeting" },
          { name: "Gradual rollout system" },
          { name: "A/B testing foundation" },
        ],
      },
    ],
  },
  {
    code: "4",
    label: "Analytics & Decision Layer",
    summary:
      "Turns operational data into decisions: configurable reporting and advanced analytics.",
    modules: [
      {
        code: "4.1",
        name: "Reporting Engine",
        icon: BarChart3,
        priority: "medium",
        complexity: "high",
        objective: "Configurable KPIs and project/financial dashboards with export.",
        userNeeds: [
          "See site and financial dashboards at a glance.",
          "Build my own KPIs without depending on anyone.",
          "Export reports to PDF or Excel to share them.",
        ],
        boundedContext: "backoffice",
        dependsOn: ["2.3"],
        submodules: [
          { name: "KPI builder (configurable)" },
          { name: "Project dashboards" },
          { name: "Financial dashboards" },
          { name: "Export engine (PDF/Excel)" },
          { name: "Query aggregation layer (read models)" },
        ],
      },
      {
        code: "4.2",
        name: "Advanced Analytics",
        icon: TrendingUp,
        priority: "low",
        complexity: "high",
        objective: "Cost variance, profitability and bottleneck detection.",
        userNeeds: [
          "Spot cost variances in time, not once it is too late.",
          "Know which projects are genuinely profitable.",
          "Anticipate bottlenecks before they slow the site down.",
        ],
        boundedContext: "backoffice",
        dependsOn: ["4.1"],
        submodules: [
          { name: "Cost deviation analysis" },
          { name: "Project profitability tracking" },
          { name: "Forecasting engine (rule-based inicialmente)" },
          { name: "Cost-overrun and delay prediction (site history)" },
          { name: "Bottleneck detection (workflow + time analysis)" },
        ],
      },
    ],
  },
  {
    code: "5",
    label: "Integration & Platform Extension",
    summary: "Opens the platform outwards: webhooks, connectors and an extension system.",
    modules: [
      {
        code: "5.1",
        name: "Integration Layer",
        icon: Webhook,
        priority: "low",
        complexity: "high",
        objective: "Webhooks, an external API gateway and import/export pipelines.",
        userNeeds: [
          "Connect ERPify to my accounting, my bank or other tools.",
          "Import and export data without typing it by hand.",
          "Receive and send events to external systems (webhooks).",
        ],
        boundedContext: "shared",
        dependsOn: ["0.1"],
        submodules: [
          { name: "Webhooks system (incoming/outgoing)" },
          { name: "External API gateway" },
          { name: "ERP connectors (accounting, banks…)" },
          { name: "Import/export pipelines" },
          { name: "Integration event bus separation" },
        ],
      },
      {
        code: "5.2",
        name: "Plugin / Extension System",
        icon: Puzzle,
        priority: "low",
        complexity: "high",
        objective: "A registry of per-tenant activatable modules with extension hooks.",
        userNeeds: [
          "Add or remove modules according to what my company needs.",
          "Pay only for the capabilities I actually use.",
        ],
        boundedContext: "shared",
        dependsOn: ["3.3"],
        submodules: [
          { name: "Module registry system" },
          { name: "Dynamic module enable/disable per tenant" },
          { name: "Extension hooks en domain events" },
          { name: "API extension points" },
        ],
      },
    ],
  },
  {
    code: "6",
    label: "Platform Ops (CI/CD + Delivery)",
    summary:
      "The delivery pipeline. Detailed in docs/saas-production-roadmap.md (phases A–H); this is the summary.",
    modules: [
      {
        code: "6.1",
        name: "CI/CD Pipeline System",
        icon: Rocket,
        priority: "high",
        complexity: "medium",
        objective: "Build pipeline, environment promotion and automatic rollback.",
        userNeeds: [
          "That new features and fixes arrive fast and without breaking anything.",
          "That it can be rolled back instantly if a deploy goes wrong.",
        ],
        boundedContext: "shared",
        submodules: [
          { name: "GitHub Actions pipelines", note: "quality + tests en push/PR" },
          { name: "Environment promotion (dev → staging → prod)" },
          { name: "Migration automation strategy" },
          { name: "Rollback system" },
          { name: "Build artifacts versioning" },
        ],
      },
      {
        code: "6.2",
        name: "Quality & Testing Infrastructure",
        icon: FlaskConical,
        priority: "high",
        complexity: "medium",
        objective: "BDD coverage, contract testing and event-driven test utilities.",
        userNeeds: [
          "Trust that every release is tested before it reaches me.",
          "Suffer fewer bugs and regressions in production.",
        ],
        boundedContext: "shared",
        submodules: [
          { name: "Behat BDD layer" },
          { name: "Contract testing (API)" },
          { name: "Integration test harness" },
          { name: "Event-driven test utilities" },
          { name: "Test data factories (DDD aligned)" },
        ],
      },
      {
        code: "6.3",
        name: "Deployment Infrastructure",
        icon: Container,
        priority: "medium",
        complexity: "medium",
        objective: "Compose environments, blue/green deploy and worker scaling.",
        userNeeds: [
          "That the app stays available without downtime during updates.",
          "That performance holds up even as load increases.",
        ],
        boundedContext: "shared",
        submodules: [
          { name: "Docker Compose environments" },
          { name: "Infrastructure as code (future)" },
          { name: "Blue/green o rolling deploy" },
          { name: "Background worker scaling (Messenger consumers)" },
        ],
      },
    ],
  },
];

/** Tally of submodules by effective status across the whole roadmap. */
export interface RoadmapProgress {
  done: number;
  inProgress: number;
  planned: number;
  total: number;
  /** Percentage of submodules that are `done`, rounded to an integer. */
  donePercent: number;
}

/** Resolve a submodule's effective status (`planned` unless declared). */
export function submoduleStatus(submodule: RoadmapSubmodule): RoadmapStatus {
  return submodule.status ?? "planned";
}

/**
 * Derive a module's status from its submodules: `done` only when every
 * submodule is done, `in-progress` as soon as any work exists, `planned`
 * otherwise. Declared nowhere — a module can never outrun its submodules.
 */
export function moduleStatus(module: RoadmapModule): RoadmapStatus {
  const statuses = module.submodules.map(submoduleStatus);
  if (statuses.length > 0 && statuses.every((status) => status === "done")) return "done";
  if (statuses.some((status) => status !== "planned")) return "in-progress";
  return "planned";
}

/** Compute progress over an arbitrary slice of phases (defaults to all). */
export function computeRoadmapProgress(phases: RoadmapPhase[] = roadmapPhases): RoadmapProgress {
  let done = 0;
  let inProgress = 0;
  let planned = 0;
  for (const phase of phases) {
    for (const mod of phase.modules) {
      for (const submodule of mod.submodules) {
        const status = submoduleStatus(submodule);
        if (status === "done") done += 1;
        else if (status === "in-progress") inProgress += 1;
        else planned += 1;
      }
    }
  }
  const total = done + inProgress + planned;
  const donePercent = total === 0 ? 0 : Math.round((done / total) * 100);
  return { done, inProgress, planned, total, donePercent };
}
