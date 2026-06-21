import { afterEach, describe, expect, it, vi } from "vitest";
import { ConsoleTelemetry } from "@/context/shared/Observability/infrastructure/ConsoleTelemetry";

afterEach(() => {
  vi.unstubAllEnvs();
  vi.restoreAllMocks();
});

describe("ConsoleTelemetry", () => {
  it("emits warn with scope + cause in dev", () => {
    vi.stubEnv("NEXT_PUBLIC_APP_ENV", "dev");
    const spy = vi.spyOn(console, "warn").mockImplementation(() => {});
    const cause = new Error("x");
    new ConsoleTelemetry().warn("boom", { scope: "realtime:bank", cause });
    expect(spy).toHaveBeenCalledWith("[realtime:bank] boom", cause);
  });

  it("emits in staging", () => {
    vi.stubEnv("NEXT_PUBLIC_APP_ENV", "staging");
    const spy = vi.spyOn(console, "error").mockImplementation(() => {});
    new ConsoleTelemetry().error("nope", { scope: "s" });
    expect(spy).toHaveBeenCalledWith("[s] nope");
  });

  it("passes a null cause through as the second arg (null !== undefined) in dev", () => {
    vi.stubEnv("NEXT_PUBLIC_APP_ENV", "dev");
    const spy = vi.spyOn(console, "warn").mockImplementation(() => {});
    new ConsoleTelemetry().warn("nullish", { scope: "n", cause: null });
    expect(spy).toHaveBeenCalledWith("[n] nullish", null);
  });

  it("is silent in prod", () => {
    vi.stubEnv("NEXT_PUBLIC_APP_ENV", "prod");
    const warn = vi.spyOn(console, "warn").mockImplementation(() => {});
    const error = vi.spyOn(console, "error").mockImplementation(() => {});
    const t = new ConsoleTelemetry();
    t.warn("a", { scope: "s" });
    t.error("b", { scope: "s" });
    expect(warn).not.toHaveBeenCalled();
    expect(error).not.toHaveBeenCalled();
  });

  it("defaults unknown env to silent (prod)", () => {
    vi.stubEnv("NEXT_PUBLIC_APP_ENV", "");
    const warn = vi.spyOn(console, "warn").mockImplementation(() => {});
    new ConsoleTelemetry().warn("a");
    expect(warn).not.toHaveBeenCalled();
  });

  it("omits the cause arg when absent and falls back to a default scope", () => {
    vi.stubEnv("NEXT_PUBLIC_APP_ENV", "dev");
    const spy = vi.spyOn(console, "warn").mockImplementation(() => {});
    new ConsoleTelemetry().warn("no cause");
    expect(spy).toHaveBeenCalledWith("[telemetry] no cause");
  });
});
