import { describe, expect, it } from "vitest";
import {
  isIdentityAxisKey,
  QUERY_KEYS,
  withholdsWholeQuery,
} from "@/context/shared/observability/domain/redaction";
import { scrubSentryEvent } from "@/context/shared/observability/infrastructure/scrubSentryEvent";

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
});
