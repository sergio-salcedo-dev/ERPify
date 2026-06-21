import { describe, expect, it } from "vitest";
import { container } from "@/context/shared/infrastructure/DependencyInjection/Container";
import { EventTargetDebugTokenObserver } from "@/context/shared/DebugToken/infrastructure/EventTargetDebugTokenObserver";
import type { DebugTokenObserver } from "@/context/shared/DebugToken/domain/DebugTokenObserver";

describe("DebugTokenObserver binding", () => {
  it("resolves the live EventTarget adapter outside production (test env)", () => {
    const observer = container.get<DebugTokenObserver>("DebugTokenObserver");
    expect(observer).toBeInstanceOf(EventTargetDebugTokenObserver);
  });

  it("resolves a singleton", () => {
    const a = container.get<DebugTokenObserver>("DebugTokenObserver");
    const b = container.get<DebugTokenObserver>("DebugTokenObserver");
    expect(a).toBe(b);
  });
});
