import { inject, injectable } from "inversify";
import { ApiRoutes } from "../../../shared/infrastructure/ApiRoutes";
import type { HttpClient } from "../../../shared/infrastructure/HttpClient/HttpClient";
import { Bank, type BankPrimitives } from "../domain/Bank";
import type { BankInput, BankRepository, BankSearchPage } from "../domain/BankRepository";

interface BankSearchResponse {
  data: BankPrimitives[];
  meta?: { nextCursor?: string };
}

interface BankSingleResponse {
  data: BankPrimitives;
}

@injectable()
export class ApiBankRepository implements BankRepository {
  constructor(@inject("HttpClient") private readonly httpClient: HttpClient) {}

  async search(): Promise<BankSearchPage> {
    const response = await this.httpClient.get<BankSearchResponse>(
      ApiRoutes.v1.backoffice.banks.list,
    );
    return {
      banks: response.data.map(Bank.fromPrimitives),
      nextCursor: response.meta?.nextCursor,
    };
  }

  async find(id: string): Promise<Bank> {
    const response = await this.httpClient.get<BankSingleResponse>(
      ApiRoutes.v1.backoffice.banks.byId(id),
    );
    return Bank.fromPrimitives(response.data);
  }

  async create(input: BankInput): Promise<Bank> {
    const response = await this.httpClient.post<BankInput, BankSingleResponse>(
      ApiRoutes.v1.backoffice.banks.list,
      input,
    );
    return Bank.fromPrimitives(response.data);
  }

  async update(id: string, input: BankInput): Promise<Bank> {
    const response = await this.httpClient.put<BankInput, BankSingleResponse>(
      ApiRoutes.v1.backoffice.banks.byId(id),
      input,
    );
    return Bank.fromPrimitives(response.data);
  }

  async delete(id: string): Promise<void> {
    await this.httpClient.delete(ApiRoutes.v1.backoffice.banks.byId(id));
  }
}
