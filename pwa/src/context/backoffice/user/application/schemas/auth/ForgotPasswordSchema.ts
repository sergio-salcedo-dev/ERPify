import { z } from "zod";
import { USER_EMAIL_MAX_LENGTH } from "../UserCreateSchema";

/**
 * Requesting a reset link takes only the email. The limit lives in `.max()` so
 * an over-length paste surfaces the "must not exceed" error rather than being
 * silently truncated. Messages mirror the login schema so one set of UI
 * assertions covers both surfaces.
 */
export const ForgotPasswordSchema = z.object({
  email: z
    .string({ error: "The email field is required." })
    .trim()
    .min(1, "The email field is required.")
    .max(USER_EMAIL_MAX_LENGTH, "The email must not exceed 255 characters.")
    .email("Enter a valid email address."),
});
export type ForgotPasswordFormValues = z.infer<typeof ForgotPasswordSchema>;
