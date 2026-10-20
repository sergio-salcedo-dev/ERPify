import { afterEach, describe, expect, it, vi } from "vitest";
import { sentryInitOptions } from "@/context/shared/observability/infrastructure/sentryInitOptions";
import {
  scrubSentryEvent,
  scrubSentrySpan,
} from "@/context/shared/observability/infrastructure/scrubSentryEvent";

/**
 * The SDK's own defaults are permissive: with `dataCollection` absent it collects cookies, user
 * info, request and response bodies, database query data and queue payloads, and with
 * `beforeSendSpan` absent every streamed span leaves unscrubbed. Neither omission fails to compile
 * or throws, so the policy is pinned here rather than read.
 */
describe("sentryInitOptions", () => {
  afterEach(() => {
    vi.unstubAllEnvs();
  });

  const options = () => {
    vi.stubEnv("NEXT_PUBLIC_SENTRY_DSN", "https://key@o0.ingest.example/1");
    vi.stubEnv("NEXT_PUBLIC_APP_ENV", "prod");
    const resolved = sentryInitOptions();
    if (resolved === null) {
      throw new Error("expected options with a DSN set");
    }
    return resolved;
  };

  it("is inert without a DSN", () => {
    vi.stubEnv("NEXT_PUBLIC_SENTRY_DSN", "  ");
    expect(sentryInitOptions()).toBeNull();
  });

  it("sets every data collection category to its restrictive value", () => {
    const collection = options().dataCollection;

    expect(collection).toMatchObject({
      userInfo: false,
      cookies: false,
      httpBodies: [],
      graphQL: { document: false, variables: false },
      genAI: { inputs: false, outputs: false },
      databaseQueryData: false,
      queues: false,
    });
    expect(collection?.httpHeaders).toEqual({
      request: { deny: expect.arrayContaining(["forwarded", "-ip", "-user"]) },
      response: { deny: expect.arrayContaining(["forwarded", "-ip", "-user"]) },
    });
    expect(collection?.urlQueryParams).toEqual({
      deny: expect.arrayContaining(["actorid", "resourceid", "correlationid", "[value]"]),
    });
    expect(collection?.stackFrameVariables).toBe(false);
  });

  // A subset match would stay green over a category a later SDK adds with a permissive default.
  it("decides every category the SDK defines, and no other", () => {
    expect(Object.keys(options().dataCollection ?? {}).sort()).toEqual([
      "cookies",
      "databaseQueryData",
      "frameContextLines",
      "genAI",
      "graphQL",
      "httpBodies",
      "httpHeaders",
      "queues",
      "stackFrameVariables",
      "urlQueryParams",
      "userInfo",
    ]);
  });

  it("scrubs error events and streamed spans with the shared scrubbers", () => {
    const resolved = options();

    expect(resolved.beforeSend).toBe(scrubSentryEvent);
    expect(resolved.beforeSendSpan).toBe(scrubSentrySpan);
  });

  it("pins the streamed trace lifecycle, the one beforeSendSpan is wired for", () => {
    // A callback that does not match the lifecycle is never invoked, and an absent option lets
    // `SENTRY_TRACE_LIFECYCLE=static` in the environment switch the span scrub off.
    expect(options().traceLifecycle).toBe("stream");
  });
});
