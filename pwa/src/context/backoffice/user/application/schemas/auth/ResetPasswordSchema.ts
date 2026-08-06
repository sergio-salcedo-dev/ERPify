import { z } from "zod";
import { newPasswordSchema } from "./passwordPolicy";

/**
 * Resetting a password sets a single new credential. There is no confirm field:
 * the UX pairs one password input with an always-visible reveal toggle, which
 * removes the mistyped-password risk a confirm field guards against. The policy
 * comes from `passwordPolicy` so an over-length paste surfaces the same "must not
 * exceed" error the API returns rather than being silently truncated.
 */
export const ResetPasswordSchema = z.object({
  password: newPasswordSchema,
});
export type ResetPasswordFormValues = z.infer<typeof ResetPasswordSchema>;
