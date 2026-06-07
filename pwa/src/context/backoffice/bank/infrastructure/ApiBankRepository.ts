import { inject, injectable } from "inversify";
import { API_ENDPOINTS } from "../../../shared/infrastructure/api/ApiEndpoints";
import type { HttpClient } from "../../../shared/infrastructure/HttpClient/HttpClient";
import { Bank, type BankPrimitives } from "../domain/Bank";
import type { BankInput, BankRepository, BankSearchPage } from "../domain/BankRepository";

interface BankSearchResponse {
  data: BankPrimitives[];
  pagination: {
    cursor: string;
    hasMorePages: boolean;
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

export function isBankSearchResponse(value: unknown): value is BankSearchResponse {
  if (!isObjectRecord(value) || !Array.isArray(value.data)) {
    return false;
  }
  const { data, pagination } = value;
  return (
    data.every(isBankPrimitives) &&
    isObjectRecord(pagination) &&
    typeof pagination.cursor === "string" &&
    typeof pagination.hasMorePages === "boolean"
  );
}

export function isBankSingleResponse(value: unknown): value is BankSingleResponse {
  return isObjectRecord(value) && isBankPrimitives(value.data);
}

@injectable()
export class ApiBankRepository implements BankRepository {
  constructor(@inject("HttpClient") private readonly httpClient: HttpClient) {}

  async search(): Promise<BankSearchPage> {
    const response = await this.httpClient.get(
      API_ENDPOINTS.BACKOFFICE.BANKS.LIST,
      isBankSearchResponse,
    );
    return {
      banks: response.data.map(Bank.fromPrimitives),
      nextCursor: response.pagination.hasMorePages ? response.pagination.cursor : undefined,
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
