import {
  LucideIcon,
  MousePointerClick,
  LayoutDashboard,
  DoorOpen,
  BrainCircuit,
  Database,
  Zap,
  Cog,
  RadioTower,
  CheckCircle2,
} from "lucide-react";

/**
 * Technical-but-accessible "how it works" flows shown at
 * `/backoffice/docs/flow` ({@link DocsFlowPage}). Plain data — no React — so the
 * page stays a thin renderer and new flows are just new entries here (mirrors
 * how `roadmap.ts` drives the roadmap page). The voice is a developer explaining
 * the real pieces by their real names, in language a 16-year-old can follow:
 * `plain` builds the mental model, the optional `tech` aside names the exact
 * stack for whoever wants to go deeper.
 */
export type FlowTone = "brand" | "success" | "warning" | "accent";

export interface FlowStep {
  /**
   * Stable QA identity — the suffix of the step's `data-testid` (journey step
   * and map jump-link). A semantic slug tied to what the step *is*, so it stays
   * fixed under reorder/insertion — never an array index (test-id ADR, D2).
   */
  id: string;
  icon: LucideIcon;
  tone: FlowTone;
  /** Short, concrete title — names the real piece doing the work. */
  title: string;
  /** One or two sentences anyone can understand, using real concepts. */
  plain: string;
  /** Optional "behind the scenes" note with the exact technology. */
  tech?: string;
}

export interface FlowDiagramNode {
  id: string;
  label: string;
  /** One short clarifier line under the label. */
  sublabel?: string;
  /** Grid column (0-based, left to right). */
  col: number;
  /** Grid row (0-based, top to bottom). */
  row: number;
  /** Part of the background (asynchronous) path — rendered dashed. */
  async?: boolean;
}

export interface FlowDiagramEdge {
  from: string;
  to: string;
  label?: string;
  /** Request/response pair — arrowheads on both ends. */
  bidirectional?: boolean;
  /** Background (asynchronous) hop — rendered dashed. */
  async?: boolean;
}

export interface FlowDiagram {
  /** Accessible name for the SVG. */
  title: string;
  nodes: FlowDiagramNode[];
  edges: FlowDiagramEdge[];
}

export interface Flow {
  id: string;
  title: string;
  intro: string;
  diagram?: FlowDiagram;
  steps: FlowStep[];
}

const requestLifecycle: Flow = {
  id: "request-lifecycle",
  title: "The journey of a request",
  intro:
    "Every time you do something in ERPify (save, search, edit), your action travels through the system end to end and comes back. This is the real route, piece by piece and by their real names.",
  diagram: {
    title:
      "Diagram of a request's journey: the synchronous path runs from the browser to the database and back; the asynchronous path branches off the API towards the queue, the worker and Mercure.",
    nodes: [
      { id: "browser", label: "Browser", sublabel: "you", col: 0, row: 0 },
      { id: "pwa", label: "PWA (Next.js)", sublabel: "the interface", col: 1, row: 0 },
      { id: "proxy", label: "FrankenPHP", sublabel: "routes every request", col: 2, row: 0 },
      {
        id: "api",
        label: "API (Symfony)",
        sublabel: "validates and applies rules",
        col: 3,
        row: 0,
      },
      { id: "db", label: "PostgreSQL", sublabel: "permanent data", col: 4, row: 0 },
      {
        id: "event",
        label: "Domain event",
        sublabel: "\u201csomething happened\u201d",
        col: 3,
        row: 1,
        async: true,
      },
      {
        id: "worker",
        label: "Worker (Messenger)",
        sublabel: "emails, notifications, retries",
        col: 2,
        row: 2,
        async: true,
      },
      {
        id: "mercure",
        label: "Mercure",
        sublabel: "real time to the browser",
        col: 4,
        row: 2,
        async: true,
      },
    ],
    edges: [
      { from: "browser", to: "pwa", bidirectional: true },
      { from: "pwa", to: "proxy", label: "HTTP", bidirectional: true },
      { from: "proxy", to: "api", label: "/api/*", bidirectional: true },
      { from: "api", to: "db", label: "SQL", bidirectional: true },
      { from: "api", to: "event", async: true },
      { from: "event", to: "worker", label: "queue", async: true },
      { from: "worker", to: "mercure", label: "publishes", async: true },
    ],
  },
  steps: [
    {
      id: "browser-action",
      icon: MousePointerClick,
      tone: "brand",
      title: "Action in the browser",
      plain:
        "You press \u201cSave\u201d in the interface. The browser does not store anything by itself: its job is to capture your action and prepare it for the server, which is where the data lives.",
      tech: "A UI event in the PWA (Next.js / React).",
    },
    {
      id: "http-request",
      icon: LayoutDashboard,
      tone: "brand",
      title: "HTTP request",
      plain:
        "The application turns your action into an HTTP request: a structured message saying what you want to do and with which data. Everything you see in any web app travels this way.",
      tech: "The PWA calls the API with fetch; the data travels as JSON.",
    },
    {
      id: "server-routing",
      icon: DoorOpen,
      tone: "accent",
      title: "Routing on the server",
      plain:
        "On the server there is a single entry point that looks at every request and directs it: those starting with /api/* go to the API (the logic); everything else goes to the interface. One door, well guarded.",
      tech: "FrankenPHP (with Caddy embedded) acts as a reverse proxy.",
    },
    {
      id: "api-rules",
      icon: BrainCircuit,
      tone: "success",
      title: "The API applies the rules",
      plain:
        "The API checks that the data is valid and that the operation is allowed, and only then runs the business logic. Nothing touches the data without going through the rules.",
      tech: "A Symfony controller delegates to a use case (application layer).",
    },
    {
      id: "database",
      icon: Database,
      tone: "success",
      title: "Database",
      plain:
        "Data is written to or read from the database: a structured, permanent store. Saving there is what makes your change survive shutdowns, failures and restarts.",
      tech: "PostgreSQL through Doctrine, inside a transaction.",
    },
    {
      id: "domain-event",
      icon: Zap,
      tone: "warning",
      title: "Domain event",
      plain:
        "Beyond saving, the system records the fact under a name: \u201ca bank has been created\u201d. Other pieces can react to that event without knowing about each other \u2014 that is how the system grows without tangling.",
      tech: "The domain event is recorded and persisted (audit / outbox).",
    },
    {
      id: "queue-worker",
      icon: Cog,
      tone: "warning",
      title: "Queue and worker",
      plain:
        "Whatever can wait (sending an email, notifying other systems) is put on a queue and processed by a worker: a separate process running in the background. Your request answers quickly, and if something fails along the way it is retried.",
      tech: "Symfony Messenger delivers the event to an asynchronous worker.",
    },
    {
      id: "realtime",
      icon: RadioTower,
      tone: "brand",
      title: "Real time",
      plain:
        "The server publishes the change and every subscribed browser receives it instantly over a connection that stays open. That is why someone else sees your change without reloading the page.",
      tech: "Mercure (server-sent events); the PWA refreshes the view on receipt.",
    },
    {
      id: "response",
      icon: CheckCircle2,
      tone: "success",
      title: "Response",
      plain:
        "The API answers with the result and the interface reflects it. If something goes wrong you get an error in a standard format that explains what happened and makes it traceable.",
      tech: "JSON response; errors follow RFC 9457 (Problem Details).",
    },
  ],
};

export const flows: Flow[] = [requestLifecycle];
