import { z } from "zod";
import { PASSWORD_MAX_LENGTH, PASSWORD_MIN_LENGTH } from "./passwordPolicy";

/**
 * Self-service credential change: prove ownership with the current password, then set
 * a new one. The new password reuses the shared bounds so the client and the API agree
 * on the same limits, and `.max()` (never an input `maxLength`) is what surfaces an
 * over-length paste instead of silently truncating it.
 *
 * The current password carries no minimum beyond presence: its real verification is
 * the server's, and applying the password policy's floor to it would tell the user
 * their existing credential is "too short" when the only thing wrong is that it does
 * not match. The ceiling is a different claim and is mirrored, because the API caps
 * that field too — without it an over-length paste buys a round trip to learn what
 * the form already knows.
 *
 * "The new password must differ from the current one" is deliberately absent — the
 * server owns that rule and answers `new-password-must-differ`, which the form hangs
 * on the field. Restating it here would fork the policy.
 */
/** Mirrors the API's ceiling on the credential being replaced, which predates the 128 policy. */
const EXISTING_PASSWORD_MAX_LENGTH = 255;

export const ChangePasswordSchema = z.object({
  currentPassword: z
    .string()
    .min(1, "Enter your current password.")
    .max(EXISTING_PASSWORD_MAX_LENGTH, "The password must not exceed 255 characters."),
  newPassword: z
    .string()
    .min(PASSWORD_MIN_LENGTH, "The password must be at least 8 characters.")
    .max(PASSWORD_MAX_LENGTH, "The password must not exceed 128 characters."),
});
export type ChangePasswordFormValues = z.infer<typeof ChangePasswordSchema>;
