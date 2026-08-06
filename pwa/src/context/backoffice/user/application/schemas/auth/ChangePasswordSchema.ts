import { z } from "zod";
import { existingPasswordSchema, newPasswordSchema } from "./passwordPolicy";

/**
 * Self-service credential change: prove ownership with the current password, then set
 * a new one. Both fields come from `passwordPolicy`, so the client and the API agree on
 * the same limits and the same counting unit, and `.max()` (never an input `maxLength`)
 * is what surfaces an over-length paste instead of silently truncating it.
 *
 * The current password carries no policy beyond presence and the API's own ceiling: its
 * real verification is the server's, and applying the policy's floor to it would tell the
 * user their existing credential is "too short" when the only thing wrong is that it does
 * not match.
 *
 * "The new password must differ from the current one" is deliberately absent — the
 * server owns that rule and answers `new-password-must-differ`, which the form hangs
 * on the field. Restating it here would fork the policy.
 */
export const ChangePasswordSchema = z.object({
  currentPassword: existingPasswordSchema,
  newPassword: newPasswordSchema,
});
export type ChangePasswordFormValues = z.infer<typeof ChangePasswordSchema>;
