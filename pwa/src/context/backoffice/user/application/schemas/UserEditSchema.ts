import { z } from "zod";
import { Role } from "@/context/shared/access/domain/Role";
import { Permission } from "@/context/shared/access/domain/Permission";
import { UserStatus } from "@/context/shared/access/domain/UserStatus";

export const UserEditSchema = z.object({
  roles: z.array(z.enum(Role)).min(1, "Select at least one role."),
  status: z.enum(UserStatus),
  permissions: z.array(z.enum(Permission)).default([]),
});
export type UserEditFormValues = z.infer<typeof UserEditSchema>;
