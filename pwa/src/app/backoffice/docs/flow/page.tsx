import type { Metadata } from "next";
import { ArrowRight } from "lucide-react";
import { cn } from "@/components/cn";
import { flows, type Flow, type FlowDiagramNode, type FlowTone } from "./_lib/flows";

export const metadata: Metadata = {
  title: "How ERPify Works",
  description: "ERPify's workflows explained in plain language, for everyone.",
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
            <li key={step.id} className="flow__map-node flex items-start gap-1.5">
              <a
                href={`#${flow.id}-step-${index + 1}`}
                className="hover:bg-muted/60 focus-visible:ring-ring flex w-21 flex-col items-center gap-1 rounded-lg p-1.5 text-center focus-visible:ring-2 focus-visible:outline-none"
                title={`Ir al paso ${index + 1}: ${step.title}`}
                data-testid={`docs-flow__map-link-${flow.id}-${step.id}`}
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

const NODE_W = 158;
const NODE_H = 56;
const GAP_X = 36;
const GAP_Y = 46;
const CHART_PAD = 10;

function nodeRect(node: FlowDiagramNode) {
  const x = CHART_PAD + node.col * (NODE_W + GAP_X);
  const y = CHART_PAD + node.row * (NODE_H + GAP_Y);
  return { x, y, cx: x + NODE_W / 2, cy: y + NODE_H / 2 };
}

function edgeGeometry(from: FlowDiagramNode, to: FlowDiagramNode) {
  const a = nodeRect(from);
  const b = nodeRect(to);
  if (from.row === to.row) {
    const [sx, ex] = a.x < b.x ? [a.x + NODE_W, b.x] : [a.x, b.x + NODE_W];
    return { path: `M ${sx} ${a.cy} L ${ex} ${b.cy}`, labelX: (sx + ex) / 2, labelY: a.cy - 7 };
  }
  if (from.col === to.col) {
    return {
      path: `M ${a.cx} ${a.y + NODE_H} L ${b.cx} ${b.y}`,
      labelX: a.cx + 8,
      labelY: (a.y + NODE_H + b.y) / 2 + 3,
    };
  }
  const midY = b.y - GAP_Y / 2;
  return {
    path: `M ${a.cx} ${a.y + NODE_H} L ${a.cx} ${midY} L ${b.cx} ${midY} L ${b.cx} ${b.y}`,
    labelX: (a.cx + b.cx) / 2,
    labelY: midY - 6,
  };
}

function FlowChart({ flow }: Readonly<{ flow: Flow }>) {
  const diagram = flow.diagram;
  if (!diagram) return null;
  const byId = new Map(diagram.nodes.map((node) => [node.id, node]));
  const cols = Math.max(...diagram.nodes.map((node) => node.col)) + 1;
  const rows = Math.max(...diagram.nodes.map((node) => node.row)) + 1;
  const width = CHART_PAD * 2 + cols * NODE_W + (cols - 1) * GAP_X;
  const height = CHART_PAD * 2 + rows * NODE_H + (rows - 1) * GAP_Y;
  const arrowId = `${flow.id}-arrow`;
  return (
    <figure
      className="flow__chart border-border bg-card overflow-x-auto rounded-xl border p-4 shadow-sm"
      data-testid={`docs-flow__chart-${flow.id}`}
    >
      <svg
        viewBox={`0 0 ${width} ${height}`}
        role="img"
        aria-label={diagram.title}
        className="w-full"
        style={{ minWidth: `${Math.round(width * 0.85)}px` }}
      >
        <g className="text-muted-foreground">
          <defs>
            <marker
              id={arrowId}
              viewBox="0 0 7 7"
              refX="6"
              refY="3.5"
              markerWidth="7"
              markerHeight="7"
              orient="auto-start-reverse"
            >
              <path d="M0,0 L7,3.5 L0,7 Z" fill="currentColor" />
            </marker>
          </defs>
          {diagram.edges.map((edge) => {
            const from = byId.get(edge.from);
            const to = byId.get(edge.to);
            if (!from || !to) return null;
            const { path, labelX, labelY } = edgeGeometry(from, to);
            return (
              <g key={`${edge.from}-${edge.to}`}>
                <path
                  d={path}
                  fill="none"
                  stroke="currentColor"
                  strokeWidth="1.2"
                  strokeDasharray={edge.async ? "5 4" : undefined}
                  markerEnd={`url(#${arrowId})`}
                  markerStart={edge.bidirectional ? `url(#${arrowId})` : undefined}
                />
                {edge.label ? (
                  <text x={labelX} y={labelY} textAnchor="middle" fontSize="10" fill="currentColor">
                    {edge.label}
                  </text>
                ) : null}
              </g>
            );
          })}
        </g>
        {diagram.nodes.map((node) => {
          const { x, y, cx, cy } = nodeRect(node);
          return (
            <g key={node.id}>
              <rect
                x={x}
                y={y}
                width={NODE_W}
                height={NODE_H}
                rx="10"
                className="fill-card stroke-border"
                strokeWidth="1"
                strokeDasharray={node.async ? "5 4" : undefined}
              />
              <text
                x={cx}
                y={node.sublabel ? cy - 3 : cy + 4}
                textAnchor="middle"
                fontSize="12"
                fontWeight="600"
                className="fill-foreground"
              >
                {node.label}
              </text>
              {node.sublabel ? (
                <text
                  x={cx}
                  y={cy + 13}
                  textAnchor="middle"
                  fontSize="10"
                  className="fill-muted-foreground"
                >
                  {node.sublabel}
                </text>
              ) : null}
            </g>
          );
        })}
      </svg>
      <figcaption className="flow__chart-legend text-muted-foreground mt-3 flex flex-wrap items-center gap-x-5 gap-y-1 text-xs">
        <span className="flex items-center gap-2">
          <span className="border-muted-foreground w-5 border-t" aria-hidden="true" />
          Your request&apos;s path (there and back)
        </span>
        <span className="flex items-center gap-2">
          <span className="border-muted-foreground w-5 border-t border-dashed" aria-hidden="true" />
          In the background (asynchronous)
        </span>
      </figcaption>
    </figure>
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

      <FlowChart flow={flow} />

      <ol className="flow__steps flex flex-col">
        {flow.steps.map((step, index) => {
          const Icon = step.icon;
          const isLast = index === flow.steps.length - 1;
          return (
            <li
              key={step.id}
              id={`${flow.id}-step-${index + 1}`}
              className="flow__step flex scroll-mt-24 gap-4"
              data-testid={`docs-flow__step-${flow.id}-${step.id}`}
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
          How ERPify works
        </h1>
        <p className="text-muted-foreground max-w-3xl text-sm leading-relaxed">
          How ERPify is built on the inside, with the real pieces under their real names, explained
          so anyone can follow it. Every step carries a &laquo;behind the scenes&raquo; note with
          the exact technology, in case you want to go deeper.
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
