import { inject, injectable } from "inversify";
import { API_ENDPOINTS } from "../../../shared/infrastructure/api/ApiEndpoints";
import type { HttpClient } from "../../../shared/infrastructure/HttpClient/HttpClient";
import { buildSearchParams } from "@/context/shared/infrastructure/Search";
import { WIRE_MAX_LIMIT, type PageEnvelope } from "@/context/shared/domain/Search";
import {
  BankAccount,
  type BankAccountCurrency,
  type BankAccountPrimitives,
  type BankAccountStatus,
} from "../domain/BankAccount";
import type {
  BankAccountRepository,
  BankAccountSearchCriteria,
  BankAccountSearchPage,
} from "../domain/BankAccountRepository";

interface BankAccountSearchResponse {
  data: BankAccountPrimitives[];
  pagination: PageEnvelope;
}

function isObjectRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === "object" && value !== null;
}

function isStringOrNull(value: unknown): value is string | null {
  return value === null || typeof value === "string";
}

function isNumberOrNull(value: unknown): value is number | null {
  return value === null || typeof value === "number";
}

const CURRENCIES: readonly BankAccountCurrency[] = ["EUR"];
const STATUSES: readonly BankAccountStatus[] = ["active", "inactive", "closed"];

function isBankAccountPrimitives(value: unknown): value is BankAccountPrimitives {
  return (
    isObjectRecord(value) &&
    typeof value.id === "string" &&
    typeof value.holderName === "string" &&
    typeof value.iban === "string" &&
    isStringOrNull(value.bic) &&
    isStringOrNull(value.alias) &&
    typeof value.currency === "string" &&
    CURRENCIES.includes(value.currency as BankAccountCurrency) &&
    typeof value.status === "string" &&
    STATUSES.includes(value.status as BankAccountStatus)
  );
}

/**
 * The adapter's trust boundary. Accepts ONLY the cursor-only envelope v2
 * (`hasNext`/`hasPrev`/`count`/`links`) and REJECTS the legacy page-based shape
 * (`currentPage`/`hasMorePages`/`cursor`), so a server still on the old contract
 * surfaces as a typed failure instead of a silent mismap. Shared by the query
 * adapter and the navigation adapter ({@link ApiBankAccountSearchNavigator}).
 */
export function isBankAccountSearchResponse(value: unknown): value is BankAccountSearchResponse {
  if (!isObjectRecord(value) || !Array.isArray(value.data)) {
    return false;
  }
  const { data, pagination } = value;
  if (!data.every(isBankAccountPrimitives) || !isObjectRecord(pagination)) {
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

/**
 * Maps a validated search response to the domain page: items plus the envelope
 * carried through verbatim (the `links` are server-composed and never rebuilt).
 * Shared so the query and navigation adapters map identically.
 */
export function toBankAccountSearchPage(
  response: BankAccountSearchResponse,
): BankAccountSearchPage {
  return {
    accounts: response.data.map(BankAccount.fromPrimitives),
    hasNext: response.pagination.hasNext,
    hasPrev: response.pagination.hasPrev,
    count: response.pagination.count,
    links: response.pagination.links,
  };
}

@injectable()
export class ApiBankAccountRepository implements BankAccountRepository {
  constructor(@inject("HttpClient") private readonly httpClient: HttpClient) {}

  async search(
    bankId: string,
    criteria: BankAccountSearchCriteria,
  ): Promise<BankAccountSearchPage> {
    // First page / query change only: filters + sort + limit, never a cursor.
    // Continuing to next/prev is `BankAccountSearchNavigator.follow(link)` — the
    // client never serializes a cursor here, so navigation has one authority:
    // the server-composed `links` in the response envelope.
    const params = buildSearchParams(criteria.filters);
    if (criteria.sort) {
      params.append("sort", criteria.sort.field);
      // The API sort enum is uppercase (`ASC`/`DESC`); the PWA enum is lowercase.
      params.append("direction", criteria.sort.direction.toUpperCase());
    }
    // Clamp to the wire ceiling (D-Cap): the UI can never emit `limit > 100`,
    // hard client enforcement complementary to the backend 422.
    params.append("limit", String(Math.min(criteria.limit, WIRE_MAX_LIMIT)));

    const response = await this.httpClient.get(
      `${API_ENDPOINTS.BACKOFFICE.BANKS.ACCOUNTS(bankId)}?${params.toString()}`,
      isBankAccountSearchResponse,
    );

    return toBankAccountSearchPage(response);
  }
}
