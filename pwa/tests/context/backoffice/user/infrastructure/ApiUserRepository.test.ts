import { describe, expect, it, vi } from "vitest";
import { User } from "@/context/backoffice/user/domain/User";
import { ApiUserRepository } from "@/context/backoffice/user/infrastructure/ApiUserRepository";
import type { UserRepository } from "@/context/backoffice/user/domain/UserRepository";
import { UserProblemType } from "@/context/backoffice/user/domain/UserProblemType";
import { HttpError } from "@/context/shared/http-client/domain/HttpError";
import { HttpStatus } from "@/context/shared/http-client/domain/HttpStatus";
import type { HttpClient } from "@/context/shared/http-client/domain/HttpClient";
import { API_ENDPOINTS } from "@/context/shared/http-client/infrastructure/ApiEndpoints";
import { SortDirection } from "@/context/shared/search/domain/SortDirection";
import type { ResourceSearchCriteria } from "@/context/shared/resource/domain/CrudRepository";

const primitives = {
  id: "0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b",
  email: "admin@erpify.test",
  status: "ACTIVE",
  roles: ["ADMIN", "VIEWER"],
  createdAt: "2026-01-01T00:00:00+00:00",
  updatedAt: "2026-01-02T00:00:00+00:00",
};

// Cursor-only envelope v2: directional flags, optional count, verbatim links.
const pagination = {
  hasNext: true,
  hasPrev: true,
  count: 42,
  links: {
    next: "/api/v1/backoffice/users?limit=25&after=cursor-next",
    prev: "/api/v1/backoffice/users?limit=25&before=cursor-prev",
  },
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

const BASE_CRITERIA: ResourceSearchCriteria = { filters: [], sort: null, limit: 25 };

describe("ApiUserRepository.search", () => {
  it("serializes filters, sort and limit — never a page or cursor", async () => {
    const httpClient = httpClientReturning({ data: [primitives], pagination });
    await new ApiUserRepository(httpClient).search({
      filters: [
        { field: "email", operator: "contains", value: "erpify" },
        { field: "status", operator: "eq", value: "ACTIVE" },
      ],
      sort: { field: "email", direction: SortDirection.DESC },
      limit: 50,
    });

    const q = queryOf(httpClient);
    expect(vi.mocked(httpClient.get).mock.calls[0][0]).toContain(
      API_ENDPOINTS.BACKOFFICE.USERS.LIST,
    );
    expect(q.get("filters[0][field]")).toBe("email");
    expect(q.get("filters[0][operator]")).toBe("contains");
    expect(q.get("filters[0][value]")).toBe("erpify");
    expect(q.get("filters[1][field]")).toBe("status");
    expect(q.get("filters[1][value]")).toBe("ACTIVE");
    expect(q.get("sort")).toBe("email");
    expect(q.get("direction")).toBe("DESC"); // PWA enum is lowercase; the wire is uppercase
    expect(q.get("limit")).toBe("50");
    // The query path NEVER serializes a cursor or page — continuing pages is the
    // navigator following server links verbatim.
    expect(q.has("page")).toBe(false);
    expect(q.has("cursor")).toBe(false);
    expect(q.has("after")).toBe(false);
    expect(q.has("before")).toBe(false);
    expect(q.has("paginationMode")).toBe(false);
  });

  it("omits sort when absent and clamps limit to the wire ceiling", async () => {
    const httpClient = httpClientReturning({ data: [primitives], pagination });
    await new ApiUserRepository(httpClient).search({ filters: [], sort: null, limit: 1000 });

    const q = queryOf(httpClient);
    expect(q.has("sort")).toBe(false);
    expect(q.has("direction")).toBe(false);
    expect(q.get("limit")).toBe("100");
  });

  it("maps the cursor-only envelope to a page of User items", async () => {
    const httpClient = httpClientReturning({ data: [primitives], pagination });
    const page = await new ApiUserRepository(httpClient).search(BASE_CRITERIA);

    expect(page.items).toHaveLength(1);
    expect(page.items[0]).toBeInstanceOf(User);
    expect(page.items[0].email).toBe("admin@erpify.test");
    expect(page.items[0].roles).toEqual(["ADMIN", "VIEWER"]);
    expect(page.hasNext).toBe(true);
    expect(page.hasPrev).toBe(true);
    expect(page.count).toBe(42);
    // Links travel through verbatim — never rebuilt client-side.
    expect(page.links).toEqual(pagination.links);
  });
});

describe("ApiUserRepository response guards", () => {
  const searchEnvelope = { data: [primitives], pagination };

  it("accepts the cursor-only envelope and rejects the legacy page-based shape", async () => {
    const httpClient = httpClientReturning(searchEnvelope);
    await new ApiUserRepository(httpClient).search(BASE_CRITERIA);

    const [, guard] = vi.mocked(httpClient.get).mock.calls[0];
    if (!guard) throw new Error("expected search() to pass a response guard");

    expect(guard(searchEnvelope)).toBe(true);
    // Null links are valid (affordance absent) — the shape stays constant.
    expect(
      guard({
        data: [primitives],
        pagination: {
          hasNext: false,
          hasPrev: false,
          count: null,
          links: { next: null, prev: null },
        },
      }),
    ).toBe(true);
    expect(guard({ data: [primitives] })).toBe(false); // pagination missing
    // The legacy page-based envelope MUST be rejected (drift guard).
    expect(
      guard({
        data: [primitives],
        pagination: { currentPage: 1, hasMorePages: true, cursor: "c" },
      }),
    ).toBe(false);
    // links.next must be string | null, never another type.
    expect(
      guard({
        data: [primitives],
        pagination: { hasNext: true, hasPrev: false, count: null, links: { next: 1, prev: null } },
      }),
    ).toBe(false);
    expect(guard({ data: null, pagination })).toBe(false);
    // A row whose roles are not a string array is rejected.
    expect(guard({ data: [{ ...primitives, roles: "ADMIN" }], pagination })).toBe(false);
    // A leaked credential column is simply ignored (not required) — the row still
    // validates on its declared fields; the projection never selects it anyway.
    expect(guard({ data: [{ ...primitives, id: 42 }], pagination })).toBe(false);
    expect(guard(undefined)).toBe(false);
  });

  it("passes a single-envelope guard to find that rejects an unwrapped or malformed body", async () => {
    const httpClient = httpClientReturning({ data: primitives });
    await new ApiUserRepository(httpClient).find(primitives.id);

    const findGuard = vi.mocked(httpClient.get).mock.calls[0][1];
    if (!findGuard) throw new Error("expected find() to pass a response guard");

    expect(findGuard({ data: primitives })).toBe(true);
    expect(findGuard(primitives)).toBe(false); // unwrapped payload
    expect(findGuard({ data: { ...primitives, status: 7 } })).toBe(false);
    expect(findGuard({ data: { ...primitives, email: undefined } })).toBe(false);
    expect(findGuard(null)).toBe(false);
  });

  it("hits the users detail endpoint and hydrates a User on find", async () => {
    const httpClient = httpClientReturning({ data: primitives });
    const user = await new ApiUserRepository(httpClient).find(primitives.id);

    expect(vi.mocked(httpClient.get).mock.calls[0][0]).toBe(
      API_ENDPOINTS.BACKOFFICE.USERS.DETAILS(primitives.id),
    );
    expect(user).toBeInstanceOf(User);
    expect(user.status).toBe("ACTIVE");
  });
});

describe("ApiUserRepository write methods", () => {
  const input = {
    email: primitives.email,
    roles: ["ADMIN" as const],
    status: "ACTIVE" as const,
  };

  it("reject create/update/delete as unsupported (501) without touching the network", async () => {
    const httpClient = httpClientReturning(undefined);
    const repository: UserRepository = new ApiUserRepository(httpClient);

    for (const call of [
      () => repository.create(input),
      () => repository.update(primitives.id, input),
      () => repository.delete(primitives.id),
    ]) {
      await expect(call()).rejects.toBeInstanceOf(HttpError);
      await expect(call()).rejects.toMatchObject({
        problem: { type: UserProblemType.NOT_SUPPORTED, status: HttpStatus.NOT_IMPLEMENTED },
      });
    }

    expect(httpClient.post).not.toHaveBeenCalled();
    expect(httpClient.put).not.toHaveBeenCalled();
    expect(httpClient.delete).not.toHaveBeenCalled();
  });
});
