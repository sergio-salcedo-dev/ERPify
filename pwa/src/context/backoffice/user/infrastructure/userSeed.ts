import type { UserPrimitives } from "../domain/User";
import { UserStatus } from "@/context/shared/access/domain/UserStatus";
import { Role } from "@/context/shared/access/domain/Role";
import { Permission } from "@/context/shared/access/domain/Permission";

const STATUSES = [UserStatus.ACTIVE, UserStatus.PENDING, UserStatus.BLOCKED];
const ROLES = [Role.ADMIN, Role.EMPLOYEE, Role.CUSTOMER, Role.SUPPLIER, Role.SUPER_ADMIN];

/** Fixed-id deterministic seed (uuid v7-shaped strings) so tests/snapshots are stable. */
export const userSeed: UserPrimitives[] = Array.from({ length: 25 }, (_, i) => {
  const n = String(i + 1).padStart(3, "0");
  const role = ROLES[i % ROLES.length];
  const status = STATUSES[i % STATUSES.length];
  const created = `2026-0${(i % 6) + 1}-1${i % 9}T09:0${i % 6}:00.000Z`;
  return {
    id: `00000000-0000-7000-8000-0000000${n}00`,
    email: `user${n}@erpify.dev`,
    status,
    roles: [role],
    permissions:
      role === Role.ADMIN
        ? [Permission.USERS_READ, Permission.USERS_WRITE]
        : [Permission.USERS_READ],
    createdAt: created,
    updatedAt: created,
  };
});
