import { z } from "zod";
import { Role } from "@/context/shared/access/domain/Role";

/**
 * Role re-grant form: the complete target set, never a delta. At least one role — an identity with no role
 * holds no authorization at all, which the API rejects too. The message mirrors the API's 422 string (the
 * request DTO's `#[Assert\Count]`), so one set of assertions covers both ends and a server violation maps
 * cleanly onto the `roles` field.
 */
export const ChangeUserRolesSchema = z.object({
  roles: z.array(z.enum(Role)).min(1, "Select at least one role."),
});
export type ChangeUserRolesFormValues = z.infer<typeof ChangeUserRolesSchema>;
