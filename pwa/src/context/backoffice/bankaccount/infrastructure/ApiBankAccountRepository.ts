import { inject, injectable } from "inversify";
import { API_ENDPOINTS } from "../../../shared/http-client/infrastructure/ApiEndpoints";
import type { HttpClient } from "../../../shared/http-client/domain/HttpClient";
import { buildSearchParams } from "@/context/shared/search/infrastructure";
import { WIRE_MAX_LIMIT, type PageEnvelope } from "@/context/shared/search/domain";
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
  CreateBankAccountInput,
  UpdateBankAccountInput,
} from "../domain/BankAccountRepository";
import type {
  BankAccountCollectionPage,
  BankAccountCollectionRow,
} from "../domain/BankAccountCollectionRow";

interface BankAccountSearchResponse {
  data: BankAccountPrimitives[];
  pagination: PageEnvelope;
}

interface BankAccountCollectionResponse {
  data: BankAccountCollectionRow[];
  pagination: PageEnvelope;
}

interface BankAccountSingleResponse {
  data: BankAccountPrimitives;
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

const CURRENCIES: ReadonlySet<BankAccountCurrency> = new Set(["EUR"]);
const STATUSES: ReadonlySet<BankAccountStatus> = new Set(["ACTIVE", "INACTIVE", "CLOSED"]);

function isBankAccountPrimitives(value: unknown): value is BankAccountPrimitives {
  return (
    isObjectRecord(value) &&
    typeof value.id === "string" &&
    typeof value.holderName === "string" &&
    typeof value.iban === "string" &&
    isStringOrNull(value.bic) &&
    isStringOrNull(value.alias) &&
    typeof value.currency === "string" &&
    CURRENCIES.has(value.currency as BankAccountCurrency) &&
    typeof value.status === "string" &&
    STATUSES.has(value.status as BankAccountStatus)
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
 * Trust boundary for the detail/write resource (`GET|POST|PUT /bank-accounts`).
 * Unlike the list item it carries `bankId` and the ISO-8601 timestamps, so the
 * single-resource view is fully populated; the nested list projection omits them.
 */
function isBankAccountDetailPrimitives(value: unknown): value is BankAccountPrimitives {
  return (
    isObjectRecord(value) &&
    typeof value.bankId === "string" &&
    typeof value.createdAt === "string" &&
    typeof value.updatedAt === "string" &&
    isBankAccountPrimitives(value)
  );
}

export function isBankAccountSingleResponse(value: unknown): value is BankAccountSingleResponse {
  return isObjectRecord(value) && isBankAccountDetailPrimitives(value.data);
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

/**
 * Trust boundary for one row of the cross-bank collection view. Unlike the
 * nested list item it carries the owning bank's identity (`bankId`/`bankName`/
 * `bankShortName`, composed by the server JOIN) and drops the audit timestamps.
 */
function isBankAccountCollectionRow(value: unknown): value is BankAccountCollectionRow {
  return (
    isObjectRecord(value) &&
    typeof value.id === "string" &&
    typeof value.bankId === "string" &&
    typeof value.bankName === "string" &&
    typeof value.bankShortName === "string" &&
    typeof value.holderName === "string" &&
    typeof value.iban === "string" &&
    isStringOrNull(value.bic) &&
    isStringOrNull(value.alias) &&
    typeof value.currency === "string" &&
    CURRENCIES.has(value.currency as BankAccountCurrency) &&
    typeof value.status === "string" &&
    STATUSES.has(value.status as BankAccountStatus)
  );
}

/**
 * The collection adapter's trust boundary — the global `/bank-accounts` view.
 * Accepts ONLY the cursor-only envelope v2 (`hasNext`/`hasPrev`/`count`/`links`)
 * and requires each row to carry the owning bank's name, so a server that drops
 * the JOINed fields surfaces as a typed failure instead of a silent mismap.
 * Deliberately separate from {@link isBankAccountSearchResponse} (the per-bank
 * view): the two read views have distinct row contracts.
 */
export function isBankAccountCollectionResponse(
  value: unknown,
): value is BankAccountCollectionResponse {
  if (!isObjectRecord(value) || !Array.isArray(value.data)) {
    return false;
  }
  const { data, pagination } = value;
  if (!data.every(isBankAccountCollectionRow) || !isObjectRecord(pagination)) {
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
 * Maps a validated collection response to the domain page: rows plus the
 * envelope carried through verbatim (the `links` are server-composed and never
 * rebuilt). Each row is projected explicitly so the page never carries fields
 * outside the {@link BankAccountCollectionRow} contract; `bankName`/
 * `bankShortName` are preserved. Shared by the query and navigation adapters.
 */
export function toBankAccountCollectionPage(
  response: BankAccountCollectionResponse,
): BankAccountCollectionPage {
  return {
    rows: response.data.map((row) => ({
      id: row.id,
      bankId: row.bankId,
      bankName: row.bankName,
      bankShortName: row.bankShortName,
      holderName: row.holderName,
      iban: row.iban,
      bic: row.bic,
      alias: row.alias,
      currency: row.currency,
      status: row.status,
    })),
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

  async searchAll(criteria: BankAccountSearchCriteria): Promise<BankAccountCollectionPage> {
    // First page / query change only: filters + sort + limit, never a cursor.
    // Continuing to next/prev follows the server-composed `links` verbatim, so
    // navigation has one authority — the response envelope, never a client-built
    // cursor.
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
      `${API_ENDPOINTS.BACKOFFICE.BANK_ACCOUNTS.LIST}?${params.toString()}`,
      isBankAccountCollectionResponse,
    );

    return toBankAccountCollectionPage(response);
  }

  async find(id: string): Promise<BankAccount> {
    const response = await this.httpClient.get(
      API_ENDPOINTS.BACKOFFICE.BANK_ACCOUNTS.DETAILS(id),
      isBankAccountSingleResponse,
    );
    return BankAccount.fromPrimitives(response.data);
  }

  async create(input: CreateBankAccountInput): Promise<BankAccount> {
    // POST carries `bankId` in the body; status is omitted (the server defaults
    // it to ACTIVE — the create command never accepts a status).
    const response = await this.httpClient.post(
      API_ENDPOINTS.BACKOFFICE.BANK_ACCOUNTS.CREATE,
      {
        bankId: input.bankId,
        holderName: input.holderName,
        iban: input.iban,
        bic: input.bic,
        alias: input.alias,
        currency: input.currency,
      },
      isBankAccountSingleResponse,
    );
    return BankAccount.fromPrimitives(response.data);
  }

  async update(id: string, input: UpdateBankAccountInput): Promise<BankAccount> {
    // PUT keys off the account id; `bankId` is immutable and the lifecycle `status` is never sent —
    // it transitions through PATCH /status.
    const response = await this.httpClient.put(
      API_ENDPOINTS.BACKOFFICE.BANK_ACCOUNTS.UPDATE(id),
      {
        holderName: input.holderName,
        iban: input.iban,
        bic: input.bic,
        alias: input.alias,
        currency: input.currency,
      },
      isBankAccountSingleResponse,
    );
    return BankAccount.fromPrimitives(response.data);
  }

  async changeStatus(id: string, status: BankAccountStatus): Promise<BankAccount> {
    const response = await this.httpClient.patch(
      API_ENDPOINTS.BACKOFFICE.BANK_ACCOUNTS.CHANGE_STATUS(id),
      { status },
      isBankAccountSingleResponse,
    );
    return BankAccount.fromPrimitives(response.data);
  }

  async delete(id: string): Promise<void> {
    await this.httpClient.delete(API_ENDPOINTS.BACKOFFICE.BANK_ACCOUNTS.DELETE(id));
  }
}
