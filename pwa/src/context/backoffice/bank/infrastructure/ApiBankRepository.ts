import { inject, injectable } from "inversify";
import { API_ENDPOINTS } from "../../../shared/infrastructure/api/ApiEndpoints";
import type { HttpClient } from "../../../shared/infrastructure/HttpClient/HttpClient";
import { buildSearchParams } from "@/context/shared/infrastructure/Search";
import { Bank, type BankPrimitives } from "../domain/Bank";
import type {
  BankInput,
  BankRepository,
  BankSearchCriteria,
  BankSearchPage,
} from "../domain/BankRepository";

interface BankSearchResponse {
  data: BankPrimitives[];
  pagination: {
    currentPage: number;
    pageCount: number | null;
    count: number | null;
    hasMorePages: boolean;
    cursor: string;
  };
}

interface BankSingleResponse {
  data: BankPrimitives;
}

function isObjectRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === "object" && value !== null;
}

function isBankPrimitives(value: unknown): value is BankPrimitives {
  return (
    isObjectRecord(value) &&
    typeof value.id === "string" &&
    typeof value.name === "string" &&
    typeof value.shortName === "string" &&
    typeof value.createdAt === "string" &&
    typeof value.updatedAt === "string"
  );
}

function isNumberOrNull(value: unknown): value is number | null {
  return value === null || typeof value === "number";
}

export function isBankSearchResponse(value: unknown): value is BankSearchResponse {
  if (!isObjectRecord(value) || !Array.isArray(value.data)) {
    return false;
  }
  const { data, pagination } = value;
  return (
    data.every(isBankPrimitives) &&
    isObjectRecord(pagination) &&
    typeof pagination.currentPage === "number" &&
    isNumberOrNull(pagination.pageCount) &&
    isNumberOrNull(pagination.count) &&
    typeof pagination.hasMorePages === "boolean" &&
    typeof pagination.cursor === "string"
  );
}

export function isBankSingleResponse(value: unknown): value is BankSingleResponse {
  return isObjectRecord(value) && isBankPrimitives(value.data);
}

@injectable()
export class ApiBankRepository implements BankRepository {
  constructor(@inject("HttpClient") private readonly httpClient: HttpClient) {}

  async search(criteria: BankSearchCriteria): Promise<BankSearchPage> {
    const params = buildSearchParams(criteria.filters);
    if (criteria.sort) {
      params.append("sort", criteria.sort.field);
      // The API sort enum is uppercase (`ASC`/`DESC`); the PWA enum is lowercase.
      params.append("direction", criteria.sort.direction.toUpperCase());
    }
    params.append("page", String(criteria.page));
    if (criteria.cursor !== undefined) {
      params.append("cursor", criteria.cursor);
    }
    params.append("limit", String(criteria.limit));
    // No `paginationMode` → the API defaults to LIGHT: it skips the COUNT(*) and
    // reports `hasMorePages` via a single fetch. The banks list navigates by
    // cursor (prev/next) and shows no total, so the extra count is pure cost.

    const response = await this.httpClient.get(
      `${API_ENDPOINTS.BACKOFFICE.BANKS.LIST}?${params.toString()}`,
      isBankSearchResponse,
    );

    return {
      banks: response.data.map(Bank.fromPrimitives),
      cursor: response.pagination.cursor,
      currentPage: response.pagination.currentPage,
      hasMorePages: response.pagination.hasMorePages,
    };
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
      isBankSingleResponse,
    );
    return Bank.fromPrimitives(response.data);
  }

  async update(id: string, input: BankInput): Promise<Bank> {
    const response = await this.httpClient.put(
      API_ENDPOINTS.BACKOFFICE.BANKS.UPDATE(id),
      input,
      isBankSingleResponse,
    );
    return Bank.fromPrimitives(response.data);
  }

  async delete(id: string): Promise<void> {
    await this.httpClient.delete(API_ENDPOINTS.BACKOFFICE.BANKS.DELETE(id));
  }
}
