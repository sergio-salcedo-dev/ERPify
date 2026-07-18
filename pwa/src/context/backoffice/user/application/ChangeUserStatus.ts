import { inject, injectable } from "inversify";
import type { UserStatus } from "@/context/shared/access/domain/UserStatus";
import type { User } from "../domain/User";
import type { ChangeUserStatusRepository } from "../domain/ChangeUserStatusRepository";

/**
 * Transitions an identity's lifecycle status through the dedicated identity-shaped port, kept separate from any
 * descriptive edit so a status change is an explicit intent. Resolves to the transitioned {@link User}.
 */
@injectable()
export class ChangeUserStatus {
  constructor(
    @inject("BackOfficeChangeUserStatusRepository")
    private readonly repository: ChangeUserStatusRepository,
  ) {}

  async run(id: string, status: UserStatus): Promise<User> {
    return this.repository.changeStatus(id, status);
  }
}
