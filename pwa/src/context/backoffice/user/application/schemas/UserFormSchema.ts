import { z } from "zod";
import { Permission } from "@/context/shared/access/domain/Permission";
import { UserCreateSchema } from "./UserCreateSchema";

/**
 * The unified create/edit form schema: the create fields (email, roles, status)
 * plus an optional `permissions` set (edited only in update mode; defaults to
 * empty on create). One schema keeps a single `useZodForm` call across both
 * modes; `UserCreateSchema`/`UserEditSchema` remain the API-contract schemas.
 */
export const UserFormSchema = UserCreateSchema.extend({
  permissions: z.array(z.enum(Permission)).default([]),
});
export type UserFormValues = z.infer<typeof UserFormSchema>;
