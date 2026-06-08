import { describe, expect, it } from "vitest";
import type { ErrorEvent } from "@sentry/nextjs";
import { scrubSentryEvent } from "@/context/shared/infrastructure/Observability/scrubSentryEvent";

describe("scrubSentryEvent", () => {
  it("strips denylisted keys from extra and contexts (recursively)", () => {
    const event = {
      extra: { token: "t", payload: { password: "p", id: 1 } },
      contexts: { custom: { secret: "s", ok: true } },
    } as unknown as ErrorEvent;

    const scrubbed = scrubSentryEvent(event);

    expect(scrubbed.extra).toEqual({ payload: { id: 1 } });
    expect(scrubbed.contexts).toEqual({ custom: { ok: true } });
  });

  it("strips denylisted keys from request data / headers / cookies", () => {
    const event = {
      request: {
        data: { authorization: "Bearer x", name: "ok" },
        headers: { Cookie: "a=b", "Content-Type": "application/json" },
        cookies: { session: "s", token: "t" },
      },
    } as unknown as ErrorEvent;

    const scrubbed = scrubSentryEvent(event);

    expect(scrubbed.request?.data).toEqual({ name: "ok" });
    expect(scrubbed.request?.headers).toEqual({ "Content-Type": "application/json" });
    expect(scrubbed.request?.cookies).toEqual({ session: "s" });
  });

  it("scrubs denylisted params from the raw query_string", () => {
    const event = {
      request: { query_string: "q=hi&token=abc&page=2" },
    } as unknown as ErrorEvent;

    const scrubbed = scrubSentryEvent(event);

    expect(scrubbed.request?.query_string).toBe("q=hi&page=2");
  });

  it("strips denylisted keys from breadcrumbs and user", () => {
    const event = {
      breadcrumbs: [{ category: "fetch", data: { token: "t", url: "/x" } }],
      user: { id: "u1", password: "p" },
    } as unknown as ErrorEvent;

    const scrubbed = scrubSentryEvent(event);

    expect(scrubbed.breadcrumbs).toEqual([{ category: "fetch", data: { url: "/x" } }]);
    expect(scrubbed.user).toEqual({ id: "u1" });
  });

  it("strips denylisted params from the request.url query, keeping the path", () => {
    const event = {
      request: { url: "https://app.example/banks?token=abc&page=2" },
    } as unknown as ErrorEvent;

    expect(scrubSentryEvent(event).request?.url).toBe("https://app.example/banks?page=2");
  });

  it("leaves an event with no sensitive surfaces untouched", () => {
    const event = { extra: { id: 1 } } as unknown as ErrorEvent;
    expect(scrubSentryEvent(event).extra).toEqual({ id: 1 });
  });
});
