import { injectable, inject } from "inversify";
import { API_ENDPOINTS } from "../../../shared/http-client/infrastructure/ApiEndpoints";
import type { HttpClient } from "../../../shared/http-client/domain/HttpClient";
import { HealthCheck, HealthCheckData } from "../domain/HealthCheck";
import type { HealthCheckRepository } from "../domain/HealthCheckRepository";

@injectable()
export class ApiHealthCheckRepository implements HealthCheckRepository {
  constructor(@inject("HttpClient") private readonly httpClient: HttpClient) {}

  async check(): Promise<HealthCheck> {
    const response = await this.httpClient.get<{ data: HealthCheckData }>(
      API_ENDPOINTS.BACKOFFICE.HEALTH,
    );
    return HealthCheck.fromPrimitives(response.data);
  }
}
