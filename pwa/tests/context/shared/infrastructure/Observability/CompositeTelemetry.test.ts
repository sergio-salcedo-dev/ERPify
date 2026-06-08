import { describe, expect, it, vi } from "vitest";
import type { Telemetry } from "@/context/shared/domain/Observability/Telemetry";
import { CompositeTelemetry } from "@/context/shared/infrastructure/Observability/CompositeTelemetry";

function fakeSink(): Telemetry {
  return { warn: vi.fn(), error: vi.fn() };
}

describe("CompositeTelemetry", () => {
  it("fans warn and error out to every sink with the same args", () => {
    const a = fakeSink();
    const b = fakeSink();
    const composite = new CompositeTelemetry([a, b]);

    composite.warn("w", { scope: "s" });
    composite.error("e", { scope: "s" });

    expect(a.warn).toHaveBeenCalledWith("w", { scope: "s" });
    expect(b.warn).toHaveBeenCalledWith("w", { scope: "s" });
    expect(a.error).toHaveBeenCalledWith("e", { scope: "s" });
    expect(b.error).toHaveBeenCalledWith("e", { scope: "s" });
  });

  it("isolates a throwing sink so the others still receive the diagnostic", () => {
    const exploding: Telemetry = {
      warn: vi.fn(() => {
        throw new Error("sink down");
      }),
      error: vi.fn(),
    };
    const healthy = fakeSink();
    const composite = new CompositeTelemetry([exploding, healthy]);

    expect(() => composite.warn("w")).not.toThrow();
    expect(healthy.warn).toHaveBeenCalledWith("w", undefined);
  });
});
