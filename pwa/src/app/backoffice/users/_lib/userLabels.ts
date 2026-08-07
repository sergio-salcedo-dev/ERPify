import { Role } from "@/context/shared/access/domain/Role";
import { UserStatus } from "@/context/shared/access/domain/UserStatus";

/**
 * Display labels for the access enums shown across the user UI (form, filters,
 * badges). Single source of truth with exhaustive `Record` typing, so adding a
 * `Role`/`UserStatus` fails the build until its label is supplied.
 */
export const ROLE_LABEL: Record<Role, string> = {
  [Role.VIEWER]: "Viewer",
  [Role.EDITOR]: "Editor",
  [Role.MANAGER]: "Manager",
  [Role.ADMIN]: "Admin",
  [Role.AUDIT_READER]: "Audit Reader",
};

export const STATUS_LABEL: Record<UserStatus, string> = {
  [UserStatus.INVITED]: "Invited",
  [UserStatus.ACTIVE]: "Active",
  [UserStatus.SUSPENDED]: "Suspended",
  [UserStatus.DEACTIVATED]: "Deactivated",
  [UserStatus.REVOKED]: "Revoked",
};
