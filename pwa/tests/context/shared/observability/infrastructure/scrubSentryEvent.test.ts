import { describe, expect, it } from "vitest";
import type { ErrorEvent } from "@sentry/nextjs";
import { scrubSentryEvent } from "@/context/shared/observability/infrastructure/scrubSentryEvent";

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

  it("redacts denylisted params in the raw query_string, keeping their key", () => {
    const event = {
      request: { query_string: "q=hi&token=abc&page=2" },
    } as unknown as ErrorEvent;

    const scrubbed = scrubSentryEvent(event);

    expect(scrubbed.request?.query_string).toBe("q=hi&token=REDACTED&page=2");
  });

  it("redacts the identity axes in the raw query_string", () => {
    const event = {
      request: { query_string: "actorId=8f14e45f&resourceId=8f14e45f&level=security" },
    } as unknown as ErrorEvent;

    const scrubbed = scrubSentryEvent(event);

    expect(scrubbed.request?.query_string).toBe(
      "actorId=REDACTED&resourceId=REDACTED&level=security",
    );
  });

  it("redacts the positional search grammar the audit screen fires at the API", () => {
    const event = {
      request: {
        query_string: "filters%5B0%5D%5Bvalue%5D=8f14e45f&filters%5B1%5D%5Bfield%5D=level",
      },
    } as unknown as ErrorEvent;

    const scrubbed = scrubSentryEvent(event);

    expect(scrubbed.request?.query_string).toBe(
      "filters%5B0%5D%5Bvalue%5D=REDACTED&filters%5B1%5D%5Bfield%5D=level",
    );
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

  it("redacts denylisted params in the request.url query, keeping the path and hash", () => {
    const event = {
      request: { url: "https://app.example/banks?token=abc&page=2#section" },
    } as unknown as ErrorEvent;

    expect(scrubSentryEvent(event).request?.url).toBe(
      "https://app.example/banks?token=REDACTED&page=2#section",
    );
  });

  it("redacts a person id the audit screen holds in the document URL", () => {
    const event = {
      request: { url: "https://app.example/backoffice/audit?actorId=8f14e45f&level=security" },
    } as unknown as ErrorEvent;

    expect(scrubSentryEvent(event).request?.url).toBe(
      "https://app.example/backoffice/audit?actorId=REDACTED&level=security",
    );
  });

  it("redacts an identifier carried inside a value, not just under a known name", () => {
    const event = {
      request: {
        query_string:
          "next=%2Fbackoffice%2Faudit%3FactorId%3D8f14e45f%26resourceId%3D8f14e45f&reason=session-expired",
      },
    } as unknown as ErrorEvent;

    const scrubbed = scrubSentryEvent(event);

    expect(scrubbed.request?.query_string).toContain("actorId%3DREDACTED");
    expect(scrubbed.request?.query_string).toContain("resourceId%3DREDACTED");
    expect(scrubbed.request?.query_string).not.toContain("8f14e45f");
  });

  // Each row is a spelling that reached this sink while the API redacted it. Sentry's retention is reached
  // by no erasure path, so a spelling only one side unwraps is a person id kept out of the log and let in
  // here. The assertion is deliberately `not.toContain` rather than an exact shape: what has to hold is
  // that the identifier is gone, not which encoding depth the sentinel comes back wearing.
  it.each([
    {
      case: "a nested URI at the depth the API follows",
      query: "next=%2Flogin%3Fnext%3D%252Faudit%253FactorId%253D8f14e45f",
    },
    {
      // The depth counter alone is not the guarantee: URLSearchParams decodes a value once, so a URI
      // wrapped twice carries no literal `?` and used to be skipped without ever being followed.
      case: "a nested URI whose query only surfaces after a second decoding",
      query: "next=%252Fbackoffice%252Faudit%253FactorId%253D8f14e45f",
    },
    {
      case: "an axis padded to miss a whole match",
      query: "actorId%00=8f14e45f&actorId%20=8f14e45f",
    },
    {
      case: "the search grammar still encoded after the caller's single decoding",
      query: "filters%255B0%255D%255Bvalue%255D=8f14e45f",
    },
  ])("keeps the identifier out of the event for $case", ({ query }) => {
    const event = { request: { query_string: query } } as unknown as ErrorEvent;

    expect(scrubSentryEvent(event).request?.query_string).not.toContain("8f14e45f");
  });

  it("redacts the explicitly indexed array form of the search grammar", () => {
    const event = {
      request: { query_string: "filters%5B0%5D%5Bvalue%5D%5B0%5D=8f14e45f" },
    } as unknown as ErrorEvent;

    expect(scrubSentryEvent(event).request?.query_string).toBe(
      "filters%5B0%5D%5Bvalue%5D%5B0%5D=REDACTED",
    );
  });

  it("redacts the query the Referer header carries", () => {
    const event = {
      request: {
        headers: {
          Referer: "https://app.example/backoffice/audit?actorId=8f14e45f&level=security",
          Accept: "application/json",
        },
      },
    } as unknown as ErrorEvent;

    const scrubbed = scrubSentryEvent(event);

    expect(scrubbed.request?.headers).toEqual({
      Referer: "https://app.example/backoffice/audit?actorId=REDACTED&level=security",
      Accept: "application/json",
    });
  });

  it("handles URLs with multiple hashes gracefully (preserving them)", () => {
    const event = {
      request: { url: "https://app.example/banks?token=abc#section#subtitle" },
    } as unknown as ErrorEvent;

    expect(scrubSentryEvent(event).request?.url).toBe(
      "https://app.example/banks?token=REDACTED#section#subtitle",
    );
  });

  it("scrubs stringified JSON request bodies", () => {
    const event = {
      request: { data: JSON.stringify({ token: "t", name: "ok" }) },
    } as unknown as ErrorEvent;

    const scrubbed = scrubSentryEvent(event);
    expect(JSON.parse(scrubbed.request?.data as string)).toEqual({ name: "ok" });
  });

  it("leaves an event with no sensitive surfaces untouched", () => {
    const event = { extra: { id: 1 } } as unknown as ErrorEvent;
    expect(scrubSentryEvent(event).extra).toEqual({ id: 1 });
  });
});
