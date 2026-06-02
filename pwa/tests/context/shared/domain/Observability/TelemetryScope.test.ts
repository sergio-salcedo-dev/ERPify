import { describe, expect, it } from "vitest";
import { RealtimeTransport } from "@/context/shared/domain/RealTime/RealtimeTransport";
import {
  TelemetrySurface,
  apiScope,
  realtimeScope,
  telemetryScope,
} from "@/context/shared/domain/Observability/TelemetryScope";

/**
 * Locks the `<surface>:<detail>` telemetry-scope convention and its two builders.
 * The surface is a curated closed set; the detail is open — an entity name for a
 * per-entity feed (`realtime:bank`) or a transport for a transport adapter
 * (`realtime:mercure`). New entities/transports must keep producing this shape so
 * diagnostics stay groupable as the realtime surface grows (Mercure).
 */
describe("telemetryScope", () => {
  it("joins a surface and detail with the ':' convention", () => {
    expect(telemetryScope(TelemetrySurface.ERROR, "segment")).toBe("error:segment");
    expect(telemetryScope(TelemetrySurface.ERROR, "root")).toBe("error:root");
    expect(telemetryScope(TelemetrySurface.REALTIME, "bank")).toBe("realtime:bank");
    expect(telemetryScope(TelemetrySurface.API, "frontoffice-health")).toBe(
      "api:frontoffice-health",
    );
  });
});

describe("realtimeScope", () => {
  it("prefixes the realtime surface for a per-entity feed", () => {
    expect(realtimeScope("bank")).toBe("realtime:bank");
    expect(realtimeScope("invoice")).toBe("realtime:invoice");
  });

  it("tags a transport adapter by its transport name", () => {
    expect(realtimeScope(RealtimeTransport.MERCURE)).toBe("realtime:mercure");
  });
});

describe("apiScope", () => {
  it("prefixes the api surface for a backend call by endpoint / use-case", () => {
    expect(apiScope("frontoffice-health")).toBe("api:frontoffice-health");
    expect(apiScope("bank-search")).toBe("api:bank-search");
  });
});
