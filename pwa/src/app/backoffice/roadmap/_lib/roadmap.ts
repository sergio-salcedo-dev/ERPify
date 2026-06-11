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
} from "lucide-react";

/**
 * Single source of truth for the product + engineering roadmap. The in-app
 * "backlog vivo" at `/backoffice/roadmap` ({@link RoadmapPage}) renders straight
 * from this data, mirroring how the sidebar is built from `backofficeMenu.ts`,
 * so the UI and the plan can never drift. Kept as plain typed data — no React,
 * no framework imports — so it stays portable (a future API/DB seed or a
 * GitHub-Projects export can consume the same shape).
 *
 * `status` reflects the real state of the codebase, not aspiration:
 * - `done`        — shipped and exercised by a real vertical slice (Banks, Health)
 *                   or by existing infra (CI, Compose, error contract).
 * - `in-progress` — partially present; foundations exist but the module is not
 *                   yet a finished, reusable capability.
 * - `planned`     — not started.
 */
export type RoadmapStatus = "done" | "in-progress" | "planned";

export type RoadmapPriority = "critical" | "high" | "medium" | "low";

export interface RoadmapSubmodule {
  name: string;
  /** Defaults to the parent module's status when omitted. */
  status?: RoadmapStatus;
  /** Short clarifier shown as a muted note. */
  note?: string;
}

export interface RoadmapModule {
  /** Stable dotted code used for cross-references and test ids, e.g. "0.3". */
  code: string;
  name: string;
  icon: LucideIcon;
  status: RoadmapStatus;
  priority: RoadmapPriority;
  /** What "done" means for this module, one line. */
  objective: string;
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
    label: "Fundación de plataforma",
    summary:
      "La base enterprise-grade sobre la que se apoya todo lo demás. Probada por las dos verticales ya entregadas (Banks, Service Health); lo que falta es generalizarla en capacidades reutilizables.",
    modules: [
      {
        code: "0.3",
        name: "Event-Driven Backbone",
        icon: Workflow,
        status: "in-progress",
        priority: "critical",
        objective: "Todo el sistema reacciona a eventos internos sin acoplamiento.",
        boundedContext: "shared",
        submodules: [
          { name: "Command Bus / Query Bus (CQRS light)", status: "in-progress" },
          { name: "Domain Event Dispatcher", status: "done" },
          { name: "Async Job Pipeline (Messenger transports)", status: "done" },
          { name: "Retry / failure strategy (DLQ)", status: "planned" },
          { name: "Idempotency layer", status: "planned", note: "crítico para ERP" },
          { name: "Event versioning strategy", status: "planned" },
          { name: "Integration Events vs Domain Events", status: "in-progress" },
        ],
      },
      {
        code: "0.4",
        name: "Transactional Outbox System",
        icon: Inbox,
        status: "in-progress",
        priority: "critical",
        objective: "Consistencia fuerte entre la DB y los eventos publicados.",
        boundedContext: "shared",
        dependsOn: ["0.3"],
        submodules: [
          { name: "Outbox table schema", status: "done" },
          { name: "Outbox writer (decorator de persistencia)", status: "in-progress" },
          { name: "Outbox publisher worker (Messenger consumer)", status: "done" },
          { name: "Retry + backoff strategy", status: "planned" },
          { name: "Deduplication / idempotency keys", status: "planned" },
          { name: "Event serialization registry", status: "planned" },
        ],
      },
      {
        code: "0.5",
        name: "API & Query Infrastructure",
        icon: Database,
        status: "in-progress",
        priority: "critical",
        objective: "Estandarizar el acceso a datos de forma componible.",
        boundedContext: "shared",
        submodules: [
          { name: "Criteria Query System (filtros componibles)", status: "done" },
          { name: "Pagination (cursor-based + offset fallback)", status: "done" },
          { name: "Sorting DSL", status: "done" },
          { name: "API Response Standard (RFC 9457)", status: "done" },
          { name: "DTO mapping layer (no exponer entidades)", status: "done" },
          { name: "Read model separation (futuro CQRS)", status: "planned" },
        ],
      },
      {
        code: "0.6",
        name: "Security & Multi-Tenant Core",
        icon: ShieldCheck,
        status: "in-progress",
        priority: "critical",
        objective: "Base SaaS enterprise: aislamiento por tenant y autorización.",
        boundedContext: "shared",
        submodules: [
          { name: "Tenant Context Resolver", status: "planned" },
          { name: "RBAC engine (roles + permisos)", status: "planned" },
          { name: "Future ABAC hooks (policies)", status: "planned" },
          { name: "JWT auth + refresh strategy", status: "planned" },
          { name: "Session / device tracking", status: "planned" },
          { name: "Audit log base system", status: "done", note: "tabla domain_event" },
        ],
      },
      {
        code: "0.7",
        name: "Observability Foundation",
        icon: Activity,
        status: "in-progress",
        priority: "high",
        objective: "Sistema observable desde el primer día.",
        boundedContext: "shared",
        submodules: [
          { name: "Structured logging standard", status: "done" },
          { name: "Correlation ID propagation", status: "done", note: "crítico con Messenger" },
          { name: "Sentry integration (backend + frontend)", status: "done" },
          { name: "Metrics hooks (futuro Prometheus)", status: "planned" },
          { name: "Event tracing hooks (domain → async)", status: "planned" },
        ],
      },
    ],
  },
  {
    code: "1",
    label: "Core ERP/CRM operativo",
    summary:
      "Donde empieza el valor de negocio real. Banks es la vertical de referencia ya entregada.",
    modules: [
      {
        code: "1.1",
        name: "Identity & Organization",
        icon: UsersRound,
        status: "planned",
        priority: "high",
        objective: "Usuarios, empresas (frontera multi-tenant) y equipos.",
        boundedContext: "organization",
        dependsOn: ["0.6"],
        submodules: [
          { name: "Users" },
          { name: "Companies (multi-tenant boundary)" },
          { name: "Teams" },
          { name: "Roles & Permissions (capa UI)" },
          { name: "User profile & preferences" },
        ],
      },
      {
        code: "1.2",
        name: "CRM (Sales & Customer Management)",
        icon: HeartHandshake,
        status: "planned",
        priority: "high",
        objective: "Gestión de clientes, pipeline y actividad comercial.",
        boundedContext: "frontoffice",
        dependsOn: ["1.1"],
        submodules: [
          { name: "Leads ingestion" },
          { name: "Contacts & Companies CRM" },
          { name: "Pipeline (stages configurables)" },
          { name: "Opportunities (deals)" },
          { name: "Activities (llamadas, tareas, emails)" },
          { name: "Notes & attachments" },
          { name: "Basic automation triggers (event-driven)" },
        ],
      },
      {
        code: "1.3",
        name: "Projects / Construction Management",
        icon: HardHat,
        status: "planned",
        priority: "high",
        objective: "Vertical CORE: gestión de obra de principio a fin.",
        boundedContext: "operations",
        dependsOn: ["1.1"],
        submodules: [
          { name: "Projects (obra)" },
          { name: "Phases / milestones" },
          { name: "Tasks & assignments" },
          { name: "Progress tracking" },
          { name: "Project status lifecycle" },
          { name: "Resource allocation hooks" },
        ],
      },
      {
        code: "1.4",
        name: "Budgeting & Estimation Engine",
        icon: Calculator,
        status: "planned",
        priority: "high",
        objective: "Presupuestos por proyecto con estructura de coste y versionado.",
        boundedContext: "operations",
        dependsOn: ["1.3"],
        submodules: [
          { name: "Budget creation per project" },
          { name: "Line-item cost structure" },
          { name: "Estimation templates" },
          { name: "Versioning of budgets" },
          { name: "Margin calculation" },
          { name: "Forecast vs actual structure" },
        ],
      },
      {
        code: "1.5",
        name: "Procurement & Inventory (Almacén)",
        icon: PackageSearch,
        status: "planned",
        priority: "high",
        objective: "Proveedores, compras y control de stock por proyecto.",
        boundedContext: "operations",
        dependsOn: ["1.3"],
        submodules: [
          { name: "Suppliers" },
          { name: "Purchase orders" },
          { name: "Material catalog" },
          { name: "Stock locations" },
          { name: "Stock movements (in/out/transfer)" },
          { name: "Reservation system per project" },
          { name: "Reorder logic (futura automatización)" },
        ],
      },
    ],
  },
  {
    code: "2",
    label: "Operaciones avanzadas",
    summary:
      "Capacidades operativas que apalancan el core: personas, documentos y el núcleo financiero.",
    modules: [
      {
        code: "2.1",
        name: "Workforce & Time Tracking",
        icon: Clock,
        status: "planned",
        priority: "medium",
        objective: "Personal, subcontratas y partes de trabajo con coste por hora.",
        boundedContext: "operations",
        dependsOn: ["1.3"],
        submodules: [
          { name: "Employees" },
          { name: "Subcontractors" },
          { name: "Work logs / partes de trabajo" },
          { name: "Time tracking (horas por tarea/proyecto)" },
          { name: "Scheduling / workload planning" },
          { name: "Cost per labor unit" },
        ],
      },
      {
        code: "2.2",
        name: "Document Management System (DMS)",
        icon: FileStack,
        status: "planned",
        priority: "medium",
        objective: "Almacenamiento, versionado y permisos de documentos ligados a proyecto.",
        boundedContext: "shared",
        dependsOn: ["0.6"],
        submodules: [
          {
            name: "File storage abstraction (S3/local)",
            status: "in-progress",
            note: "Banks ya sube ficheros",
          },
          { name: "Document versioning" },
          { name: "Project-linked documents" },
          { name: "Templates (contratos, informes)" },
          { name: "Permissions per document" },
          { name: "Audit trail" },
        ],
      },
      {
        code: "2.3",
        name: "Finance Layer (ERP Financial Core)",
        icon: Banknote,
        status: "in-progress",
        priority: "high",
        objective: "Facturación, pagos y agregación de coste por proyecto.",
        boundedContext: "backoffice",
        dependsOn: ["1.4"],
        submodules: [
          { name: "Banks / Treasury (vertical de referencia)", status: "done" },
          { name: "Invoices (cliente/proveedor)" },
          { name: "Payments tracking" },
          { name: "Cashflow view" },
          { name: "Cost aggregation per project" },
          { name: "Tax abstraction layer (future-proof)" },
          { name: "Financial reporting engine" },
        ],
      },
    ],
  },
  {
    code: "3",
    label: "Automation & Intelligence",
    summary:
      "El diferenciador del producto: motor de automatización, notificaciones y feature flags.",
    modules: [
      {
        code: "3.1",
        name: "Automation Engine",
        icon: Cog,
        status: "planned",
        priority: "high",
        objective: "Motor de reglas y workflows que reacciona a eventos del dominio.",
        boundedContext: "shared",
        dependsOn: ["0.3"],
        submodules: [
          { name: "Event trigger registry" },
          { name: "Rules engine (IF/THEN)" },
          { name: "Workflow engine (state machine + DAG)" },
          { name: "Action system (email, update entity, create task)" },
          { name: "Retry + compensation (sagas light)" },
          { name: "Visual workflow builder (UI clave)" },
        ],
      },
      {
        code: "3.2",
        name: "Notification System",
        icon: Bell,
        status: "in-progress",
        priority: "medium",
        objective: "Centro de notificaciones in-app, email y push con preferencias por usuario.",
        boundedContext: "shared",
        dependsOn: ["0.3"],
        submodules: [
          {
            name: "Notification center (in-app)",
            status: "in-progress",
            note: "toasts + Mercure realtime",
          },
          {
            name: "Email notifications",
            status: "in-progress",
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
        status: "planned",
        priority: "low",
        objective: "Activación por tenant/usuario y rollout gradual.",
        boundedContext: "shared",
        dependsOn: ["0.6"],
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
      "Convierte los datos operativos en decisiones: reporting configurable y analítica avanzada.",
    modules: [
      {
        code: "4.1",
        name: "Reporting Engine",
        icon: BarChart3,
        status: "planned",
        priority: "medium",
        objective: "KPIs configurables y dashboards de proyecto/financieros con export.",
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
        status: "planned",
        priority: "low",
        objective: "Desviación de coste, rentabilidad y detección de cuellos de botella.",
        boundedContext: "backoffice",
        dependsOn: ["4.1"],
        submodules: [
          { name: "Cost deviation analysis" },
          { name: "Project profitability tracking" },
          { name: "Forecasting engine (rule-based inicialmente)" },
          { name: "Bottleneck detection (workflow + time analysis)" },
        ],
      },
    ],
  },
  {
    code: "5",
    label: "Integration & Platform Extension",
    summary: "Abre la plataforma al exterior: webhooks, conectores y un sistema de extensiones.",
    modules: [
      {
        code: "5.1",
        name: "Integration Layer",
        icon: Webhook,
        status: "planned",
        priority: "low",
        objective: "Webhooks, gateway de API externa y pipelines de import/export.",
        boundedContext: "shared",
        dependsOn: ["0.3"],
        submodules: [
          { name: "Webhooks system (incoming/outgoing)" },
          { name: "External API gateway" },
          { name: "ERP connectors (contabilidad, bancos…)" },
          { name: "Import/export pipelines" },
          { name: "Integration event bus separation" },
        ],
      },
      {
        code: "5.2",
        name: "Plugin / Extension System",
        icon: Puzzle,
        status: "planned",
        priority: "low",
        objective: "Registro de módulos activables por tenant con hooks de extensión.",
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
      "La tubería de entrega. Detallada en docs/saas-production-roadmap.md (fases A–H); aquí va el resumen.",
    modules: [
      {
        code: "6.1",
        name: "CI/CD Pipeline System",
        icon: Rocket,
        status: "in-progress",
        priority: "high",
        objective: "Pipeline de build, promoción de entornos y rollback automático.",
        boundedContext: "shared",
        submodules: [
          { name: "GitHub Actions pipelines", status: "done", note: "quality + tests en push/PR" },
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
        status: "in-progress",
        priority: "high",
        objective: "Cobertura BDD, contract testing y utilidades de test event-driven.",
        boundedContext: "shared",
        submodules: [
          { name: "Behat BDD layer", status: "done" },
          { name: "Contract testing (API)" },
          { name: "Integration test harness", status: "in-progress" },
          { name: "Event-driven test utilities" },
          { name: "Test data factories (DDD aligned)", status: "in-progress" },
        ],
      },
      {
        code: "6.3",
        name: "Deployment Infrastructure",
        icon: Container,
        status: "in-progress",
        priority: "medium",
        objective: "Entornos Compose, deploy blue/green y escalado de workers.",
        boundedContext: "shared",
        submodules: [
          { name: "Docker Compose environments", status: "done" },
          { name: "Infrastructure as code (futuro)" },
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

/** Resolve a submodule's effective status (falls back to its module's). */
export function effectiveSubmoduleStatus(
  submodule: RoadmapSubmodule,
  module: RoadmapModule,
): RoadmapStatus {
  return submodule.status ?? module.status;
}

/** Compute progress over an arbitrary slice of phases (defaults to all). */
export function computeRoadmapProgress(phases: RoadmapPhase[] = roadmapPhases): RoadmapProgress {
  let done = 0;
  let inProgress = 0;
  let planned = 0;
  for (const phase of phases) {
    for (const mod of phase.modules) {
      for (const submodule of mod.submodules) {
        const status = effectiveSubmoduleStatus(submodule, mod);
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
