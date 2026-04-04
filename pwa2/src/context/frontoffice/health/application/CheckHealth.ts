import { injectable, inject } from "inversify";
import { HealthCheck } from "../domain/HealthCheck";
import type { HealthCheckRepository } from "../domain/HealthCheckRepository";

@injectable()
export class CheckHealth {
  constructor(
    @inject("FrontOfficeHealthCheckRepository") private readonly repository: HealthCheckRepository,
  ) {}

  async run(): Promise<HealthCheck> {
    return this.repository.check();
  }
}
