import { inject, injectable } from "inversify";
import { API_ENDPOINTS } from "../../../shared/infrastructure/api/ApiEndpoints";
import type { HttpClient } from "../../../shared/infrastructure/HttpClient/HttpClient";
import type { BankCountReader } from "../domain/BankCountReader";

interface BankCountResponse {
  total: number;
}

function isObjectRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === "object" && value !== null;
}

// `total` is a count: a non-negative integer. `typeof NaN === "number"` and
// floats/negatives would otherwise pass the boundary, so the guard rejects them
// here rather than letting a bogus value render in the header.
export function isBankCountResponse(value: unknown): value is BankCountResponse {
  return (
    isObjectRecord(value) &&
    typeof value.total === "number" &&
    Number.isInteger(value.total) &&
    value.total >= 0
  );
}

/**
 * Reads the banks total from the projection endpoint. The response is a plain
 * `{ total }` object — NOT the `{ data }` envelope the search/single reads use —
 * so the guard validates `total` directly.
 */
@injectable()
export class ApiBankCountReader implements BankCountReader {
  constructor(@inject("HttpClient") private readonly httpClient: HttpClient) {}

  async read(): Promise<number> {
    const response = await this.httpClient.get(
      API_ENDPOINTS.BACKOFFICE.BANKS.COUNT,
      isBankCountResponse,
    );
    return response.total;
  }
}
