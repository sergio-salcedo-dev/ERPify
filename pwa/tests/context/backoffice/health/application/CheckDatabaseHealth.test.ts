import { describe, it, expect, vi } from "vitest";
import { CheckDatabaseHealth } from "@/context/backoffice/health/application/CheckDatabaseHealth";
import { HealthCheck } from "@/context/backoffice/health/domain/HealthCheck";
import { HealthCheckRepository } from "@/context/backoffice/health/domain/HealthCheckRepository";

describe("CheckDatabaseHealth Use Case (BackOffice)", () => {
  it("should return the database health check from the repository", async () => {
    const mockHealthCheck = new HealthCheck("ok", "Database", "2026-04-04T12:00:00Z");
    const mockRepository: HealthCheckRepository = {
      check: vi.fn().mockResolvedValue(mockHealthCheck),
    };

    const useCase = new CheckDatabaseHealth(mockRepository);
    const result = await useCase.run();

    expect(result).toEqual(mockHealthCheck);
    expect(mockRepository.check).toHaveBeenCalledTimes(1);
  });
});
