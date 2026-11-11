import { describe, expect, it } from "vitest";
import {
  isIdentityAxisKey,
  QUERY_KEYS,
  withholdsWholeQuery,
} from "@/context/shared/observability/domain/redaction";
import {
  scrubSentryEvent,
  scrubSentrySpan,
} from "@/context/shared/observability/infrastructure/scrubSentryEvent";

const UPLOAD = "/api/v1/documents/uploads/0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1a2c/content";

describe("query keys (the evidence filename)", () => {
  it("holds exactly the keys the API's QUERY_KEYS holds", () => {
    expect([...QUERY_KEYS]).toEqual(["filename"]);
  });

  it.each([
    "filename",
    "FILENAME",
    "filename[]",
    "filename[0]",
    "%66ilename",
    "filename\u0000",
    "x-filename",
  ])("matches %s as a substring after reduction", (key) => {
    expect(isIdentityAxisKey(key)).toBe(true);
  });

  it("withholds the whole query on the upload route only", () => {
    expect(withholdsWholeQuery(UPLOAD)).toBe(true);
    expect(withholdsWholeQuery(`https://erpify.test${UPLOAD}`)).toBe(true);
    expect(withholdsWholeQuery(`${UPLOAD}/more`)).toBe(false);
    expect(withholdsWholeQuery("/api/v1/backoffice/banks")).toBe(false);
  });

  it("keeps neither the name nor a misspelt key out of a scrubbed breadcrumb", () => {
    const event = scrubSentryEvent({
      breadcrumbs: [
        { category: "fetch", data: { url: `${UPLOAD}?file_name=SECRET` } },
        { category: "fetch", data: { url: "/api/v1/backoffice/banks?filename=SECRET" } },
      ],
      request: {
        url: `https://erpify.test${UPLOAD}?filename=SECRET`,
        query_string: "filename=SECRET",
      },
    });

    expect(JSON.stringify(event)).not.toContain("SECRET");
  });

  const ID = "0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1a2c";
  const SPELLINGS: ReadonlyArray<readonly [string, string]> = [
    ["through the front controller", `/index.php${UPLOAD}`],
    ["absolute, through the front controller", `https://erpify.test/index.php${UPLOAD}`],
    ["with a percent-encoded segment", `/api/v1/documents/uploads/${ID}/%63ontent`],
    ["with an encoded slash", `/api/v1/documents/uploads%2F${ID}/content`],
    ["double-encoded", `/api/v1/documents/uploads/${ID}/%2563ontent`],
    ["with a triplet that is not UTF-8", "/api/v1/documents/uploads/%E0/%63ontent"],
  ];

  it.each(SPELLINGS)("withholds the whole query when the route is spelt %s", (_label, path) => {
    expect(withholdsWholeQuery(path)).toBe(true);
  });

  it.each([
    "/api/v1/documents/uploads/x/content/more",
    "/index.php/api/v1/documents/uploads/x/contents",
    "/api/v1/documents/uploads/x/%63ontent%2Fmore",
  ])("does not mistake the near miss %s for the route", (path) => {
    expect(withholdsWholeQuery(path)).toBe(false);
  });

  it.each(SPELLINGS)(
    "keeps no query in an event's request when the route is spelt %s",
    (_label, path) => {
      const event = scrubSentryEvent({
        request: { url: `${path}?file_name=SECRET`, query_string: "file_name=SECRET" },
      });

      expect(JSON.stringify(event)).not.toContain("SECRET");
      expect(event.request?.query_string).toBe("REDACTED");
    },
  );

  it.each([
    ["url.path", { "url.path": UPLOAD, "url.query": "file_name=SECRET" }],
    ["url.full", { "url.full": `https://erpify.test${UPLOAD}`, "url.query": "file_name=SECRET" }],
    ["url", { url: UPLOAD, "http.query": "?file_name=SECRET" }],
    ["http.url", { "http.url": `https://erpify.test${UPLOAD}`, "http.query": "file_name=SECRET" }],
    ["http.target", { "http.target": `${UPLOAD}?file_name=SECRET` }],
    [
      "an encoded url.path",
      { "url.path": `/api/v1/documents/uploads/${ID}/%63ontent`, "url.query": "file_name=SECRET" },
    ],
  ])(
    "keeps no query in a span or an event span naming the route under %s",
    (_label, attributes) => {
      const span = scrubSentrySpan({
        name: "PUT",
        attributes: { ...attributes },
      } as unknown as Parameters<typeof scrubSentrySpan>[0]);
      const event = scrubSentryEvent({
        spans: [{ data: { ...attributes } }],
      } as unknown as Parameters<typeof scrubSentryEvent>[0]);

      expect(JSON.stringify(span)).not.toContain("SECRET");
      expect(JSON.stringify(event)).not.toContain("SECRET");
    },
  );

  it("keeps a harmless detached query off the route", () => {
    const span = scrubSentrySpan({
      name: "GET",
      attributes: { "url.path": "/api/v1/backoffice/banks", "url.query": "page=2" },
    } as unknown as Parameters<typeof scrubSentrySpan>[0]);

    expect(span.attributes?.["url.query"]).toBe("page=2");
  });
});
