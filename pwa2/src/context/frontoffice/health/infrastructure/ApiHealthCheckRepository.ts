import { injectable, inject } from "inversify";
import type { HttpClient } from "../../../shared/infrastructure/HttpClient/HttpClient";
import { HealthCheck, HealthCheckData } from "../domain/HealthCheck";
import type { HealthCheckRepository } from "../domain/HealthCheckRepository";

@injectable()
export class ApiHealthCheckRepository implements HealthCheckRepository {
  constructor(@inject("HttpClient") private readonly httpClient: HttpClient) {}

  async check(): Promise<HealthCheck> {
    const response = await this.httpClient.get<HealthCheckData>("/api/frontoffice/health");
    return HealthCheck.fromPrimitives(response);
  }
}
