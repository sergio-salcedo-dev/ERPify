import { describe, it, expect, vi } from "vitest";
import { CheckHealth } from "@/context/backoffice/health/application/CheckHealth";
import { HealthCheck } from "@/context/backoffice/health/domain/HealthCheck";
import { HealthCheckRepository } from "@/context/backoffice/health/domain/HealthCheckRepository";

describe("CheckHealth Use Case (BackOffice)", () => {
  it("should return a health check from the repository", async () => {
    const mockHealthCheck = new HealthCheck("ok", "Back Office", "2026-04-04T12:00:00Z");
    const mockRepository: HealthCheckRepository = {
      check: vi.fn().mockResolvedValue(mockHealthCheck),
    };

    const useCase = new CheckHealth(mockRepository);
    const result = await useCase.run();

    expect(result).toEqual(mockHealthCheck);
    expect(mockRepository.check).toHaveBeenCalledTimes(1);
  });
});
