import { inject, injectable } from "inversify";
import { API_ENDPOINTS } from "../../../shared/http-client/infrastructure/ApiEndpoints";
import type { HttpClient } from "../../../shared/http-client/domain/HttpClient";
import { buildSearchParams } from "@/context/shared/search/infrastructure";
import { WIRE_MAX_LIMIT, type PageEnvelope } from "@/context/shared/search/domain";
import { Bank, type BankPrimitives } from "../domain/Bank";
import type {
  BankInput,
  BankRepository,
  BankSearchCriteria,
  BankSearchPage,
} from "../domain/BankRepository";

interface BankSearchResponse {
  data: BankPrimitives[];
  pagination: PageEnvelope;
}

interface BankSingleResponse {
  data: BankPrimitives;
}

function isObjectRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === "object" && value !== null;
}

// `accountCount` is a count: a present value must be a non-negative integer.
// `typeof NaN === "number"` and floats/negatives would otherwise pass and render
// as "None", so the guard — not the `?? 0` mapping (which only catches null) — rejects them.
function isNonNegativeInteger(value: unknown): value is number {
  return typeof value === "number" && Number.isInteger(value) && value >= 0;
}

function isBankPrimitives(value: unknown): value is BankPrimitives {
  return (
    isObjectRecord(value) &&
    typeof value.id === "string" &&
    typeof value.name === "string" &&
    typeof value.shortName === "string" &&
    typeof value.createdAt === "string" &&
    typeof value.updatedAt === "string" &&
    isNonNegativeInteger(value.accountCount)
  );
}

function isNumberOrNull(value: unknown): value is number | null {
  return value === null || typeof value === "number";
}

function isStringOrNull(value: unknown): value is string | null {
  return value === null || typeof value === "string";
}

/**
 * The adapter's trust boundary. Accepts ONLY the cursor-only envelope v2
 * (`hasNext`/`hasPrev`/`count`/`links`) and REJECTS the legacy page-based shape
 * (`currentPage`/`hasMorePages`/`cursor`), so a server still on the old contract
 * surfaces as a typed failure instead of a silent mismap. Shared by the query
 * adapter and the navigation adapter ({@link ApiBankSearchNavigator}).
 */
export function isBankSearchResponse(value: unknown): value is BankSearchResponse {
  if (!isObjectRecord(value) || !Array.isArray(value.data)) {
    return false;
  }
  const { data, pagination } = value;
  if (!data.every(isBankPrimitives) || !isObjectRecord(pagination)) {
    return false;
  }
  const { hasNext, hasPrev, count, links } = pagination;
  return (
    typeof hasNext === "boolean" &&
    typeof hasPrev === "boolean" &&
    isNumberOrNull(count) &&
    isObjectRecord(links) &&
    isStringOrNull(links.next) &&
    isStringOrNull(links.prev)
  );
}

export function isBankSingleResponse(value: unknown): value is BankSingleResponse {
  return isObjectRecord(value) && isBankPrimitives(value.data);
}

// Write-path responses (POST/PUT) omit accountCount — the serialization groups on
// those controllers don't include GROUP_ACCOUNT_COUNT, which is reserved for reads.
// Default to 0 when the field is absent so create()/update() never throw a guard error.
function isBankWriteSingleResponse(
  value: unknown,
): value is { data: Omit<BankPrimitives, "accountCount"> & { accountCount?: number } } {
  if (!isObjectRecord(value) || !isObjectRecord(value.data)) {
    return false;
  }
  const d = value.data;
  return (
    typeof d.id === "string" &&
    typeof d.name === "string" &&
    typeof d.shortName === "string" &&
    typeof d.createdAt === "string" &&
    typeof d.updatedAt === "string" &&
    (d.accountCount === undefined || isNonNegativeInteger(d.accountCount))
  );
}

/**
 * Maps a validated search response to the domain page: items plus the envelope
 * carried through verbatim (the `links` are server-composed and never rebuilt).
 * Shared so the query and navigation adapters map identically.
 */
export function toBankSearchPage(response: BankSearchResponse): BankSearchPage {
  return {
    banks: response.data.map(Bank.fromPrimitives),
    hasNext: response.pagination.hasNext,
    hasPrev: response.pagination.hasPrev,
    count: response.pagination.count,
    links: response.pagination.links,
  };
}

@injectable()
export class ApiBankRepository implements BankRepository {
  constructor(@inject("HttpClient") private readonly httpClient: HttpClient) {}

  async search(criteria: BankSearchCriteria): Promise<BankSearchPage> {
    // First page / query change only: filters + sort + limit, never a cursor.
    // Continuing to next/prev is `BankSearchNavigator.follow(link)` (W11) — the
    // client never serializes a cursor here, so navigation has one authority:
    // the server-composed `links` in the response envelope (W2).
    const params = buildSearchParams(criteria.filters);
    if (criteria.sort) {
      params.append("sort", criteria.sort.field);
      // The API sort enum is uppercase (`ASC`/`DESC`); the PWA enum is lowercase.
      params.append("direction", criteria.sort.direction.toUpperCase());
    }
    // Clamp to the wire ceiling (D-Cap): the UI can never emit `limit > 100`,
    // hard client enforcement complementary to the backend 422.
    params.append("limit", String(Math.min(criteria.limit, WIRE_MAX_LIMIT)));
    // No `paginationMode` → the API defaults to LIGHT (skips COUNT; `count` is
    // null). The banks list navigates by cursor and shows no total.

    const response = await this.httpClient.get(
      `${API_ENDPOINTS.BACKOFFICE.BANKS.LIST}?${params.toString()}`,
      isBankSearchResponse,
    );

    return toBankSearchPage(response);
  }

  async find(id: string): Promise<Bank> {
    const response = await this.httpClient.get(
      API_ENDPOINTS.BACKOFFICE.BANKS.DETAILS(id),
      isBankSingleResponse,
    );
    return Bank.fromPrimitives(response.data);
  }

  async create(input: BankInput): Promise<Bank> {
    const response = await this.httpClient.post(
      API_ENDPOINTS.BACKOFFICE.BANKS.CREATE,
      input,
      isBankWriteSingleResponse,
    );
    return Bank.fromPrimitives({ ...response.data, accountCount: response.data.accountCount ?? 0 });
  }

  async update(id: string, input: BankInput): Promise<Bank> {
    const response = await this.httpClient.put(
      API_ENDPOINTS.BACKOFFICE.BANKS.UPDATE(id),
      input,
      isBankWriteSingleResponse,
    );
    return Bank.fromPrimitives({ ...response.data, accountCount: response.data.accountCount ?? 0 });
  }

  async delete(id: string): Promise<void> {
    await this.httpClient.delete(API_ENDPOINTS.BACKOFFICE.BANKS.DELETE(id));
  }
}
