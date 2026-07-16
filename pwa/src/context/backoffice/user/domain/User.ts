import type { UserStatus } from "@/context/shared/access/domain/UserStatus";
import type { Role } from "@/context/shared/access/domain/Role";

export interface UserPrimitives {
  id: string;
  email: string;
  status: UserStatus;
  roles: Role[];
  createdAt: string;
  updatedAt: string;
}

/**
 * Identity aggregate (auth/authorization only — never a business entity). The
 * read-side wire contract is `{id, email, status, roles, createdAt, updatedAt}`:
 * the roles ARE the authorization map, so there is no `permissions` member — the
 * API never returns one (there is no `User.permissions`).
 */
export class User {
  constructor(
    public readonly id: string,
    public readonly email: string,
    public readonly status: UserStatus,
    public readonly roles: Role[],
    public readonly createdAt: string,
    public readonly updatedAt: string,
  ) {}

  static fromPrimitives(d: UserPrimitives): User {
    return new User(d.id, d.email, d.status, d.roles, d.createdAt, d.updatedAt);
  }
}
