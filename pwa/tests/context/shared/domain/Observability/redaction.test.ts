import { describe, expect, it } from "vitest";
import {
  REDACTION_DENYLIST,
  isDenylistedKey,
  scrubDeep,
} from "@/context/shared/domain/Observability/redaction";

describe("redaction denylist", () => {
  it("mirrors the API key set", () => {
    expect([...REDACTION_DENYLIST]).toEqual([
      "password",
      "token",
      "secret",
      "authorization",
      "cookie",
      "ssn",
      "iban",
    ]);
  });

  it("matches keys case-insensitively, exact (no substring)", () => {
    expect(isDenylistedKey("Password")).toBe(true);
    expect(isDenylistedKey("AUTHORIZATION")).toBe(true);
    expect(isDenylistedKey("token")).toBe(true);
    expect(isDenylistedKey("user_password")).toBe(false);
    expect(isDenylistedKey("name")).toBe(false);
  });
});

describe("scrubDeep", () => {
  it("strips denylisted keys at the top level, keeping the rest", () => {
    expect(scrubDeep({ name: "Acme", token: "abc", id: 1 })).toEqual({ name: "Acme", id: 1 });
  });

  it("strips recursively at every depth, including inside arrays", () => {
    const input = {
      user: { name: "x", password: "p", profile: { secret: "s", age: 3 } },
      items: [{ iban: "ES00", label: "ok" }],
    };
    expect(scrubDeep(input)).toEqual({
      user: { name: "x", profile: { age: 3 } },
      items: [{ label: "ok" }],
    });
  });

  it("passes primitives and null through untouched", () => {
    expect(scrubDeep("hello")).toBe("hello");
    expect(scrubDeep(42)).toBe(42);
    expect(scrubDeep(null)).toBeNull();
    expect(scrubDeep(undefined)).toBeUndefined();
  });

  it("is bounded against cyclic structures (does not throw / loop)", () => {
    const cyclic: Record<string, unknown> = { name: "x" };
    cyclic.self = cyclic;
    expect(() => scrubDeep(cyclic)).not.toThrow();
  });

  it("returns a sentinel at the depth cap so a deeply-nested secret never rides out raw", () => {
    // 10 levels deep — past MAX_DEPTH (8). The object at the cap is replaced by a
    // sentinel string, so a `password` below the cap cannot be emitted verbatim.
    let deep: Record<string, unknown> = { password: "leak", level: 99 };
    for (let i = 0; i < 10; i += 1) {
      deep = { nested: deep };
    }
    const serialized = JSON.stringify(scrubDeep(deep));
    expect(serialized).toContain("[depth-limited]");
    expect(serialized).not.toContain("leak");
  });
});
