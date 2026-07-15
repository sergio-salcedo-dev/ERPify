import { z } from "zod";
import { PASSWORD_MAX_LENGTH, PASSWORD_MIN_LENGTH } from "./passwordPolicy";

/**
 * Resetting a password sets a single new credential. There is no confirm field:
 * the UX pairs one password input with an always-visible reveal toggle, which
 * removes the mistyped-password risk a confirm field guards against. The limit
 * lives in `.max()` so an over-length paste surfaces the same "must not exceed"
 * error the API returns rather than being silently truncated.
 */
export const ResetPasswordSchema = z.object({
  password: z
    .string()
    .min(PASSWORD_MIN_LENGTH, "The password must be at least 8 characters.")
    .max(PASSWORD_MAX_LENGTH, "The password must not exceed 128 characters."),
});
export type ResetPasswordFormValues = z.infer<typeof ResetPasswordSchema>;
