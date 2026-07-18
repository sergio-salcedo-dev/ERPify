import { describe, expect, it, vi } from "vitest";
import { InviteUser } from "@/context/backoffice/user/application/InviteUser";
import { Role } from "@/context/shared/access/domain/Role";
import type { InviteUserRepository } from "@/context/backoffice/user/domain/InviteUserRepository";

describe("InviteUser", () => {
  it("delegates the invitation to the repository", async () => {
    const invite = vi.fn().mockResolvedValue(undefined);
    const repository: InviteUserRepository = { invite };
    const input = { email: "newbie@erpify.test", roles: [Role.MANAGER] };

    await new InviteUser(repository).run(input);

    expect(invite).toHaveBeenCalledWith(input);
  });
});
