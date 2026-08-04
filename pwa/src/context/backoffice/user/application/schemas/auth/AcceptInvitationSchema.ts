import { z } from "zod";
import { newPasswordSchema } from "./passwordPolicy";

/**
 * Accepting an invitation sets a single new credential. There is no confirm
 * field: the UX pairs one password input with an always-visible reveal toggle,
 * which removes the mistyped-password risk a confirm field guards against. The
 * policy comes from `passwordPolicy`, truncating nothing — an over-length paste
 * surfaces the same "must not exceed" error the API returns.
 */
export const AcceptInvitationSchema = z.object({
  password: newPasswordSchema,
});
export type AcceptInvitationFormValues = z.infer<typeof AcceptInvitationSchema>;
