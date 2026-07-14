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
    .min(PASSWORD_MIN_LENGTH, "La contraseña debe tener al menos 8 caracteres.")
    .max(PASSWORD_MAX_LENGTH, "La contraseña no puede superar los 128 caracteres."),
});
export type ResetPasswordFormValues = z.infer<typeof ResetPasswordSchema>;
