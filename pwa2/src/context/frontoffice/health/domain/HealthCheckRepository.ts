import { HealthCheck } from "./HealthCheck";

export interface HealthCheckRepository {
  check(): Promise<HealthCheck>;
}
