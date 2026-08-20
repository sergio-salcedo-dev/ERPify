/** Aggregate UI status for the status pages. */
export const SystemStatus = {
  CHECKING: "checking",
  OPERATIONAL: "operational",
  DEGRADED: "degraded",
  DISRUPTED: "disrupted",
} as const;
export type SystemStatus = (typeof SystemStatus)[keyof typeof SystemStatus];

/** Minimal health snapshot the derivation needs — any `HealthCheck` satisfies it. */
export interface HealthSnapshot {
  status: string;
  datetime: string;
}

export interface SystemStatusInput {
  /** A health check is in flight. */
  checking: boolean;
  /** The last check threw (transport / HTTP error). */
  failed: boolean;
  /** The last successful health snapshot, or null when none yet / failed. */
  result: HealthSnapshot | null;
}

export interface SystemStatusView {
  status: SystemStatus;
  /** Server-reported ISO datetime, present only on the success path. */
  datetime: string | null;
}

const HEALTHY = "ok";

export function deriveSystemStatus({
  checking,
  failed,
  result,
}: SystemStatusInput): SystemStatusView {
  if (checking) return { status: SystemStatus.CHECKING, datetime: null };
  if (failed || result === null) return { status: SystemStatus.DISRUPTED, datetime: null };
  return {
    status: result.status === HEALTHY ? SystemStatus.OPERATIONAL : SystemStatus.DEGRADED,
    datetime: result.datetime,
  };
}

/**
 * Worst-wins severity used to fold several component views into one banner:
 * any disruption dominates a degradation, which dominates an in-flight check,
 * which dominates the all-clear. The aggregate `datetime` is the most recent
 * server-reported timestamp among the components that have one.
 */
const SEVERITY: Record<SystemStatus, number> = {
  [SystemStatus.OPERATIONAL]: 0,
  [SystemStatus.CHECKING]: 1,
  [SystemStatus.DEGRADED]: 2,
  [SystemStatus.DISRUPTED]: 3,
};

export function aggregateSystemStatus(views: readonly SystemStatusView[]): SystemStatusView {
  if (views.length === 0) return { status: SystemStatus.CHECKING, datetime: null };

  const status = views.reduce((worst, view) =>
    SEVERITY[view.status] > SEVERITY[worst.status] ? view : worst,
  ).status;

  const datetime = views
    .map((view) => view.datetime)
    .filter((value): value is string => value !== null)
    .sort((a, b) => Date.parse(a) - Date.parse(b))
    .at(-1);

  return { status, datetime: datetime ?? null };
}

const HEADLINE: Record<SystemStatus, string> = {
  [SystemStatus.OPERATIONAL]: "All Systems Operational",
  [SystemStatus.DEGRADED]: "Partial Service Disruption",
  [SystemStatus.DISRUPTED]: "Service Disruption",
  [SystemStatus.CHECKING]: "Checking system status…",
};

/** Headline shown in the aggregate banner. */
export function systemHeadline(status: SystemStatus): string {
  return HEADLINE[status];
}

const COMPONENT_LABEL: Record<SystemStatus, string> = {
  [SystemStatus.OPERATIONAL]: "Operational",
  [SystemStatus.DEGRADED]: "Degraded",
  [SystemStatus.DISRUPTED]: "Disrupted",
  [SystemStatus.CHECKING]: "Checking…",
};

/** Short label shown in a component's status pill. */
export function componentStatusLabel(status: SystemStatus): string {
  return COMPONENT_LABEL[status];
}

export interface NamedComponentStatus {
  name: string;
  status: SystemStatus;
}

/**
 * Every component named with its status, for a live region whose headline is the worst of them.
 *
 * `aggregateSystemStatus` is worst-wins, so a component moving under the maximum changes no
 * headline and announces nothing — visible in its row, absent from the audio. This is the line
 * that carries it. It lives here, beside the other two label functions, so the case it exists
 * for can be asserted without rendering a page.
 */
export function componentRollCall(components: readonly NamedComponentStatus[]): string {
  return components
    .map(({ name, status }) => `${name}: ${componentStatusLabel(status)}`)
    .join(". ");
}
