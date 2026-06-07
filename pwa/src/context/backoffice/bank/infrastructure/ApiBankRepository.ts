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

@injectable()
export class ApiBankRepository implements BankRepository {
  constructor(@inject("HttpClient") private readonly httpClient: HttpClient) {}

  async search(): Promise<BankSearchPage> {
    const response = await this.httpClient.get<BankSearchResponse>(
      API_ENDPOINTS.BACKOFFICE.BANKS.LIST,
    );
    return {
      banks: response.data.map(Bank.fromPrimitives),
      nextCursor: response.pagination.hasMorePages ? response.pagination.cursor : undefined,
    };
  }

  async find(id: string): Promise<Bank> {
    const response = await this.httpClient.get<BankSingleResponse>(
      API_ENDPOINTS.BACKOFFICE.BANKS.DETAILS(id),
    );
    return Bank.fromPrimitives(response.data);
  }

  async create(input: BankInput): Promise<Bank> {
    const response = await this.httpClient.post<BankInput, BankSingleResponse>(
      API_ENDPOINTS.BACKOFFICE.BANKS.CREATE,
      input,
    );
    return Bank.fromPrimitives(response.data);
  }

  async update(id: string, input: BankInput): Promise<Bank> {
    const response = await this.httpClient.put<BankInput, BankSingleResponse>(
      API_ENDPOINTS.BACKOFFICE.BANKS.UPDATE(id),
      input,
    );
    return Bank.fromPrimitives(response.data);
  }

  async delete(id: string): Promise<void> {
    await this.httpClient.delete(API_ENDPOINTS.BACKOFFICE.BANKS.DELETE(id));
  }
}
