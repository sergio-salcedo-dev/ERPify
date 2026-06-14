import type { CrudRepository } from "@/context/shared/resource/domain/CrudRepository";
import type { UserStatus } from "@/context/shared/access/domain/UserStatus";
import type { Role } from "@/context/shared/access/domain/Role";
import type { Permission } from "@/context/shared/access/domain/Permission";
import type { User } from "./User";

export interface UserInput {
  email: string;
  roles: Role[];
  status: UserStatus;
  permissions?: Permission[];
}

export type UserRepository = CrudRepository<User, UserInput>;
