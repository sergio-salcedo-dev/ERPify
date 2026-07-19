import { describe, expect, it, vi } from "vitest";
import { ChangeUserRoles } from "@/context/backoffice/user/application/ChangeUserRoles";
import { User } from "@/context/backoffice/user/domain/User";
import { UserStatus } from "@/context/shared/access/domain/UserStatus";
import { Role } from "@/context/shared/access/domain/Role";
import type { ChangeUserRolesRepository } from "@/context/backoffice/user/domain/ChangeUserRolesRepository";

describe("ChangeUserRoles", () => {
  it("delegates the complete role set to the repository and returns the re-granted user", async () => {
    const regranted = User.fromPrimitives({
      id: "0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5c",
      email: "mallory@erpify.test",
      status: UserStatus.ACTIVE,
      roles: [Role.MANAGER],
      createdAt: "2026-01-01T00:00:00+00:00",
      updatedAt: "2026-01-02T00:00:00+00:00",
    });
    const changeRoles = vi.fn().mockResolvedValue(regranted);
    const repository: ChangeUserRolesRepository = { changeRoles };

    const result = await new ChangeUserRoles(repository).run(regranted.id, [Role.MANAGER]);

    expect(changeRoles).toHaveBeenCalledWith(regranted.id, [Role.MANAGER]);
    expect(result).toBe(regranted);
  });
});
