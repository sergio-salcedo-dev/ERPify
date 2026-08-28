import { describe, expect, it, vi } from "vitest";
import { ApiAuditTimelineRepository } from "@/context/backoffice/audit/infrastructure/ApiAuditTimelineRepository";
import type { HttpClient } from "@/context/shared/http-client/domain/HttpClient";
import { API_ENDPOINTS } from "@/context/shared/http-client/infrastructure/ApiEndpoints";
import { SortDirection } from "@/context/shared/search/domain/SortDirection";
import type { AuditTimelineCriteria } from "@/context/backoffice/audit/domain/AuditTimelineRepository";

const entry = {
  id: "019f0691-2b5b-731e-9509-3470576159d6",
  occurredOn: "2026-06-27T00:53:24.955168+00:00",
  level: "activity",
  action: "ROUTE_BACKOFFICE_AUDIT_TIMELINE_SEARCH",
  actorType: "anonymous",
  actorId: null,
  correlationId: "019f0691-2aeb-7377-ba2d-1c9666c9ab90",
  resourceType: null,
  resourceId: null,
  actorErased: false,
  resourceErased: false,
};

const pagination = {
  hasNext: true,
  hasPrev: false,
  count: null,
  links: { next: "/api/v1/backoffice/audit/timeline?limit=25&after=opaque", prev: null },
};

function httpClientReturning(response: unknown): HttpClient {
  return {
    get: vi.fn().mockResolvedValue(response),
    post: vi.fn(),
    put: vi.fn(),
    patch: vi.fn(),
    delete: vi.fn(),
  };
}

function queryOf(httpClient: HttpClient): URLSearchParams {
  const url = vi.mocked(httpClient.get).mock.calls[0][0];
  return new URLSearchParams(url.split("?")[1] ?? "");
}

const BASE_CRITERIA: AuditTimelineCriteria = { filters: [], sort: null, limit: 25 };

describe("ApiAuditTimelineRepository.search", () => {
  it("serializes filters, sort and limit — never a page or cursor", async () => {
    const httpClient = httpClientReturning({ data: [entry], pagination });
    await new ApiAuditTimelineRepository(httpClient).search({
      filters: [
        { field: "level", operator: "eq", value: "security" },
        { field: "actorType", operator: "in", value: ["user", "api_key"] },
      ],
      sort: { field: "occurredOn", direction: SortDirection.DESC },
      limit: 50,
    });

    const q = queryOf(httpClient);
    expect(vi.mocked(httpClient.get).mock.calls[0][0]).toContain(
      API_ENDPOINTS.BACKOFFICE.AUDIT.TIMELINE,
    );
    expect(q.get("filters[0][field]")).toBe("level");
    expect(q.get("filters[0][operator]")).toBe("eq");
    expect(q.get("filters[0][value]")).toBe("security");
    expect(q.getAll("filters[1][value][]")).toEqual(["user", "api_key"]);
    expect(q.get("sort")).toBe("occurredOn");
    expect(q.get("direction")).toBe("DESC"); // PWA enum is lowercase; the wire is uppercase
    expect(q.get("limit")).toBe("50");
    // The query path NEVER serializes a cursor — continuing pages is the navigator
    // following server links verbatim.
    expect(q.has("cursor")).toBe(false);
    expect(q.has("after")).toBe(false);
    expect(q.has("before")).toBe(false);
    expect(q.has("paginationMode")).toBe(false);
  });

  it("omits sort when absent and clamps limit to the wire ceiling", async () => {
    const httpClient = httpClientReturning({ data: [entry], pagination });
    await new ApiAuditTimelineRepository(httpClient).search({
      filters: [],
      sort: null,
      limit: 1000,
    });

    const q = queryOf(httpClient);
    expect(q.has("sort")).toBe(false);
    expect(q.has("direction")).toBe(false);
    expect(q.get("limit")).toBe("100");
  });

  it("maps the cursor-only envelope to an AuditTimelinePage", async () => {
    const httpClient = httpClientReturning({ data: [entry], pagination });
    const page = await new ApiAuditTimelineRepository(httpClient).search(BASE_CRITERIA);

    expect(page.entries).toHaveLength(1);
    expect(page.entries[0].action).toBe("ROUTE_BACKOFFICE_AUDIT_TIMELINE_SEARCH");
    expect(page.entries[0].actorErased).toBe(false);
    expect(page.entries[0].resourceErased).toBe(false);
    expect(page.hasNext).toBe(true);
    expect(page.hasPrev).toBe(false);
    expect(page.count).toBeNull();
    expect(page.links).toEqual(pagination.links);
  });
});

describe("ApiAuditTimelineRepository response guard", () => {
  const envelope = { data: [entry], pagination };

  it("accepts the cursor-only envelope and rejects drift", async () => {
    const httpClient = httpClientReturning(envelope);
    await new ApiAuditTimelineRepository(httpClient).search(BASE_CRITERIA);

    const [, guard] = vi.mocked(httpClient.get).mock.calls[0];
    if (!guard) throw new Error("expected search() to pass a response guard");

    expect(guard(envelope)).toBe(true);
    // Null links + null actor/resource ids are valid (forensic faithfulness).
    expect(
      guard({
        data: [{ ...entry, actorId: null, resourceType: null, resourceId: null }],
        pagination: {
          hasNext: false,
          hasPrev: false,
          count: null,
          links: { next: null, prev: null },
        },
      }),
    ).toBe(true);
    // An unknown level/actorType token still passes — the read model never narrows them.
    expect(guard({ data: [{ ...entry, level: "wat", actorType: "robot" }], pagination })).toBe(
      true,
    );
    expect(guard({ data: [entry] })).toBe(false); // pagination missing
    expect(guard({ data: [{ ...entry, actorErased: "false" }], pagination })).toBe(false);
    expect(guard({ data: [{ ...entry, resourceErased: "false" }], pagination })).toBe(false);
    expect(guard({ data: [{ ...entry, id: 1 }], pagination })).toBe(false);
    expect(
      guard({
        data: [entry],
        pagination: { hasNext: true, hasPrev: false, count: null, links: { next: 1, prev: null } },
      }),
    ).toBe(false);
    expect(guard({ data: null, pagination })).toBe(false);
    expect(guard(undefined)).toBe(false);
  });
});

/**
 * `resourceErased` is the one field of the row whose ABSENCE the guard tolerates. It identifies
 * nothing — it drives an "erased" badge and withholds the follow-resource pivot — so a client
 * running ahead of the API, or one meeting a rolled-back API, must not lose every row of the screen
 * to MALFORMED_RESPONSE_ENVELOPE over it. Four states, and the guard has to separate them: missing
 * is valid and reads as `false`, `false`/`true` are valid, and anything else is still drift.
 */
describe("ApiAuditTimelineRepository resourceErased tolerance", () => {
  /** The row as an API that does not publish the flag would send it: the key is absent, not null. */
  function rowWithoutTheFlag(): Record<string, unknown> {
    const row: Record<string, unknown> = { ...entry };
    delete row.resourceErased;
    return row;
  }

  async function guardOf(response: unknown): Promise<(value: unknown) => boolean> {
    const httpClient = httpClientReturning(response);
    await new ApiAuditTimelineRepository(httpClient).search(BASE_CRITERIA);
    const [, guard] = vi.mocked(httpClient.get).mock.calls[0];
    if (!guard) throw new Error("expected search() to pass a response guard");
    return guard;
  }

  it("admits a row that omits the flag", async () => {
    const envelope = { data: [rowWithoutTheFlag()], pagination };
    const guard = await guardOf(envelope);

    expect(guard(envelope)).toBe(true);
  });

  it("reads an omitted flag as not-erased rather than as absent", async () => {
    // The mapper is the half that decides what the UI sees. Admitting the row while leaving the
    // field `undefined` would push the absence into every consumer that reads it as a boolean.
    const httpClient = httpClientReturning({ data: [rowWithoutTheFlag()], pagination });
    const page = await new ApiAuditTimelineRepository(httpClient).search(BASE_CRITERIA);

    expect(page.entries[0].resourceErased).toBe(false);
  });

  it.each([false, true])("admits and carries through the flag when present (%s)", async (flag) => {
    const envelope = { data: [{ ...entry, resourceErased: flag }], pagination };
    const guard = await guardOf(envelope);
    expect(guard(envelope)).toBe(true);

    const page = await new ApiAuditTimelineRepository(httpClientReturning(envelope)).search(
      BASE_CRITERIA,
    );
    expect(page.entries[0].resourceErased).toBe(flag);
  });

  // The tolerance is for absence, never for corruption: a present value that is not a boolean would
  // be READ as a state — `"false"` and `42` are both truthy — so the envelope still fails.
  it.each([
    { label: 'the string "false"', flag: "false" as unknown },
    { label: "null", flag: null as unknown },
    { label: "a number", flag: 42 as unknown },
  ])("rejects the envelope when the flag is $label", async ({ flag }) => {
    const guard = await guardOf({ data: [entry], pagination });

    expect(guard({ data: [{ ...entry, resourceErased: flag }], pagination })).toBe(false);
  });
});
