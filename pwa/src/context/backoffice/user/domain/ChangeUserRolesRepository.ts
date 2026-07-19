import type { Role } from "@/context/shared/access/domain/Role";
import type { User } from "./User";

/**
 * Identity-shaped write port for the authorization map, the sibling of `ChangeUserStatusRepository`: the
 * console re-grants roles through a dedicated use case, never the generic `CrudRepository.update()` (which stays
 * a typed no-op — identity administration is not CRUD). `roles` is the COMPLETE target set, not a delta, so the
 * same set is a legitimate no-op. Resolves to the re-granted {@link User} so the detail reflects it in place.
 */
export interface ChangeUserRolesRepository {
  changeRoles(id: string, roles: Role[]): Promise<User>;
}
