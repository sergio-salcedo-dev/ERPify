import { describe, expect, it, vi } from "vitest";
import { ChangeUserStatus } from "@/context/backoffice/user/application/ChangeUserStatus";
import { User } from "@/context/backoffice/user/domain/User";
import { UserStatus } from "@/context/shared/access/domain/UserStatus";
import type { ChangeUserStatusRepository } from "@/context/backoffice/user/domain/ChangeUserStatusRepository";

describe("ChangeUserStatus", () => {
  it("delegates the transition to the repository and returns the transitioned user", async () => {
    const suspended = User.fromPrimitives({
      id: "0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5c",
      email: "mallory@erpify.test",
      status: UserStatus.SUSPENDED,
      roles: [],
      createdAt: "2026-01-01T00:00:00+00:00",
      updatedAt: "2026-01-02T00:00:00+00:00",
    });
    const changeStatus = vi.fn().mockResolvedValue(suspended);
    const repository: ChangeUserStatusRepository = { changeStatus };

    const result = await new ChangeUserStatus(repository).run(suspended.id, UserStatus.SUSPENDED);

    expect(changeStatus).toHaveBeenCalledWith(suspended.id, UserStatus.SUSPENDED);
    expect(result).toBe(suspended);
  });
});
