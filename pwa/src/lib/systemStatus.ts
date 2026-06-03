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

/** Headline shown in the aggregate banner. */
export function systemHeadline(status: SystemStatus): string {
  switch (status) {
    case SystemStatus.OPERATIONAL:
      return "All Systems Operational";
    case SystemStatus.DEGRADED:
      return "Partial Service Disruption";
    case SystemStatus.DISRUPTED:
      return "Service Disruption";
    default:
      return "Checking system status…";
  }
}

/** Short label shown in a component's status pill. */
export function componentStatusLabel(status: SystemStatus): string {
  switch (status) {
    case SystemStatus.OPERATIONAL:
      return "Operational";
    case SystemStatus.DEGRADED:
      return "Degraded";
    case SystemStatus.DISRUPTED:
      return "Disrupted";
    default:
      return "Checking…";
  }
}
