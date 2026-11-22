import { describe, expect, it } from "vitest";
import {
  apiOriginFrom,
  buildContentSecurityPolicy,
  mintNonce,
} from "@/context/shared/content-security-policy/domain/contentSecurityPolicy";

function directive(csp: string, name: string): string | undefined {
  return csp
    .split(";")
    .map((part) => part.trim())
    .find((part) => part === name || part.startsWith(`${name} `));
}

const build = (over: Partial<Parameters<typeof buildContentSecurityPolicy>[0]> = {}) =>
  buildContentSecurityPolicy({ nonce: "abc123", isProduction: true, apiOrigin: "", ...over });

describe("buildContentSecurityPolicy — script-src", () => {
  it("is nonce + strict-dynamic and never 'unsafe-inline' in production", () => {
    const scriptSrc = directive(build(), "script-src");
    expect(scriptSrc).toBe("script-src 'self' 'nonce-abc123' 'strict-dynamic'");
    expect(scriptSrc).not.toContain("'unsafe-inline'");
  });

  it("keeps 'unsafe-eval' out of production and admits it in development only", () => {
    expect(directive(build(), "script-src")).not.toContain("'unsafe-eval'");
    const dev = directive(build({ isProduction: false }), "script-src");
    expect(dev).toContain("'unsafe-eval'");
    expect(dev).not.toContain("'unsafe-inline'");
  });
});

describe("buildContentSecurityPolicy — other directives", () => {
  it("never admits a blanket https: origin in img-src", () => {
    expect(directive(build(), "img-src")).toBe("img-src 'self' data: blob:");
  });

  it("limits img-src and connect-src to self plus the API origin when one is configured", () => {
    const csp = build({ apiOrigin: "https://api.example.test" });
    expect(directive(csp, "img-src")).toBe("img-src 'self' data: blob: https://api.example.test");
    expect(directive(csp, "connect-src")).toBe("connect-src 'self' https://api.example.test");
  });

  it("keeps the locked-down structural directives", () => {
    const csp = build();
    for (const expected of [
      "object-src 'none'",
      "frame-ancestors 'none'",
      "base-uri 'self'",
      "form-action 'self'",
      "upgrade-insecure-requests",
    ]) {
      expect(csp.split("; ")).toContain(expected);
    }
  });
});

describe("mintNonce", () => {
  it("is base64, carries 128 bits and differs on every call", () => {
    const first = mintNonce();
    expect(first).toMatch(/^[A-Za-z0-9+/]{22}==$/);
    expect(mintNonce()).not.toBe(first);
  });
});

describe("apiOriginFrom", () => {
  it("reduces a base URL to its origin", () => {
    expect(apiOriginFrom("https://api.example.test/v1")).toBe("https://api.example.test");
  });

  it.each([undefined, "", "   ", "not a url"])("returns '' for %j", (raw) => {
    expect(apiOriginFrom(raw)).toBe("");
  });
});
