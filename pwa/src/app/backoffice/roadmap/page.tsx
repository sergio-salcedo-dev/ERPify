import type { Metadata } from "next";
import { Check } from "lucide-react";
import { StatusBadge, type StatusBadgeVariant } from "@/components/erpify";
import { cn } from "@/context/shared/styling/infrastructure/classNames";
import {
  roadmapPhases,
  computeRoadmapProgress,
  moduleStatus,
  submoduleStatus,
  type RoadmapComplexity,
  type RoadmapModule,
  type RoadmapPhase,
  type RoadmapPriority,
  type RoadmapStatus,
} from "./_lib/roadmap";

export const metadata: Metadata = {
  title: "Product Roadmap",
  description: "Backlog vivo de producto + ingeniería de ERPify, por fases y módulos.",
};

const STATUS_LABEL: Record<RoadmapStatus, string> = {
  done: "Hecho",
  "in-progress": "En curso",
  planned: "Pendiente",
};

const STATUS_VARIANT: Record<RoadmapStatus, StatusBadgeVariant> = {
  done: "success",
  "in-progress": "warning",
  planned: "neutral",
};

const STATUS_DOT: Record<RoadmapStatus, string> = {
  done: "bg-status-dot-success",
  "in-progress": "bg-status-dot-warning",
  planned: "bg-text-subtle",
};

const PRIORITY_LABEL: Record<RoadmapPriority, string> = {
  critical: "Crítica",
  high: "Alta",
  medium: "Media",
  low: "Baja",
};

const COMPLEXITY_LABEL: Record<RoadmapComplexity, string> = {
  low: "Baja",
  medium: "Media",
  high: "Alta",
};

function Chip({ children }: Readonly<{ children: React.ReactNode }>) {
  return (
    <span className="roadmap__chip border-border text-muted-foreground inline-flex items-center gap-1 rounded-md border px-1.5 py-0.5 text-2xs font-medium">
      {children}
    </span>
  );
}

function ProgressBar({
  percent,
  label,
  testId,
}: Readonly<{ percent: number; label: string; testId?: string }>) {
  return (
    <progress
      className="roadmap__progress-track bg-muted h-1.5 w-full appearance-none overflow-hidden rounded-full [&::-moz-progress-bar]:bg-status-dot-success [&::-webkit-progress-bar]:bg-muted [&::-webkit-progress-value]:bg-status-dot-success"
      max={100}
      value={percent}
      aria-label={label}
      data-testid={testId}
    />
  );
}

function ModuleCard({ module }: Readonly<{ module: RoadmapModule }>) {
  const Icon = module.icon;
  const status = moduleStatus(module);
  return (
    <article
      className="roadmap__module bg-card border-border flex flex-col gap-3 rounded-xl border p-4 shadow-sm"
      data-testid={`roadmap__module-${module.code}`}
    >
      <header className="roadmap__module-head flex items-start gap-3">
        <span className="roadmap__module-icon bg-muted text-muted-foreground flex size-9 flex-none items-center justify-center rounded-lg">
          <Icon className="size-4.5" aria-hidden="true" />
        </span>
        <div className="roadmap__module-title min-w-0 flex-1">
          <p className="text-muted-foreground text-2xs font-semibold tracking-wider">
            {module.code}
          </p>
          <h3 className="text-foreground text-sm font-semibold tracking-tight">{module.name}</h3>
        </div>
        <StatusBadge variant={STATUS_VARIANT[status]} label={STATUS_LABEL[status]} />
      </header>

      <p className="roadmap__module-objective text-muted-foreground text-xs leading-relaxed">
        {module.objective}
      </p>

      <div className="roadmap__needs bg-muted/40 border-border flex flex-col gap-1.5 rounded-lg border p-3">
        <p className="roadmap__needs-title text-foreground text-2xs font-semibold tracking-wider uppercase">
          Necesidades que cubre
        </p>
        <ul className="roadmap__needs-list flex flex-col gap-1">
          {module.userNeeds.map((need) => (
            <li key={need} className="roadmap__need text-foreground flex items-start gap-2 text-xs">
              <Check
                className="text-status-dot-success mt-0.5 size-3.5 flex-none"
                aria-hidden="true"
              />
              <span className="min-w-0 flex-1">{need}</span>
            </li>
          ))}
        </ul>
      </div>

      <div className="roadmap__module-meta flex flex-wrap items-center gap-1.5">
        <Chip>Prioridad: {PRIORITY_LABEL[module.priority]}</Chip>
        <Chip>Complejidad: {COMPLEXITY_LABEL[module.complexity]}</Chip>
        {module.boundedContext ? <Chip>Contexto: {module.boundedContext}</Chip> : null}
        {module.dependsOn?.length ? <Chip>Depende de: {module.dependsOn.join(", ")}</Chip> : null}
      </div>

      <ul className="roadmap__submodules flex flex-col gap-1.5 pt-1">
        {module.submodules.map((submodule) => {
          const status = submoduleStatus(submodule);
          return (
            <li key={submodule.name} className="roadmap__submodule flex items-start gap-2 text-xs">
              <span
                className={cn(
                  "roadmap__submodule-dot mt-1 size-1.5 flex-none rounded-full",
                  STATUS_DOT[status],
                )}
                aria-hidden="true"
              />
              <span className="min-w-0 flex-1">
                <span
                  className={cn(
                    "text-foreground",
                    status === "done" && "text-muted-foreground line-through",
                  )}
                >
                  {submodule.name}
                </span>
                {submodule.note ? (
                  <span className="text-muted-foreground"> — {submodule.note}</span>
                ) : null}
                <span className="sr-only"> ({STATUS_LABEL[status]})</span>
              </span>
            </li>
          );
        })}
      </ul>
    </article>
  );
}

function PhaseSection({ phase }: Readonly<{ phase: RoadmapPhase }>) {
  const progress = computeRoadmapProgress([phase]);
  return (
    <section
      className="roadmap__phase flex flex-col gap-4"
      data-testid={`roadmap__phase-${phase.code}`}
    >
      <header className="roadmap__phase-head flex flex-col gap-2">
        <div className="flex items-baseline gap-2">
          <span className="text-muted-foreground text-sm font-semibold tracking-wider">
            Fase {phase.code}
          </span>
          <h2 className="text-foreground text-lg font-semibold tracking-tight">{phase.label}</h2>
        </div>
        <p className="text-muted-foreground text-sm leading-relaxed">{phase.summary}</p>
        <div className="roadmap__phase-progress flex items-center gap-3">
          <ProgressBar
            percent={progress.donePercent}
            label={`Progreso fase ${phase.code}: ${phase.label}`}
          />
          <span className="text-muted-foreground text-2xs font-medium whitespace-nowrap">
            {progress.done}/{progress.total} ({progress.donePercent}%)
          </span>
        </div>
      </header>

      <div className="roadmap__module-grid grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
        {phase.modules.map((module) => (
          <ModuleCard key={module.code} module={module} />
        ))}
      </div>
    </section>
  );
}

export default function RoadmapPage() {
  const overall = computeRoadmapProgress();
  return (
    <div className="roadmap flex flex-col gap-8">
      <header className="roadmap__head flex flex-col gap-4">
        <div className="flex flex-col gap-1">
          <h1
            className="text-foreground text-2xl font-semibold tracking-tight"
            data-testid="roadmap__title"
          >
            Product Roadmap
          </h1>
          <p className="text-muted-foreground max-w-3xl text-sm leading-relaxed">
            Backlog vivo de producto + ingeniería, por fases y módulos. El estado se marca tarea a
            tarea, al cerrar y verificar cada pieza:
          </p>
          <ul className="roadmap__status-legend text-muted-foreground max-w-3xl text-sm leading-relaxed">
            <li>
              <span className="text-foreground font-medium">Hecho</span> = entregado y verificado
            </li>
            <li>
              <span className="text-foreground font-medium">En curso</span> = se está trabajando
            </li>
            <li>
              <span className="text-foreground font-medium">Pendiente</span> = sin empezar
            </li>
          </ul>
          <p className="text-muted-foreground max-w-3xl text-sm leading-relaxed">
            La complejidad mide la facilidad de implementación, no la importancia:
          </p>
          <ul className="roadmap__complexity-legend text-muted-foreground max-w-3xl text-sm leading-relaxed">
            <li>
              <span className="text-foreground font-medium">Baja</span> = CRUD sin relaciones en DB
              (calcar la plantilla Banks)
            </li>
            <li>
              <span className="text-foreground font-medium">Media</span> = relaciones en DB + reglas
              de negocio
            </li>
            <li>
              <span className="text-foreground font-medium">Alta</span> = motores, integraciones o
              UI interactiva
            </li>
          </ul>
          <p className="text-muted-foreground max-w-3xl text-sm leading-relaxed">
            Para elegir la siguiente tarea: prioridad alta + complejidad baja primero. Fuente de
            verdad: <code className="text-foreground">roadmap.ts</code>.
          </p>
        </div>

        <div
          className="roadmap__overall bg-card border-border flex flex-col gap-2 rounded-xl border p-4 shadow-sm"
          data-testid="roadmap__overall"
        >
          <div className="flex flex-wrap items-center gap-3">
            <span className="text-foreground text-sm font-semibold">Progreso global</span>
            <StatusBadge variant="success" label={`${overall.done} hechos`} />
            <StatusBadge variant="warning" label={`${overall.inProgress} en curso`} />
            <StatusBadge variant="neutral" label={`${overall.planned} pendientes`} />
            <span className="text-muted-foreground text-xs">de {overall.total} submódulos</span>
          </div>
          <div className="flex items-center gap-3">
            <ProgressBar
              percent={overall.donePercent}
              label="Progreso global"
              testId="roadmap__overall-progress"
            />
            <span className="text-muted-foreground text-2xs font-medium whitespace-nowrap">
              {overall.donePercent}%
            </span>
          </div>
        </div>
      </header>

      <div className="roadmap__phases flex flex-col gap-10">
        {roadmapPhases.map((phase) => (
          <PhaseSection key={phase.code} phase={phase} />
        ))}
      </div>
    </div>
  );
}
