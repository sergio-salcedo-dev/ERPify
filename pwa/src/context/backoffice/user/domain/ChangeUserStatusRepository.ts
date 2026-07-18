import type { UserStatus } from "@/context/shared/access/domain/UserStatus";
import type { User } from "./User";

/**
 * Identity-shaped write port for the lifecycle transition, the sibling of {@link InviteUserRepository}: the
 * console suspends/deactivates through a dedicated use case, never the generic `CrudRepository.update()` (which
 * stays a typed no-op — identity administration is not CRUD). `changeStatus` resolves to the transitioned
 * {@link User} so the detail surface can reflect the new status in place.
 */
export interface ChangeUserStatusRepository {
  changeStatus(id: string, status: UserStatus): Promise<User>;
}
