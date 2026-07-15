import { z } from "zod";
import { PASSWORD_MAX_LENGTH, PASSWORD_MIN_LENGTH } from "./passwordPolicy";

/**
 * Accepting an invitation sets a single new credential. There is no confirm
 * field: the UX pairs one password input with an always-visible reveal toggle,
 * which removes the mistyped-password risk a confirm field guards against. The
 * bounds silently truncating nothing — the limit lives in `.max()` so an
 * over-length paste surfaces the same "must not exceed" error the API returns.
 */
export const AcceptInvitationSchema = z.object({
  password: z
    .string()
    .min(PASSWORD_MIN_LENGTH, "The password must be at least 8 characters.")
    .max(PASSWORD_MAX_LENGTH, "The password must not exceed 128 characters."),
});
export type AcceptInvitationFormValues = z.infer<typeof AcceptInvitationSchema>;
