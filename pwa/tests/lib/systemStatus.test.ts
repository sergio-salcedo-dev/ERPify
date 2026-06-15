import { describe, expect, it } from "vitest";
import {
  SystemStatus,
  aggregateSystemStatus,
  componentStatusLabel,
  deriveSystemStatus,
  systemHeadline,
} from "@/lib/systemStatus";

const okResult = { status: "ok", datetime: "2026-06-02T10:00:00+02:00" };

describe("deriveSystemStatus", () => {
  it("reports CHECKING while a check is in flight", () => {
    expect(deriveSystemStatus({ checking: true, failed: false, result: null })).toEqual({
      status: SystemStatus.CHECKING,
      datetime: null,
    });
  });

  it("reports CHECKING even when a stale result is present (re-check in flight)", () => {
    expect(deriveSystemStatus({ checking: true, failed: false, result: okResult })).toEqual({
      status: SystemStatus.CHECKING,
      datetime: null,
    });
  });

  it("prioritizes CHECKING over a prior failure (re-check after error)", () => {
    expect(deriveSystemStatus({ checking: true, failed: true, result: null })).toEqual({
      status: SystemStatus.CHECKING,
      datetime: null,
    });
  });

  it("reports OPERATIONAL for an ok result and exposes the server datetime", () => {
    expect(deriveSystemStatus({ checking: false, failed: false, result: okResult })).toEqual({
      status: SystemStatus.OPERATIONAL,
      datetime: "2026-06-02T10:00:00+02:00",
    });
  });

  it("reports DEGRADED when the result status is not 'ok'", () => {
    const degraded = { status: "degraded", datetime: "2026-06-02T10:00:00+02:00" };
    expect(deriveSystemStatus({ checking: false, failed: false, result: degraded }).status).toBe(
      SystemStatus.DEGRADED,
    );
  });

  it("reports DISRUPTED when the check failed", () => {
    expect(deriveSystemStatus({ checking: false, failed: true, result: null })).toEqual({
      status: SystemStatus.DISRUPTED,
      datetime: null,
    });
  });

  it("reports DISRUPTED when there is no result and no failure flag", () => {
    expect(deriveSystemStatus({ checking: false, failed: false, result: null }).status).toBe(
      SystemStatus.DISRUPTED,
    );
  });
});

describe("aggregateSystemStatus", () => {
  const operational = { status: SystemStatus.OPERATIONAL, datetime: "2026-06-02T10:00:00+02:00" };
  const laterOperational = {
    status: SystemStatus.OPERATIONAL,
    datetime: "2026-06-02T11:00:00+02:00",
  };

  it("reports CHECKING when there are no component views", () => {
    expect(aggregateSystemStatus([])).toEqual({ status: SystemStatus.CHECKING, datetime: null });
  });

  it("stays OPERATIONAL when every component is operational and keeps the latest datetime", () => {
    expect(aggregateSystemStatus([operational, laterOperational])).toEqual({
      status: SystemStatus.OPERATIONAL,
      datetime: "2026-06-02T11:00:00+02:00",
    });
  });

  it("degrades when one component is degraded while another is operational", () => {
    expect(
      aggregateSystemStatus([operational, { status: SystemStatus.DEGRADED, datetime: null }])
        .status,
    ).toBe(SystemStatus.DEGRADED);
  });

  it("disruption wins over degradation", () => {
    expect(
      aggregateSystemStatus([
        { status: SystemStatus.DEGRADED, datetime: null },
        { status: SystemStatus.DISRUPTED, datetime: null },
      ]).status,
    ).toBe(SystemStatus.DISRUPTED);
  });

  it("picks the chronologically-latest datetime across mixed UTC offsets", () => {
    // 11:00+02:00 is 09:00Z; 10:00+00:00 is 10:00Z — the later instant despite
    // sorting earlier lexicographically.
    expect(
      aggregateSystemStatus([
        { status: SystemStatus.OPERATIONAL, datetime: "2026-06-02T11:00:00+02:00" },
        { status: SystemStatus.OPERATIONAL, datetime: "2026-06-02T10:00:00+00:00" },
      ]).datetime,
    ).toBe("2026-06-02T10:00:00+00:00");
  });
});

describe("systemHeadline", () => {
  it("maps each status to its banner headline", () => {
    expect(systemHeadline(SystemStatus.OPERATIONAL)).toBe("All Systems Operational");
    expect(systemHeadline(SystemStatus.DEGRADED)).toBe("Partial Service Disruption");
    expect(systemHeadline(SystemStatus.DISRUPTED)).toBe("Service Disruption");
    expect(systemHeadline(SystemStatus.CHECKING)).toBe("Checking system status…");
  });
});

describe("componentStatusLabel", () => {
  it("maps each status to its pill label", () => {
    expect(componentStatusLabel(SystemStatus.OPERATIONAL)).toBe("Operational");
    expect(componentStatusLabel(SystemStatus.DEGRADED)).toBe("Degraded");
    expect(componentStatusLabel(SystemStatus.DISRUPTED)).toBe("Disrupted");
    expect(componentStatusLabel(SystemStatus.CHECKING)).toBe("Checking…");
  });
});
