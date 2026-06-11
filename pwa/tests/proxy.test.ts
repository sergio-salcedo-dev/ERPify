import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { NodeEnv } from "@/context/shared/domain/types/nodeEnv";
import {
  DEV_TOOL_ROUTE_PREFIXES,
  isDevToolRoute,
} from "@/context/shared/dev-tools/domain/devToolRoutes";

/**
 * Locks the production short-circuit for the dev-tools surface. The
 * proxy (`pwa/src/proxy.ts`) is supposed to render a 404 for every
 * dev / QA URL when `NODE_ENV === production`, regardless of what the
 * page-level guards do. These tests verify that contract end-to-end —
 * including that the hardcoded matcher and the domain prefix list
 * stay in sync, since Turbopack requires the matcher to be a static
 * literal.
 */

vi.mock("next/server", () => {
  // jsdom doesn't ship a Web `Request` polyfill that satisfies
  // `NextRequest`; we only need the URL + the `next()` / `rewrite()`
  // sentinels to assert the proxy's choice.
  class FakeNextResponse {
    constructor(
      public readonly kind: "next" | "rewrite",
      public readonly destination?: URL,
    ) {}
    static next(): FakeNextResponse {
      return new FakeNextResponse("next");
    }
    static rewrite(destination: URL): FakeNextResponse {
      return new FakeNextResponse("rewrite", destination);
    }
  }
  return { NextResponse: FakeNextResponse };
});

import { config, proxy } from "@/proxy";
import type { NextRequest } from "next/server";

function fakeRequest(pathname: string): NextRequest {
  // jsdom doesn't ship a Web Request polyfill that satisfies NextRequest.
  // The proxy only reads `nextUrl`, so a structural stub is enough and
  // avoids the `as never` casts the type assertion would otherwise need.
  return { nextUrl: new URL(pathname, "https://localhost") } as unknown as NextRequest;
}

// `proxy` is typed against the real `NextResponse`, but the mock above
// returns a `FakeNextResponse` whose `kind` / `destination` sentinels are
// what we assert on. This bridges the static type to the mock's shape.
type ProxyDecision = { kind: "next" | "rewrite"; destination?: URL };
function runProxy(pathname: string): ProxyDecision {
  return proxy(fakeRequest(pathname)) as unknown as ProxyDecision;
}

describe("isDevToolRoute", () => {
  it.each(DEV_TOOL_ROUTE_PREFIXES)("matches the bare prefix %s", (prefix) => {
    expect(isDevToolRoute(prefix)).toBe(true);
  });

  it.each(DEV_TOOL_ROUTE_PREFIXES)("matches nested segments under %s", (prefix) => {
    expect(isDevToolRoute(`${prefix}/foo`)).toBe(true);
    expect(isDevToolRoute(`${prefix}/foo/bar`)).toBe(true);
  });

  it("does not match unrelated paths", () => {
    expect(isDevToolRoute("/")).toBe(false);
    expect(isDevToolRoute("/backoffice")).toBe(false);
    expect(isDevToolRoute("/backoffice/banks")).toBe(false);
    expect(isDevToolRoute("/dev-tooling")).toBe(false);
    // The gallery used to live at the top-level `/dev-error-gallery`;
    // it now nests under `/backoffice/dev-tools/error-gallery`, so the old path
    // must NOT be matched (and the proxy must not protect it).
    expect(isDevToolRoute("/dev-error-gallery")).toBe(false);
  });

  it("matches the nested /backoffice/dev-tools/error-gallery tool route", () => {
    expect(isDevToolRoute("/backoffice/dev-tools/error-gallery")).toBe(true);
    expect(isDevToolRoute("/backoffice/dev-tools/error-gallery/something")).toBe(true);
  });
});

describe("proxy.config.matcher — domain ↔ static-literal parity", () => {
  // Turbopack requires `config.matcher` to be a static literal, so we
  // can't derive it from `DEV_TOOL_ROUTE_PREFIXES` at runtime. The two
  // are duplicated by necessity — this test fails the build the moment
  // they drift.
  it("contains both the bare prefix and the `:path*` form for every domain prefix", () => {
    const expected = DEV_TOOL_ROUTE_PREFIXES.flatMap((prefix) => [prefix, `${prefix}/:path*`]);
    expect(config.matcher).toEqual(expected);
  });
});

describe("proxy — dev-tools production short-circuit", () => {
  describe("in production", () => {
    beforeEach(() => {
      vi.stubEnv("NODE_ENV", NodeEnv.PRODUCTION);
    });
    afterEach(() => {
      vi.unstubAllEnvs();
    });

    it.each(DEV_TOOL_ROUTE_PREFIXES)("rewrites %s to a guaranteed 404", (prefix) => {
      const result = runProxy(prefix);
      expect(result.kind).toBe("rewrite");
      expect(result.destination?.pathname).toBe("/__erpify-dev-tools-disabled__");
    });

    it("rewrites nested dev-tool URLs as well", () => {
      const result = runProxy("/backoffice/dev-tools/anything/here");
      expect(result.kind).toBe("rewrite");
    });

    it("ignores non-dev paths", () => {
      const result = runProxy("/backoffice/banks");
      expect(result.kind).toBe("next");
    });
  });

  describe("in development", () => {
    beforeEach(() => {
      vi.stubEnv("NODE_ENV", NodeEnv.DEVELOPMENT);
    });
    afterEach(() => {
      vi.unstubAllEnvs();
    });

    it.each(DEV_TOOL_ROUTE_PREFIXES)("lets %s pass through untouched", (prefix) => {
      const result = runProxy(prefix);
      expect(result.kind).toBe("next");
    });
  });

  describe("in test", () => {
    beforeEach(() => {
      vi.stubEnv("NODE_ENV", NodeEnv.TEST);
    });
    afterEach(() => {
      vi.unstubAllEnvs();
    });

    it("lets dev URLs pass through (CI E2E uses /dev-throw)", () => {
      const result = runProxy("/dev-throw");
      expect(result.kind).toBe("next");
    });
  });
});
