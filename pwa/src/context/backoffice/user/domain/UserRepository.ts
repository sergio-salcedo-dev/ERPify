import type { CrudRepository } from "@/context/shared/resource/domain/CrudRepository";
import type { UserStatus } from "@/context/shared/access/domain/UserStatus";
import type { Role } from "@/context/shared/access/domain/Role";
import type { User } from "./User";

/**
 * Carries no permissions: a user's permissions are derived from their roles by the authorization policy and
 * never stored, so they are not something a caller can supply.
 */
export interface UserInput {
  email: string;
  roles: Role[];
  status: UserStatus;
}

export type UserRepository = CrudRepository<User, UserInput>;
