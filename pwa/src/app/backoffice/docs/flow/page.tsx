import type { Metadata } from "next";
import { ArrowRight } from "lucide-react";
import { cn } from "@/lib/utils";
import { flows, type Flow, type FlowTone } from "./_lib/flows";

export const metadata: Metadata = {
  title: "Cómo funciona ERPify",
  description: "Flujos de trabajo de ERPify explicados de forma sencilla, para todo el mundo.",
};

const TONE_ICON: Record<FlowTone, string> = {
  brand: "bg-brand/10 text-brand",
  success: "bg-success/10 text-success",
  warning: "bg-warning/10 text-warning-strong",
  accent: "bg-accent text-accent-foreground",
};

function FlowMap({ flow }: Readonly<{ flow: Flow }>) {
  return (
    <nav
      className="flow__map border-border bg-card overflow-x-auto rounded-xl border p-4 shadow-sm"
      aria-label={`Mapa del flujo: ${flow.title}`}
      data-testid={`docs-flow__map-${flow.id}`}
    >
      <ol className="flow__map-track flex items-start gap-1.5">
        {flow.steps.map((step, index) => {
          const Icon = step.icon;
          const isLast = index === flow.steps.length - 1;
          return (
            <li key={step.title} className="flow__map-node flex items-start gap-1.5">
              <a
                href={`#${flow.id}-step-${index + 1}`}
                className="hover:bg-muted/60 focus-visible:ring-ring flex w-21 flex-col items-center gap-1 rounded-lg p-1.5 text-center focus-visible:ring-2 focus-visible:outline-none"
                title={`Ir al paso ${index + 1}: ${step.title}`}
              >
                <span
                  className={cn(
                    "flow__map-icon flex size-9 flex-none items-center justify-center rounded-full",
                    TONE_ICON[step.tone],
                  )}
                >
                  <Icon className="size-4" aria-hidden="true" />
                </span>
                <span className="text-muted-foreground text-2xs font-semibold tracking-wider uppercase">
                  {index + 1}
                </span>
                <span className="text-foreground text-xs leading-snug">{step.title}</span>
              </a>
              {isLast ? null : (
                <ArrowRight
                  className="text-muted-foreground mt-4 size-4 flex-none"
                  aria-hidden="true"
                />
              )}
            </li>
          );
        })}
      </ol>
    </nav>
  );
}

function FlowJourney({ flow }: Readonly<{ flow: Flow }>) {
  return (
    <section className="flow flex flex-col gap-5" data-testid={`docs-flow__flow-${flow.id}`}>
      <header className="flow__head flex flex-col gap-1">
        <h2 className="text-foreground text-lg font-semibold tracking-tight">{flow.title}</h2>
        <p className="text-muted-foreground max-w-3xl text-sm leading-relaxed">{flow.intro}</p>
      </header>

      <FlowMap flow={flow} />

      <ol className="flow__steps flex flex-col">
        {flow.steps.map((step, index) => {
          const Icon = step.icon;
          const isLast = index === flow.steps.length - 1;
          return (
            <li
              key={step.title}
              id={`${flow.id}-step-${index + 1}`}
              className="flow__step flex scroll-mt-24 gap-4"
              data-testid={`docs-flow__step-${flow.id}-${index + 1}`}
            >
              <div className="flow__step-rail flex flex-col items-center">
                <span
                  className={cn(
                    "flow__step-icon flex size-11 flex-none items-center justify-center rounded-full",
                    TONE_ICON[step.tone],
                  )}
                >
                  <Icon className="size-5" aria-hidden="true" />
                </span>
                {isLast ? null : (
                  <span className="flow__step-line bg-border mt-2 w-px flex-1" aria-hidden="true" />
                )}
              </div>

              <div className={cn("flow__step-body min-w-0 flex-1", isLast ? "pb-0" : "pb-8")}>
                <p className="text-muted-foreground text-2xs font-semibold tracking-wider uppercase">
                  Paso {index + 1}
                </p>
                <h3 className="text-foreground text-base font-semibold tracking-tight">
                  {step.title}
                </h3>
                <p className="text-foreground mt-1 text-sm leading-relaxed">{step.plain}</p>
                {step.tech ? (
                  <p className="text-muted-foreground mt-1.5 text-xs leading-relaxed">
                    <span className="font-medium">Entre bastidores:</span> {step.tech}
                  </p>
                ) : null}
              </div>
            </li>
          );
        })}
      </ol>
    </section>
  );
}

export default function DocsFlowPage() {
  return (
    <div className="docs-flow flex flex-col gap-8">
      <header className="docs-flow__head flex flex-col gap-1">
        <h1
          className="text-foreground text-2xl font-semibold tracking-tight"
          data-testid="docs-flow__title"
        >
          Cómo funciona ERPify
        </h1>
        <p className="text-muted-foreground max-w-3xl text-sm leading-relaxed">
          Cómo está construido ERPify por dentro, con las piezas reales y sus nombres reales,
          explicado para que cualquiera pueda seguirlo. Cada paso lleva una nota «entre bastidores»
          con la tecnología exacta por si quieres profundizar.
        </p>
      </header>

      <div className="docs-flow__flows flex flex-col gap-10">
        {flows.map((flow) => (
          <FlowJourney key={flow.id} flow={flow} />
        ))}
      </div>
    </div>
  );
}
