import type { UserStatus } from "@/context/shared/access/domain/UserStatus";
import type { Role } from "@/context/shared/access/domain/Role";
import type { Permission } from "@/context/shared/access/domain/Permission";

export interface UserPrimitives {
  id: string;
  email: string;
  status: UserStatus;
  roles: Role[];
  permissions: Permission[];
  createdAt: string;
  updatedAt: string;
}

/** Identity aggregate (auth/authorization only — never a business entity). */
export class User {
  constructor(
    public readonly id: string,
    public readonly email: string,
    public readonly status: UserStatus,
    public readonly roles: Role[],
    public readonly permissions: Permission[],
    public readonly createdAt: string,
    public readonly updatedAt: string,
  ) {}

  static fromPrimitives(d: UserPrimitives): User {
    return new User(d.id, d.email, d.status, d.roles, d.permissions, d.createdAt, d.updatedAt);
  }
}
