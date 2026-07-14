import { z } from "zod";
import { USER_EMAIL_MAX_LENGTH } from "../UserCreateSchema";

/**
 * Requesting a reset link takes only the email. Copy is Spanish to match the
 * identity/access design spine (the reset flow reuses the Spanish access wall
 * and security signal). The limit lives in `.max()` so an over-length paste
 * surfaces the "must not exceed" error rather than being silently truncated.
 */
export const ForgotPasswordSchema = z.object({
  email: z
    .string({ error: "El correo electrónico es obligatorio." })
    .trim()
    .min(1, "El correo electrónico es obligatorio.")
    .max(USER_EMAIL_MAX_LENGTH, "El correo electrónico no puede superar los 255 caracteres.")
    .email("Introduce un correo electrónico válido."),
});
export type ForgotPasswordFormValues = z.infer<typeof ForgotPasswordSchema>;
