import type { UserStatus } from "@/context/shared/access/domain/UserStatus";
import type { User } from "./User";

/**
 * Identity-shaped write port for the lifecycle transition, the sibling of {@link InviteUserRepository}: the
 * console suspends/deactivates through a dedicated use case, never the generic `CrudRepository.update()` (which
 * stays a typed no-op — identity administration is not CRUD). `changeStatus` resolves to the transitioned
 * {@link User} so the detail surface can reflect the new status in place. The transition is proved with the
 * CALLER's current password, so a borrowed session cannot suspend or retire another identity on its own; a wrong
 * password rejects with `invalid-current-password`.
 */
export interface ChangeUserStatusRepository {
  changeStatus(id: string, status: UserStatus, currentPassword: string): Promise<User>;
}
