import type { Metadata } from "next";
import Link from "next/link";
import { Compass, Users, Boxes, Radio, ArrowUpRight, type LucideIcon } from "lucide-react";
import { Routes } from "@/context/shared/routing/domain/Routes";
import {
  erpifyOverview,
  domainContexts,
  integrationEvents,
  contextsByArea,
  AREA_LABEL,
  AREA_ORDER,
  type ContextArea,
  type DomainContext,
} from "./_lib/domainModel";

export const metadata: Metadata = {
  title: "About ERPify",
  description:
    "What ERPify is and what it is for: ubiquitous language, bounded contexts, and the domain events that integrate them.",
};

const AREA_ICON: Record<ContextArea, LucideIcon> = {
  commercial: Users,
  site: Compass,
  operations: Boxes,
  finance: Boxes,
  platform: Radio,
};

const REFERENCES: ReadonlyArray<{ label: string; href: string; description: string }> = [
  {
    label: "Cómo funciona ERPify",
    href: `${Routes.BACKOFFICE}/docs/flow`,
    description: "Los flujos de trabajo paso a paso.",
  },
  {
    label: "Product Roadmap",
    href: `${Routes.BACKOFFICE}/roadmap`,
    description: "El plan por fases y módulos, con su estado.",
  },
];

function Chip({ children }: Readonly<{ children: React.ReactNode }>) {
  return (
    <span className="about__chip border-border text-muted-foreground inline-flex items-center gap-1 rounded-md border px-1.5 py-0.5 text-2xs font-medium">
      {children}
    </span>
  );
}

function EventTag({ name, tone }: Readonly<{ name: string; tone: "emit" | "consume" }>) {
  return (
    <span
      className={
        tone === "emit"
          ? "about__event about__event--emit bg-success/10 text-success inline-flex items-center rounded px-1.5 py-0.5 font-mono text-2xs"
          : "about__event about__event--consume bg-muted text-muted-foreground inline-flex items-center rounded px-1.5 py-0.5 font-mono text-2xs"
      }
    >
      {name}
    </span>
  );
}

function ContextCard({ context }: Readonly<{ context: DomainContext }>) {
  return (
    <article
      className="about__context bg-card border-border flex flex-col gap-3 rounded-xl border p-4 shadow-sm"
      data-testid={`about__context-${context.key}`}
    >
      <header className="about__context-head flex items-start justify-between gap-2">
        <h4 className="text-foreground text-sm font-semibold tracking-tight">{context.name}</h4>
        {context.roadmap ? <Chip>Roadmap {context.roadmap}</Chip> : null}
      </header>

      <p className="about__context-responsibility text-muted-foreground text-xs leading-relaxed">
        {context.responsibility}
      </p>

      <div className="about__lang flex flex-col gap-1.5">
        <p className="text-foreground text-2xs font-semibold tracking-wider uppercase">
          Lenguaje ubicuo
        </p>
        <dl className="about__lang-list flex flex-col gap-1">
          {context.ubiquitousLanguage.map((entry) => (
            <div key={entry.term} className="about__lang-entry flex flex-col text-xs">
              <dt className="text-foreground font-mono font-medium">{entry.term}</dt>
              <dd className="text-muted-foreground">{entry.definition}</dd>
            </div>
          ))}
        </dl>
      </div>

      <div className="about__aggregates flex flex-wrap gap-1">
        {context.aggregates.map((aggregate) => (
          <Chip key={aggregate}>{aggregate}</Chip>
        ))}
      </div>

      <div className="about__events flex flex-col gap-2">
        {context.emits.length ? (
          <div className="flex flex-col gap-1">
            <p className="text-muted-foreground text-2xs font-semibold tracking-wider uppercase">
              Emite
            </p>
            <div className="flex flex-wrap gap-1">
              {context.emits.map((event) => (
                <EventTag key={event} name={event} tone="emit" />
              ))}
            </div>
          </div>
        ) : null}
        {context.consumes.length ? (
          <div className="flex flex-col gap-1">
            <p className="text-muted-foreground text-2xs font-semibold tracking-wider uppercase">
              Consume
            </p>
            <div className="flex flex-wrap gap-1">
              {context.consumes.map((event) => (
                <EventTag key={event} name={event} tone="consume" />
              ))}
            </div>
          </div>
        ) : null}
      </div>
    </article>
  );
}

function AreaSection({ area }: Readonly<{ area: ContextArea }>) {
  const contexts = contextsByArea(area);
  if (!contexts.length) return null;
  const Icon = AREA_ICON[area];
  return (
    <section className="about__area flex flex-col gap-4" data-testid={`about__area-${area}`}>
      <header className="about__area-head flex items-center gap-2">
        <span className="about__area-icon bg-muted text-muted-foreground flex size-8 flex-none items-center justify-center rounded-lg">
          <Icon className="size-4" aria-hidden="true" />
        </span>
        <h3 className="text-foreground text-base font-semibold tracking-tight">
          {AREA_LABEL[area]}
        </h3>
      </header>
      <div className="about__context-grid grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
        {contexts.map((context) => (
          <ContextCard key={context.key} context={context} />
        ))}
      </div>
    </section>
  );
}

export default function AboutPage() {
  return (
    <div className="about flex flex-col gap-10">
      <header className="about__hero flex flex-col gap-3" data-testid="about__hero">
        <h1
          className="text-foreground text-2xl font-semibold tracking-tight"
          data-testid="about__title"
        >
          Qué es ERPify
        </h1>
        <p className="about__tagline text-foreground max-w-3xl text-base leading-relaxed">
          {erpifyOverview.tagline}
        </p>
        <p className="text-muted-foreground max-w-3xl text-sm leading-relaxed">
          {erpifyOverview.whatItIs}
        </p>
        <p className="text-muted-foreground max-w-3xl text-sm leading-relaxed">
          {erpifyOverview.whatFor}
        </p>
      </header>

      <section className="about__north bg-card border-border flex flex-col gap-2 rounded-xl border p-4 shadow-sm">
        <h2 className="text-foreground flex items-center gap-2 text-sm font-semibold">
          <Compass className="text-muted-foreground size-4" aria-hidden="true" />
          Norte estratégico
        </h2>
        <p className="text-muted-foreground max-w-3xl text-sm leading-relaxed">
          {erpifyOverview.strategicNorth}
        </p>
      </section>

      <section className="about__personas flex flex-col gap-4">
        <h2 className="text-foreground text-lg font-semibold tracking-tight">Para quién es</h2>
        <div className="about__personas-grid grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-4">
          {erpifyOverview.personas.map((persona) => (
            <article
              key={persona.name}
              className="about__persona bg-card border-border flex flex-col gap-1.5 rounded-xl border p-4 shadow-sm"
            >
              <h3 className="text-foreground text-sm font-semibold tracking-tight">
                {persona.name}
              </h3>
              <p className="text-muted-foreground text-xs leading-relaxed">{persona.focus}</p>
            </article>
          ))}
        </div>
      </section>

      <section className="about__contexts flex flex-col gap-8">
        <div className="flex flex-col gap-1">
          <h2 className="text-foreground text-lg font-semibold tracking-tight">Bounded contexts</h2>
          <p className="text-muted-foreground max-w-3xl text-sm leading-relaxed">
            Cada capacidad se modela como un contexto con lenguaje del dominio (no abstracciones
            genéricas tipo &laquo;CRM&raquo;). Los contextos no comparten entidades: se integran por
            eventos. Modelo completo en{" "}
            <code className="text-foreground">docs/bounded-contexts.md</code>.
          </p>
        </div>
        {AREA_ORDER.map((area) => (
          <AreaSection key={area} area={area} />
        ))}
      </section>

      <section className="about__event-catalog flex flex-col gap-4">
        <div className="flex flex-col gap-1">
          <h2 className="text-foreground flex items-center gap-2 text-lg font-semibold tracking-tight">
            <Radio className="text-muted-foreground size-4.5" aria-hidden="true" />
            Catálogo de eventos de integración
          </h2>
          <p className="text-muted-foreground max-w-3xl text-sm leading-relaxed">
            El contrato público por el que los contextos se hablan. Cada consumidor traduce el
            evento ajeno a su propio modelo mediante un Anti-Corruption Layer.
          </p>
        </div>
        <div className="about__event-table border-border overflow-x-auto rounded-xl border">
          <table className="w-full min-w-160 border-collapse text-left text-xs">
            <thead className="bg-muted/50 text-muted-foreground">
              <tr>
                <th className="px-3 py-2 font-semibold">Evento</th>
                <th className="px-3 py-2 font-semibold">Emite</th>
                <th className="px-3 py-2 font-semibold">Payload (clave)</th>
                <th className="px-3 py-2 font-semibold">Consumidores</th>
              </tr>
            </thead>
            <tbody>
              {integrationEvents.map((event) => (
                <tr key={event.name} className="border-border border-t">
                  <td className="text-foreground px-3 py-2 font-mono">{event.name}</td>
                  <td className="text-muted-foreground px-3 py-2">{event.emitter}</td>
                  <td className="text-muted-foreground px-3 py-2 font-mono">{event.payload}</td>
                  <td className="text-muted-foreground px-3 py-2">{event.consumers}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
        <p className="text-muted-foreground text-2xs">
          {domainContexts.length} bounded contexts · {integrationEvents.length} eventos de
          integración en el catálogo.
        </p>
      </section>

      <section className="about__references flex flex-col gap-3">
        <h2 className="text-foreground text-lg font-semibold tracking-tight">Referencias</h2>
        <ul className="about__references-list flex flex-col gap-2">
          {REFERENCES.map((reference) => (
            <li key={reference.href}>
              <Link
                href={reference.href}
                className="about__reference group border-border bg-card hover:bg-muted/50 flex items-center justify-between gap-3 rounded-lg border px-3 py-2"
                title={reference.label}
              >
                <span className="flex flex-col">
                  <span className="text-foreground text-sm font-medium">{reference.label}</span>
                  <span className="text-muted-foreground text-xs">{reference.description}</span>
                </span>
                <ArrowUpRight
                  className="text-muted-foreground size-4 flex-none"
                  aria-hidden="true"
                />
              </Link>
            </li>
          ))}
        </ul>
      </section>
    </div>
  );
}
