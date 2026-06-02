import type { HealthCheck } from "@/context/frontoffice/health/domain/HealthCheck";

/** Aggregate UI status for the public status page (Atlassian-style). */
export const SystemStatus = {
  CHECKING: "checking",
  OPERATIONAL: "operational",
  DEGRADED: "degraded",
  DISRUPTED: "disrupted",
} as const;
export type SystemStatus = (typeof SystemStatus)[keyof typeof SystemStatus];

export interface SystemStatusInput {
  /** A health check is in flight. */
  checking: boolean;
  /** The last check threw (transport / HTTP error). */
  failed: boolean;
  /** The last successful health check, or null when none yet / failed. */
  result: HealthCheck | null;
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
