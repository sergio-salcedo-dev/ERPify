import { inject, injectable } from "inversify";
import type { Role } from "@/context/shared/access/domain/Role";
import type { User } from "../domain/User";
import type { ChangeUserRolesRepository } from "../domain/ChangeUserRolesRepository";

/**
 * Re-grants an identity's roles through the dedicated identity-shaped port, kept separate from the lifecycle
 * transition so authorization and status are explicit, independent intents. `roles` is the complete target set.
 * Resolves to the re-granted {@link User}.
 */
@injectable()
export class ChangeUserRoles {
  constructor(
    @inject("BackOfficeChangeUserRolesRepository")
    private readonly repository: ChangeUserRolesRepository,
  ) {}

  async run(id: string, roles: Role[]): Promise<User> {
    return this.repository.changeRoles(id, roles);
  }
}
