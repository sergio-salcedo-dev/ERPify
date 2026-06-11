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
 *
 * Status is declared ONLY at the submodule level, where it can be checked
 * against real code; an omitted submodule status means `planned` — never an
 * inherited optimism. A module's status is DERIVED from its submodules
 * ({@link moduleStatus}), so a module can never look more advanced than the
 * work inside it.
 *
 * Language: module/submodule names are technical identifiers kept in English
 * (they match how the codebase and docs refer to them); narrative fields
 * (`summary`, `objective`, `userNeeds`, notes) are Spanish until the i18n
 * module (0.8) makes the whole surface translatable.
 *
 * `objective` is the engineering "definition of done"; `userNeeds` is the
 * complementary user-facing view — the jobs a user expects ERPify to solve for
 * them. Foundation/ops modules frame the need from the operator/admin's seat.
 */
export type RoadmapStatus = "done" | "in-progress" | "planned";

export type RoadmapPriority = "critical" | "high" | "medium" | "low";

export interface RoadmapSubmodule {
  name: string;
  /** Defaults to `planned` when omitted — progress is always opt-in. */
  status?: RoadmapStatus;
  /** Short clarifier shown as a muted note. */
  note?: string;
}

export interface RoadmapModule {
  /** Stable dotted code used for cross-references and test ids, e.g. "0.3". */
  code: string;
  name: string;
  icon: LucideIcon;
  priority: RoadmapPriority;
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
    label: "Fundación de plataforma",
    summary:
      "La base enterprise-grade sobre la que se apoya todo lo demás. Probada por las dos verticales ya entregadas (Banks, Service Health); lo que falta es generalizarla en capacidades reutilizables.",
    modules: [
      {
        code: "0.3",
        name: "Event-Driven Backbone",
        icon: Workflow,
        priority: "critical",
        objective: "Todo el sistema reacciona a eventos internos sin acoplamiento.",
        userNeeds: [
          "Que mis acciones disparen efectos automáticos (avisos, tareas) sin esperar.",
          "Que nada se pierda aunque un proceso falle por el camino.",
          "Que la app siga ágil aunque crezca el volumen de trabajo.",
        ],
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
        priority: "critical",
        objective: "Consistencia fuerte entre la DB y los eventos publicados.",
        userNeeds: [
          "Que un cambio guardado nunca quede a medias respecto a sus avisos o integraciones.",
          "Que no me lleguen notificaciones duplicadas por un mismo hecho.",
        ],
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
        priority: "critical",
        objective: "Estandarizar el acceso a datos de forma componible.",
        userNeeds: [
          "Buscar, filtrar y ordenar cualquier listado al instante.",
          "Trabajar con listas grandes sin que la pantalla se congele.",
          "Recibir errores claros y entendibles, no pantallazos técnicos.",
        ],
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
        priority: "critical",
        objective: "Base SaaS enterprise: aislamiento por tenant y autorización.",
        userNeeds: [
          "Entrar de forma segura con mi cuenta.",
          "Que cada empresa solo pueda ver y tocar sus propios datos.",
          "Permisos por rol: cada persona ve y hace exactamente lo que le toca.",
        ],
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
        priority: "high",
        objective: "Sistema observable desde el primer día.",
        userNeeds: [
          "Que si algo falla se detecte antes de que me afecte.",
          "Que soporte pueda rastrear mi incidencia concreta por un identificador.",
        ],
        boundedContext: "shared",
        submodules: [
          { name: "Structured logging standard", status: "done" },
          { name: "Correlation ID propagation", status: "done", note: "crítico con Messenger" },
          { name: "Sentry integration (backend + frontend)", status: "done" },
          { name: "Metrics hooks (futuro Prometheus)", status: "planned" },
          { name: "Event tracing hooks (domain → async)", status: "planned" },
        ],
      },
      {
        code: "0.8",
        name: "Internationalization (i18n)",
        icon: Languages,
        priority: "high",
        objective:
          "Toda la app disponible en inglés y español, con el idioma como preferencia del usuario.",
        userNeeds: [
          "Usar toda la aplicación en mi idioma (español o inglés).",
          "Que fechas, números y moneda se muestren en mi formato local.",
          "Recibir emails y mensajes de error también en mi idioma.",
        ],
        boundedContext: "shared",
        submodules: [
          { name: "Catálogos de traducción PWA (next-intl)" },
          { name: "Detección + conmutación de locale (EN/ES)" },
          { name: "Selector de idioma + persistencia (preferencia de usuario)" },
          { name: "Traducciones backend (Symfony Translation: validación, emails)" },
          { name: "Mensajes de error RFC 9457 localizados" },
          {
            name: "Formato de fecha/número/moneda por locale",
            status: "in-progress",
            note: "dateTimeProvider ya formatea por locale",
          },
          { name: "Cobertura de traducción en CI (claves faltantes fallan)" },
        ],
      },
      {
        code: "0.9",
        name: "Audit & Activity Trail",
        icon: ScrollText,
        priority: "high",
        objective: "Trazabilidad completa: quién cambió qué y cuándo, consultable y exportable.",
        userNeeds: [
          "Saber quién cambió qué y cuándo en cualquier registro.",
          "Recuperar el historial completo de cambios de un dato.",
          "Exportar el rastro de actividad para una auditoría o un cliente.",
        ],
        boundedContext: "shared",
        dependsOn: ["0.6"],
        submodules: [
          { name: "Audit table schema (domain_event)", status: "done" },
          { name: "Audit log viewer (UI /backoffice/audit)" },
          { name: "Filtro + búsqueda por entidad / usuario / acción" },
          { name: "Historial de cambios por entidad (timeline)" },
          { name: "Consultas de auditoría tenant-scoped" },
          { name: "Política de retención + archivado" },
          { name: "Export (CSV / PDF)" },
          { name: "Tamper-evidence / integridad del registro" },
        ],
      },
      {
        code: "0.10",
        name: "Media & Document System",
        icon: FolderArchive,
        priority: "high",
        objective:
          "Centralizar la gestión de archivos (imágenes, PDFs, Excel…) desacoplada del dominio de negocio. Core transversal, no un módulo más.",
        userNeeds: [
          "Adjuntar y recuperar archivos (logos, fotos de obra, PDFs, facturas) desde cualquier módulo.",
          "Reutilizar un mismo archivo en varias entidades sin duplicarlo.",
          "Tener mis documentos de obra versionados, con estados (borrador/aprobado/archivado) e historial.",
          "Descargas rápidas y seguras (enlaces temporales) sin depender de quién lo subió.",
        ],
        boundedContext: "shared",
        submodules: [
          {
            name: "F0 · Diseño del modelo (MediaFile, StorageProviderInterface, MIME)",
            status: "done",
          },
          {
            name: "F1 · Storage local + upload/download por tenant",
            status: "in-progress",
            note: "Bank ya sube/recupera ficheros",
          },
          { name: "F2 · Media Library genérica (reutilización, sin duplicar, tags)" },
          { name: "F3 · Document System (agregado Document, versionado, tipos, estados)" },
          {
            name: "F4 · Storage abstraction (S3/MinIO, swap por config)",
            note: "Flysystem ya provee la abstracción; falta adapter S3/MinIO",
          },
          { name: "F5 · Optimización SaaS (URLs firmadas, CDN, thumbnails, OCR)" },
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
        priority: "high",
        objective: "Usuarios, empresas (frontera multi-tenant) y equipos.",
        userNeeds: [
          "Gestionar los usuarios y equipos de mi empresa.",
          "Invitar gente y asignarle el rol y los permisos adecuados.",
          "Editar mi perfil, mi contraseña y mis preferencias.",
        ],
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
        priority: "high",
        objective: "Gestión de clientes, pipeline y actividad comercial.",
        userNeeds: [
          "Tener todos mis clientes, contactos y oportunidades en un único sitio.",
          "Seguir cada oportunidad por sus fases hasta cerrarla.",
          "Registrar llamadas, tareas, emails y notas sin perder el hilo.",
          "No olvidarme nunca de un seguimiento comercial.",
        ],
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
        priority: "high",
        objective: "Vertical CORE: gestión de obra de principio a fin.",
        userNeeds: [
          "Controlar cada obra de principio a fin desde un solo lugar.",
          "Ver el avance real por fases e hitos en tiempo real.",
          "Saber quién está haciendo qué tarea y cuándo.",
        ],
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
        priority: "high",
        objective: "Presupuestos por proyecto con estructura de coste y versionado.",
        userNeeds: [
          "Hacer presupuestos por obra con sus partidas de coste.",
          "Reutilizar plantillas para no empezar de cero cada vez.",
          "Comparar lo previsto frente a lo real y ver el margen.",
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
        ],
      },
      {
        code: "1.5",
        name: "Procurement & Inventory (Almacén)",
        icon: PackageSearch,
        priority: "high",
        objective: "Proveedores, compras y control de stock por proyecto.",
        userNeeds: [
          "Saber qué material tengo y en qué almacén está.",
          "Lanzar y seguir pedidos a proveedores.",
          "Reservar material para una obra concreta.",
          "Que me avise antes de quedarme sin stock.",
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
        priority: "medium",
        objective: "Personal, subcontratas y partes de trabajo con coste por hora.",
        userNeeds: [
          "Registrar las horas trabajadas por tarea y por obra.",
          "Llevar los partes de trabajo de empleados y subcontratas.",
          "Saber el coste real de mano de obra de cada proyecto.",
          "Planificar la carga de trabajo del equipo.",
        ],
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
        priority: "medium",
        objective:
          "Capa de negocio documental sobre el core Media (0.10): documentos por proyecto, plantillas y permisos. Storage y versionado los aporta 0.10 — aquí no se duplican.",
        userNeeds: [
          "Guardar todos los documentos de una obra juntos y encontrarlos rápido.",
          "Acceder siempre a la última versión de cada documento.",
          "Controlar quién puede ver o editar cada documento.",
        ],
        boundedContext: "shared",
        dependsOn: ["0.6", "0.10"],
        submodules: [
          { name: "Project-linked documents" },
          { name: "Templates (contratos, informes)" },
          { name: "Permissions per document" },
          { name: "Audit trail documental", note: "sobre la base de 0.9" },
        ],
      },
      {
        code: "2.3",
        name: "Finance Layer (ERP Financial Core)",
        icon: Banknote,
        priority: "high",
        objective: "Facturación, pagos y agregación de coste por proyecto.",
        userNeeds: [
          "Emitir y controlar facturas de cliente y de proveedor.",
          "Seguir cobros y pagos pendientes.",
          "Ver el cashflow y la tesorería de un vistazo.",
          "Saber el coste y el margen real de cada proyecto.",
        ],
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
        priority: "high",
        objective: "Motor de reglas y workflows que reacciona a eventos del dominio.",
        userNeeds: [
          "Automatizar tareas repetitivas con reglas 'si pasa X, haz Y'.",
          "Diseñar mis propios flujos de trabajo sin programar.",
          "Que el sistema cree tareas y avise por mí, solo.",
        ],
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
        priority: "medium",
        objective: "Centro de notificaciones in-app, email y push con preferencias por usuario.",
        userNeeds: [
          "Enterarme al momento de lo que me afecta.",
          "Elegir qué avisos quiero recibir y por qué canal (app, email o push).",
          "Ver mis notificaciones en un único centro.",
        ],
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
        priority: "low",
        objective: "Activación por tenant/usuario y rollout gradual.",
        userNeeds: [
          "Activar o desactivar funciones para mi empresa sin esperar a un despliegue.",
          "Probar novedades con un grupo reducido antes de abrirlas a todos.",
        ],
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
        priority: "medium",
        objective: "KPIs configurables y dashboards de proyecto/financieros con export.",
        userNeeds: [
          "Ver dashboards de obra y financieros de un vistazo.",
          "Crear mis propios KPIs sin depender de nadie.",
          "Exportar informes a PDF o Excel para compartir.",
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
        objective: "Desviación de coste, rentabilidad y detección de cuellos de botella.",
        userNeeds: [
          "Detectar desviaciones de coste a tiempo, no cuando ya es tarde.",
          "Saber qué proyectos son realmente rentables.",
          "Anticipar cuellos de botella antes de que frenen la obra.",
        ],
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
        priority: "low",
        objective: "Webhooks, gateway de API externa y pipelines de import/export.",
        userNeeds: [
          "Conectar ERPify con mi contabilidad, mi banco u otras herramientas.",
          "Importar y exportar datos sin tener que teclearlos a mano.",
          "Recibir y enviar eventos a sistemas externos (webhooks).",
        ],
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
        priority: "low",
        objective: "Registro de módulos activables por tenant con hooks de extensión.",
        userNeeds: [
          "Añadir o quitar módulos según lo que necesite mi empresa.",
          "Pagar solo por las capacidades que realmente uso.",
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
      "La tubería de entrega. Detallada en docs/saas-production-roadmap.md (fases A–H); aquí va el resumen.",
    modules: [
      {
        code: "6.1",
        name: "CI/CD Pipeline System",
        icon: Rocket,
        priority: "high",
        objective: "Pipeline de build, promoción de entornos y rollback automático.",
        userNeeds: [
          "Que las novedades y correcciones lleguen rápido y sin romper nada.",
          "Que se pueda volver atrás al instante si un despliegue sale mal.",
        ],
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
        priority: "high",
        objective: "Cobertura BDD, contract testing y utilidades de test event-driven.",
        userNeeds: [
          "Confiar en que cada versión está probada antes de llegar a mí.",
          "Sufrir menos errores y regresiones en producción.",
        ],
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
        priority: "medium",
        objective: "Entornos Compose, deploy blue/green y escalado de workers.",
        userNeeds: [
          "Que la app esté disponible sin cortes durante las actualizaciones.",
          "Que el rendimiento se mantenga aunque aumente la carga.",
        ],
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
