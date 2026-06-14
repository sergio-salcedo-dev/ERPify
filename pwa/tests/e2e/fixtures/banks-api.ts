import type { Page, Route } from "@playwright/test";

export interface BankFixture {
  id: string;
  name: string;
  shortName: string;
  createdAt: string;
  updatedAt: string;
  /**
   * Associated-account count — the read-model field the API serializes on
   * reads (GROUP_ACCOUNT_COUNT) and the list/single response guards require.
   * Defaults to 0 (no accounts → the optimistic delete-guard stays inactive).
   */
  accountCount: number;
}

export const SAMPLE_BANK_A: BankFixture = {
  id: "11111111-1111-4111-8111-111111111111",
  name: "Acme Savings",
  shortName: "ACME",
  createdAt: "2026-01-01T10:00:00Z",
  updatedAt: "2026-04-15T14:30:00Z",
  accountCount: 0,
};

export const SAMPLE_BANK_B: BankFixture = {
  id: "22222222-2222-4222-8222-222222222222",
  name: "Brookline Trust",
  shortName: "BRT",
  createdAt: "2026-02-12T09:00:00Z",
  updatedAt: "2026-04-20T16:00:00Z",
  accountCount: 0,
};

export const SAMPLE_BANK_C: BankFixture = {
  id: "33333333-3333-4333-8333-333333333333",
  name: "Cosmos Bank",
  shortName: "COSM",
  createdAt: "2026-03-20T08:00:00Z",
  updatedAt: "2026-04-22T12:00:00Z",
  accountCount: 0,
};

export const SAMPLE_BANK_D: BankFixture = {
  id: "44444444-4444-4444-8444-444444444444",
  name: "Delta Credit Union",
  shortName: "DCU",
  createdAt: "2026-04-05T11:30:00Z",
  updatedAt: "2026-04-25T09:00:00Z",
  accountCount: 0,
};

const ONE_DAY_MS = 24 * 60 * 60 * 1000;

/**
 * Generate `count` deterministic bank fixtures named `Bank 001`…, with
 * `createdAt` walking forward one day from 2026-01-01 UTC. Useful for
 * pagination/large-list E2E coverage.
 */
export function makeBanks(count: number): BankFixture[] {
  const start = Date.parse("2026-01-01T00:00:00Z");
  return Array.from({ length: count }, (_, i): BankFixture => {
    const idx = i + 1;
    const padded = idx.toString().padStart(3, "0");
    const idTail = idx.toString().padStart(12, "0");
    const created = new Date(start + i * ONE_DAY_MS).toISOString();
    return {
      id: `aaaaaaaa-aaaa-4aaa-8aaa-${idTail}`,
      name: `Bank ${padded}`,
      shortName: `BNK${padded}`,
      createdAt: created,
      updatedAt: created,
      accountCount: 0,
    };
  });
}

export type ListScenario = "happy" | "empty" | "server-error";
export type GetScenario = "happy" | "not-found" | "server-error";
export type CreateScenario = "happy" | "validation-error" | "server-error";
export type UpdateScenario = "happy" | "validation-error" | "not-found";
export type DeleteScenario = "happy" | "not-found" | "in-use";

export interface BanksApiScenario {
  list?: ListScenario;
  get?: GetScenario;
  create?: CreateScenario;
  update?: UpdateScenario;
  delete?: DeleteScenario;
  /** Bank fixture used to satisfy GET/{id} and updates. Defaults to SAMPLE_BANK_A. */
  bank?: BankFixture;
  /** Banks returned by the list endpoint when scenario.list === "happy". */
  list_banks?: BankFixture[];
  /** Ids whose GET/{id} answers 404 `bank-not-found` (stale rows for the bulk pre-check). */
  stale_ids?: string[];
  /** Ids whose DELETE answers 409 `bank-in-use` regardless of `scenario.delete`. */
  delete_in_use_ids?: string[];
}

const LIST_PATH = /\/api\/v1\/backoffice\/banks(\?.*)?$/;
const ITEM_PATH = /\/api\/v1\/backoffice\/banks\/[^/?#]+$/;

interface ProblemViolationFixture {
  field: string;
  message: string;
}

interface ProblemBodyOverrides {
  detail?: string;
  violations?: ProblemViolationFixture[];
  /** Type-specific RFC 9457 extension members (e.g. `bankId`, `accountCount`). */
  extensions?: Record<string, unknown>;
}

function problemBody(
  type: string,
  title: string,
  status: number,
  correlationId: string,
  overrides: ProblemBodyOverrides = {},
): Record<string, unknown> {
  return {
    type,
    title,
    status,
    instance: `${correlationId}-instance`,
    "correlation-id": correlationId,
    ...(overrides.detail === undefined ? {} : { detail: overrides.detail }),
    ...(overrides.violations ? { violations: overrides.violations } : {}),
    ...overrides.extensions,
  };
}

function inUseProblem(route: Route, bankId: string): Promise<void> {
  const correlationId = "01H-delete-409";
  return fulfillProblem(
    route,
    409,
    problemBody(
      "bank-in-use",
      "Bank cannot be deleted: 3 associated bank accounts",
      409,
      correlationId,
      { extensions: { bankId, accountCount: 3 } },
    ),
    correlationId,
  );
}

async function fulfillJson(route: Route, status: number, body: unknown): Promise<void> {
  await route.fulfill({
    status,
    contentType: "application/json",
    body: JSON.stringify(body),
  });
}

async function fulfillProblem(
  route: Route,
  status: number,
  body: Record<string, unknown>,
  correlationId: string,
): Promise<void> {
  await route.fulfill({
    status,
    contentType: "application/problem+json",
    headers: {
      "X-Correlation-Id": correlationId,
      "Cache-Control": "no-store",
    },
    body: JSON.stringify(body),
  });
}

function bankFieldValue(bank: BankFixture, field: string): string {
  switch (field) {
    case "name":
      return bank.name;
    case "shortName":
      return bank.shortName;
    case "createdAt":
      return bank.createdAt;
    case "updatedAt":
      return bank.updatedAt;
    case "id":
      return bank.id;
    default:
      return "";
  }
}

function matchesFilter(
  bank: BankFixture,
  field: string,
  operator: string,
  params: URLSearchParams,
  index: number,
): boolean {
  if (operator === "in") {
    return params.getAll(`filters[${index}][value][]`).includes(bankFieldValue(bank, field));
  }
  const value = params.get(`filters[${index}][value]`) ?? "";
  const fieldValue = bankFieldValue(bank, field);
  switch (operator) {
    case "contains":
      return fieldValue.toLowerCase().includes(value.toLowerCase());
    case "eq":
      return fieldValue === value;
    case "gt":
      return Date.parse(fieldValue) > Date.parse(value);
    case "gte":
      return Date.parse(fieldValue) >= Date.parse(value);
    case "lt":
      return Date.parse(fieldValue) < Date.parse(value);
    case "lte":
      return Date.parse(fieldValue) <= Date.parse(value);
    default:
      return true;
  }
}

function compareField(a: BankFixture, b: BankFixture, field: string): number {
  if (field === "createdAt" || field === "updatedAt") {
    return Date.parse(bankFieldValue(a, field)) - Date.parse(bankFieldValue(b, field));
  }
  return bankFieldValue(a, field).localeCompare(bankFieldValue(b, field), "en", {
    sensitivity: "base",
  });
}

/**
 * Emulates the server-driven search contract over the in-memory fixtures:
 * applies the wire `filters[]` then `sort`/`direction`. The PWA is now
 * server-driven, so the mock must do what the real endpoint does instead of
 * returning the whole list; the cursor-only slice (`limit` + opaque
 * `after`/`before`) is applied by the caller.
 */
function applyMockQuery(banks: BankFixture[], params: URLSearchParams): BankFixture[] {
  let result = [...banks];
  for (let i = 0; params.has(`filters[${i}][field]`); i++) {
    const field = params.get(`filters[${i}][field]`) ?? "";
    const operator = params.get(`filters[${i}][operator]`) ?? "";
    result = result.filter((bank) => matchesFilter(bank, field, operator, params, i));
  }
  const sort = params.get("sort");
  if (sort) {
    const direction = params.get("direction") === "DESC" ? -1 : 1;
    result.sort((a, b) => direction * compareField(a, b, sort));
  }
  return result;
}

/**
 * The mock's opaque cursor: the absolute start offset of the target page,
 * base64url-encoded. The client treats it as opaque — it forwards the whole
 * server-issued link verbatim and never decodes it; only this mock, standing in
 * for the API's `CursorCodec`, reads it to compute the slice. `next` advances
 * one page (`after`), `prev` steps back one page (`before`); both decode to the
 * target page's start offset, so a next→prev round-trip lands on the same rows.
 */
function encodeCursorOffset(offset: number): string {
  return Buffer.from(String(offset)).toString("base64url");
}

function decodeCursorOffset(params: URLSearchParams): number {
  const raw = params.get("after") ?? params.get("before");
  if (raw === null) {
    return 0;
  }
  const decoded = Number(Buffer.from(raw, "base64url").toString());
  return Number.isInteger(decoded) && decoded > 0 ? decoded : 0;
}

/**
 * Build a server-composed relative pagination link: the current query with the
 * cursor swapped for an `after`/`before` pointing at `offset`. Same shape the
 * real `SearchResponder` emits — relative, same-origin, preserving
 * `limit`/`sort`/`direction`/`filters[]` — so {@link ApiBankSearchNavigator}'s
 * same-origin guard accepts it and the client replays it untouched (W9/W11).
 */
function buildCursorLink(url: URL, param: "after" | "before", offset: number): string {
  const next = new URLSearchParams(url.searchParams);
  next.delete("after");
  next.delete("before");
  next.set(param, encodeCursorOffset(offset));
  return `${url.pathname}?${next.toString()}`;
}

export async function mockBanksApi(page: Page, scenario: BanksApiScenario): Promise<void> {
  const bank = scenario.bank ?? SAMPLE_BANK_A;
  const listBanks = scenario.list_banks ?? [SAMPLE_BANK_A, SAMPLE_BANK_B];

  await page.route(
    (url) => LIST_PATH.test(url.pathname) && !ITEM_PATH.test(url.pathname),
    async (route) => {
      const method = route.request().method();

      if (method === "GET") {
        if (scenario.list === "server-error") {
          const correlationId = "01H-list-error";
          await fulfillProblem(
            route,
            500,
            problemBody("unhandled-exception", "Database unavailable.", 500, correlationId),
            correlationId,
          );
          return;
        }

        // Cursor-only (PR3): the opaque `after`/`before` cursor decodes to the
        // target page's start offset; the envelope carries directional flags +
        // verbatim `links`. No page number, no total (LIGHT → count null), no
        // legacy `currentPage`/`pageCount`/`hasMorePages`/`cursor`.
        const base = scenario.list === "empty" ? [] : listBanks;
        const url = new URL(route.request().url());
        const params = url.searchParams;
        const matched = applyMockQuery(base, params);
        const limit = Number(params.get("limit")) || 25;
        const start = decodeCursorOffset(params);
        const slice = matched.slice(start, start + limit);
        const hasPrev = start > 0;
        const hasNext = start + limit < matched.length;

        await fulfillJson(route, 200, {
          data: slice,
          pagination: {
            hasNext,
            hasPrev,
            count: null,
            links: {
              next: hasNext ? buildCursorLink(url, "after", start + limit) : null,
              prev: hasPrev ? buildCursorLink(url, "before", Math.max(0, start - limit)) : null,
            },
          },
        });
        return;
      }

      if (method === "POST") {
        switch (scenario.create) {
          case "validation-error": {
            const correlationId = "01H-create-validation";
            await fulfillProblem(
              route,
              422,
              problemBody("validation-failed", "Validation failed.", 422, correlationId, {
                violations: [{ field: "name", message: "The name field is required." }],
              }),
              correlationId,
            );
            return;
          }
          case "server-error": {
            const correlationId = "01H-create-error";
            await fulfillProblem(
              route,
              500,
              problemBody("unhandled-exception", "Something went wrong.", 500, correlationId, {
                detail: "The bank could not be created.",
              }),
              correlationId,
            );
            return;
          }
          case "happy":
          default:
            await fulfillJson(route, 201, { data: bank });
            return;
        }
      }

      await route.fallback();
    },
  );

  await page.route(
    (url) => ITEM_PATH.test(url.pathname),
    async (route) => {
      const method = route.request().method();

      const itemId = new URL(route.request().url()).pathname.split("/").pop() ?? "";

      if (method === "GET") {
        if (scenario.stale_ids?.includes(itemId)) {
          const correlationId = "01H-get-stale-404";
          await fulfillProblem(
            route,
            404,
            problemBody("bank-not-found", "Bank not found", 404, correlationId),
            correlationId,
          );
          return;
        }
        switch (scenario.get) {
          case "not-found": {
            const correlationId = "01H-get-404";
            await fulfillProblem(
              route,
              404,
              problemBody("bank-not-found", "Bank not found.", 404, correlationId),
              correlationId,
            );
            return;
          }
          case "server-error": {
            const correlationId = "01H-get-error";
            await fulfillProblem(
              route,
              500,
              problemBody("unhandled-exception", "Database unavailable.", 500, correlationId),
              correlationId,
            );
            return;
          }
          case "happy":
          default:
            await fulfillJson(route, 200, { data: bank });
            return;
        }
      }

      if (method === "PUT") {
        switch (scenario.update) {
          case "validation-error": {
            const correlationId = "01H-update-validation";
            await fulfillProblem(
              route,
              422,
              problemBody("validation-failed", "Validation failed.", 422, correlationId, {
                violations: [
                  {
                    field: "shortName",
                    message: "The code must not exceed 50 characters.",
                  },
                ],
              }),
              correlationId,
            );
            return;
          }
          case "not-found": {
            const correlationId = "01H-update-404";
            await fulfillProblem(
              route,
              404,
              problemBody("bank-not-found", "Bank not found.", 404, correlationId, {
                detail: "It may have been deleted.",
              }),
              correlationId,
            );
            return;
          }
          case "happy":
          default:
            await fulfillJson(route, 200, { data: bank });
            return;
        }
      }

      if (method === "DELETE") {
        if (scenario.delete_in_use_ids?.includes(itemId)) {
          await inUseProblem(route, itemId);
          return;
        }
        switch (scenario.delete) {
          case "not-found": {
            const correlationId = "01H-delete-404";
            await fulfillProblem(
              route,
              404,
              problemBody("bank-not-found", "Bank not found.", 404, correlationId),
              correlationId,
            );
            return;
          }
          case "in-use":
            await inUseProblem(route, itemId);
            return;
          case "happy":
          default:
            await route.fulfill({ status: 204 });
            return;
        }
      }

      await route.fallback();
    },
  );
}
