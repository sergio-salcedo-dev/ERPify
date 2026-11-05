import { z } from "zod";
import { existingPasswordSchema } from "./passwordPolicy";

/**
 * The confirmation step in front of an authority-changing write proves ownership with the credential that
 * already exists, so it reuses `existingPasswordSchema` — presence and the API's ceiling, never the
 * new-password policy. Applying the policy's floor here would tell the user their working password is "too
 * short" when the only thing that can be wrong is that it does not match.
 */
export const ConfirmCurrentPasswordSchema = z.object({
  currentPassword: existingPasswordSchema,
});
export type ConfirmCurrentPasswordFormValues = z.infer<typeof ConfirmCurrentPasswordSchema>;
