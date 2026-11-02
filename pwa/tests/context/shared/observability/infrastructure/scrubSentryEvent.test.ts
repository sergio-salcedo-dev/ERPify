import { describe, expect, it } from "vitest";
import type { ErrorEvent } from "@sentry/nextjs";
import {
  scrubSentryEvent,
  scrubSentrySpan,
  type StreamedSpanJSON,
} from "@/context/shared/observability/infrastructure/scrubSentryEvent";

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
  // The SDK adds one breadcrumb per `fetch` and one per history entry, and each carries the whole
  // URL as a STRING under a key no denylist holds. Every rule above matches a parameter name or a
  // structured key, so this whole class travelled in clear until the URL pass ran here too.
  it("redacts the query of a fetch breadcrumb's url", () => {
    const event = {
      breadcrumbs: [
        {
          category: "fetch",
          data: {
            method: "GET",
            url: "https://app.example/api/v1/backoffice/audit?filters%5B0%5D%5Bfield%5D=email&filters%5B0%5D%5Bvalue%5D=someone@example.com",
            status_code: 500,
          },
        },
      ],
    } as unknown as ErrorEvent;

    const url = scrubSentryEvent(event).breadcrumbs?.[0]?.data?.url as string;

    expect(url).toContain("filters%5B0%5D%5Bvalue%5D=REDACTED");
    expect(url).not.toContain("someone@example.com");
    // The shape is the diagnostic worth of a URL, so the path and the non-sensitive axes stay.
    expect(url).toContain("/api/v1/backoffice/audit");
    expect(url).toContain("filters%5B0%5D%5Bfield%5D=email");
  });

  // A history breadcrumb names the URL under `from`/`to`, which is why the pass is keyed on the
  // SHAPE of the value rather than on the key it sits under.
  it("redacts a person id carried by a navigation breadcrumb", () => {
    const event = {
      breadcrumbs: [
        {
          category: "navigation",
          data: {
            from: "/backoffice/audit?actorId=8f14e45f",
            to: "/login?next=%2Fbackoffice%2Faudit%3FactorId%3D8f14e45f&reason=session-expired",
          },
        },
      ],
    } as unknown as ErrorEvent;

    const data = scrubSentryEvent(event).breadcrumbs?.[0]?.data as Record<string, string>;

    expect(data.from).toBe("/backoffice/audit?actorId=REDACTED");
    expect(data.to).not.toContain("8f14e45f");
    expect(data.to).toContain("reason=session-expired");
  });

  it("leaves a breadcrumb value that is not URL-shaped alone", () => {
    const event = {
      breadcrumbs: [
        {
          category: "console",
          // Free text is out of scope here exactly as it is for `message` and a captured stack —
          // a recorded residual, not an oversight. Rewriting it would mangle any prose holding a `?`.
          message: "Could not reach /api/v1/banks?page=2 — retrying",
          data: { arguments: ["what? really"], level: "warn" },
        },
      ],
    } as unknown as ErrorEvent;

    const crumb = scrubSentryEvent(event).breadcrumbs?.[0];

    expect(crumb?.message).toBe("Could not reach /api/v1/banks?page=2 — retrying");
    expect(crumb?.data?.arguments).toEqual(["what? really"]);
  });

  it("applies the denylist before the url pass, so a stripped key has no url left to scrub", () => {
    const event = {
      breadcrumbs: [
        { category: "fetch", data: { authorization: "Bearer x", url: "/api/v1/banks?token=abc" } },
      ],
    } as unknown as ErrorEvent;

    expect(scrubSentryEvent(event).breadcrumbs?.[0]?.data).toEqual({
      url: "/api/v1/banks?token=REDACTED",
    });
  });
  // An event that embeds span data (the static trace lifecycle's transaction shape) is scrubbed on
  // the same terms. The streamed spans the SDK sends by default are covered below, by `scrubSentrySpan`.
  it("redacts the request URL a traced fetch leaves on span data embedded in an event", () => {
    const event = {
      spans: [
        {
          op: "http.client",
          description: "GET /api/v1/backoffice/audit",
          data: {
            url: "https://app.example/api/v1/backoffice/audit?filters%5B0%5D%5Bvalue%5D=jane@example.com",
            "http.url":
              "https://app.example/api/v1/backoffice/audit?filters%5B0%5D%5Bvalue%5D=jane@example.com",
            "url.full":
              "https://app.example/api/v1/backoffice/audit?filters%5B0%5D%5Bvalue%5D=jane@example.com",
            "http.query": "?filters%5B0%5D%5Bvalue%5D=jane%40example.com",
            "http.response.status_code": 500,
          },
        },
      ],
    } as unknown as ErrorEvent;

    const data = (
      scrubSentryEvent(event) as unknown as { spans: { data: Record<string, unknown> }[] }
    ).spans[0].data;

    expect(JSON.stringify(data)).not.toContain("jane");
    expect(data.url).toContain("filters%5B0%5D%5Bvalue%5D=REDACTED");
    expect(data["http.url"]).toContain("filters%5B0%5D%5Bvalue%5D=REDACTED");
    expect(data["url.full"]).toContain("filters%5B0%5D%5Bvalue%5D=REDACTED");
    // A bare query is neither absolute nor root-relative, so it needs its own arm of the rule.
    expect(data["http.query"]).toBe("?filters%5B0%5D%5Bvalue%5D=REDACTED");
    // The shape stays readable: an operator still sees which endpoint was called.
    expect(data.url).toContain("/api/v1/backoffice/audit");
    expect(data["http.response.status_code"]).toBe(500);
  });

  // Tracing is on in every environment (sampled 0.2 in prod) and spans are streamed one at a time,
  // never through `beforeSend`, so a traced fetch's request URL is reachable only from
  // `beforeSendSpan`. The attributes are the ones the SDK writes on a fetch span: `url.full`, and
  // `url.query` with its `?` removed — a bare query no URL-shape rule can recognise.
  it("redacts the request URL a traced fetch leaves on a streamed span", () => {
    const leaked =
      "https://app.example/api/v1/backoffice/audit?filters%5B0%5D%5Bvalue%5D=jane@example.com";
    const span: StreamedSpanJSON = {
      trace_id: "t",
      span_id: "s",
      name: "GET app.example",
      start_timestamp: 1,
      status: "error",
      is_segment: false,
      attributes: {
        "sentry.op": "http.client",
        "url.full": leaked,
        "url.query":
          "filters%5B0%5D%5Bfield%5D=actorId&filters%5B0%5D%5Bvalue%5D=jane%40example.com",
        "url.domain": "app.example",
        "http.request.header.referer": ["https://app.example/backoffice/audit?actorId=8f14e45f"],
        "http.response.status_code": 500,
        authorization: "Bearer x",
      },
      links: [{ context: { traceId: "t", spanId: "s" }, attributes: { "url.full": leaked } }],
    } as unknown as StreamedSpanJSON;

    const scrubbed = scrubSentrySpan(span);

    expect(JSON.stringify(scrubbed)).not.toContain("jane");
    expect(JSON.stringify(scrubbed)).not.toContain("8f14e45f");
    expect(scrubbed.attributes["url.full"]).toContain("filters%5B0%5D%5Bvalue%5D=REDACTED");
    expect(scrubbed.attributes["url.query"]).toBe(
      "filters%5B0%5D%5Bfield%5D=actorId&filters%5B0%5D%5Bvalue%5D=REDACTED",
    );
    expect(scrubbed.attributes).not.toHaveProperty("authorization");
    // The shape stays readable: an operator still sees which endpoint was called.
    expect(scrubbed.attributes["url.full"]).toContain("/api/v1/backoffice/audit");
    expect(scrubbed.attributes["http.response.status_code"]).toBe(500);
    expect(scrubbed.attributes["sentry.op"]).toBe("http.client");
  });

  it("leaves a bare query that carries nothing sensitive byte-identical", () => {
    const span = {
      trace_id: "t",
      span_id: "s",
      name: "GET app.example",
      start_timestamp: 1,
      status: "ok",
      is_segment: false,
      attributes: { "url.query": "page=2&size=20%20x" },
    } as StreamedSpanJSON;

    expect(scrubSentrySpan(span).attributes["url.query"]).toBe("page=2&size=20%20x");
  });

  // The node SDK records an outgoing request as a breadcrumb carrying the same bare `url.query`,
  // and a breadcrumb rides on an ERROR event, so this is the event path rather than the span one.
  it("redacts the bare url.query an outgoing-request breadcrumb carries", () => {
    const event = {
      breadcrumbs: [
        {
          category: "http",
          data: { "url.query": "actorId=8f14e45f&page=1", "http.method": "GET" },
        },
      ],
    } as unknown as ErrorEvent;

    expect(scrubSentryEvent(event).breadcrumbs?.[0]?.data?.["url.query"]).toBe(
      "actorId=REDACTED&page=1",
    );
  });

  // The SDK names a client span `<METHOD> <domain>` and a server span by its route, so a query in
  // the name is not a shape it produces today; the name pass is cheap insurance for one that does.
  it.each([
    ["GET /api/v1/audit?actorId=8f14e45f", "GET /api/v1/audit?actorId=REDACTED"],
    ["GET  /api/v1/audit?actorId=8f14e45f", "GET  /api/v1/audit?actorId=REDACTED"],
    ["GET\t/api/v1/audit?actorId=8f14e45f", "GET\t/api/v1/audit?actorId=REDACTED"],
    ["GET /api/v1/banks", "GET /api/v1/banks"],
    ["/api/v1/audit?actorId=8f14e45f", "/api/v1/audit?actorId=REDACTED"],
    ["GET", "GET"],
  ])("scrubs a query behind the method in span name %j", (name, expected) => {
    const span = {
      trace_id: "t",
      span_id: "s",
      name,
      start_timestamp: 1,
      status: "ok",
      is_segment: true,
      attributes: {},
    } as StreamedSpanJSON;

    expect(scrubSentrySpan(span).name).toBe(expected);
  });

  // The SDK catches a throw from `beforeSendSpan` and sends the span it handed over, unscrubbed.
  it("drops the span's data rather than sending it when the scrub throws", () => {
    const attributes = new Proxy(
      { "url.full": "https://app.example/audit?actorId=8f14e45f" },
      {
        ownKeys: () => {
          throw new Error("boom");
        },
      },
    );
    const span = {
      trace_id: "t",
      span_id: "s",
      name: "GET /audit?actorId=8f14e45f",
      start_timestamp: 1,
      status: "ok",
      is_segment: true,
      attributes,
    } as unknown as StreamedSpanJSON;

    const scrubbed = scrubSentrySpan(span);

    expect(scrubbed.attributes).toEqual({});
    expect(scrubbed.name).toBe("REDACTED");
    expect(scrubbed.trace_id).toBe("t");
  });

  it("redacts a person id on the trace context's own attributes", () => {
    const event = {
      contexts: {
        trace: {
          trace_id: "01H",
          data: { "url.full": "https://app.example/backoffice/audit?actorId=8f14e45f" },
        },
      },
    } as unknown as ErrorEvent;

    const trace = scrubSentryEvent(event).contexts?.trace as { data: Record<string, string> };

    expect(trace.data["url.full"]).toBe("https://app.example/backoffice/audit?actorId=REDACTED");
  });

  // The URL pass rewrites the caller's bytes, so it may only do so when it actually replaced
  // something. `URLSearchParams` normalises everything it round-trips, and applying that to a
  // string that merely LOOKS like a URL is corruption of the one thing this sink is kept for.
  it.each([
    { case: "prose holding a question mark", value: "/help? what now" },
    { case: "an encoded space an operator reads the request by", value: "/x?a=b%20c" },
    { case: "a trailing question mark with no query at all", value: "/foo?" },
    { case: "a value with no equals sign", value: "/x?flag" },
  ])("leaves $case exactly as the caller sent it", ({ value }) => {
    const event = {
      breadcrumbs: [{ category: "console", data: { arguments: [value] } }],
    } as unknown as ErrorEvent;

    const args = scrubSentryEvent(event).breadcrumbs?.[0]?.data?.arguments as string[];

    expect(args[0]).toBe(value);
  });

  it("redacts a URL-shaped value inside request.data, not just a denylisted key", () => {
    const event = {
      request: {
        data: { returnUrl: "https://app.example/backoffice/audit?actorId=8f14e45f", ok: true },
      },
    } as unknown as ErrorEvent;

    const data = scrubSentryEvent(event).request?.data as Record<string, unknown>;

    expect(data.returnUrl).toBe("https://app.example/backoffice/audit?actorId=REDACTED");
    expect(data.ok).toBe(true);
  });

  it("redacts a URL-shaped value inside a stringified request.data JSON body", () => {
    const event = {
      request: {
        data: JSON.stringify({ returnUrl: "/backoffice/audit?actorId=8f14e45f", ok: true }),
      },
    } as unknown as ErrorEvent;

    const data = JSON.parse(scrubSentryEvent(event).request?.data as string) as {
      returnUrl: string;
      ok: boolean;
    };

    expect(data.returnUrl).toBe("/backoffice/audit?actorId=REDACTED");
    expect(data.ok).toBe(true);
  });

  it("redacts a URL-shaped value inside tags, a surface no key-based scrub reaches", () => {
    const event = {
      tags: { "telemetry.scope": "api:transport", referer: "/audit?actorId=8f14e45f" },
    } as unknown as ErrorEvent;

    const tags = scrubSentryEvent(event).tags as Record<string, string>;

    expect(tags.referer).toBe("/audit?actorId=REDACTED");
    expect(tags["telemetry.scope"]).toBe("api:transport");
  });
});
