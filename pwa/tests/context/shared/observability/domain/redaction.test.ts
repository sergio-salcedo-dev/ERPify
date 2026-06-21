import { describe, expect, it } from "vitest";
import {
  REDACTION_DENYLIST,
  isDenylistedKey,
  scrubDeep,
} from "@/context/shared/observability/domain/redaction";

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
      "email",
      "phone_number",
      "address",
    ]);
  });

  it("matches keys case-insensitively (substring)", () => {
    expect(isDenylistedKey("Password")).toBe(true);
    expect(isDenylistedKey("AUTHORIZATION")).toBe(true);
    expect(isDenylistedKey("token")).toBe(true);
    expect(isDenylistedKey("user_password")).toBe(true);
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

  it("returns a sentinel when the node budget (1000) is exceeded", () => {
    const wide: Record<string, unknown> = {};
    for (let i = 0; i < 1001; i += 1) {
      wide[`k${i}`] = { psw: "p" }; // use a non-denylisted key so it doesn't just skip it immediately without traversing
    }
    const scrubbed = scrubDeep(wide) as Record<string, unknown>;
    // Some keys will be scrubbed, but eventually it hits the node cap.
    expect(JSON.stringify(scrubbed)).toContain("[node-limited]");
  });

  it("passes through Dates, Maps, and Sets untouched instead of destroying them", () => {
    const now = new Date();
    const map = new Map([["a", 1]]);
    const set = new Set([1]);
    const input = { now, map, set };

    const scrubbed = scrubDeep(input) as typeof input;
    expect(scrubbed.now).toBe(now);
    expect(scrubbed.map).toBe(map);
    expect(scrubbed.set).toBe(set);
  });

  it("passes through custom class instances untouched", () => {
    class User {
      constructor(public name: string) {}
    }
    const user = new User("Alice");
    expect(scrubDeep(user)).toBe(user);
  });

  it("scrubs objects with no prototype (Object.create(null))", () => {
    const noProto = Object.create(null);
    noProto.token = "secret";
    noProto.ok = true;
    expect(scrubDeep(noProto)).toEqual({ ok: true });
  });
});
