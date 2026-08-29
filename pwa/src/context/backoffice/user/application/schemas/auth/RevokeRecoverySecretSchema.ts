import { z } from "zod";
import { existingPasswordSchema } from "./passwordPolicy";

/**
 * Revoking the account's recovery secret destroys its last way back in, so it proves ownership
 * with the credential that already exists and reuses `existingPasswordSchema` — presence and
 * the API's ceiling, never the new-password policy. Applying the policy's floor here would tell
 * the user their working password is "too short" when the only thing that can be wrong is that
 * it does not match.
 */
export const RevokeRecoverySecretSchema = z.object({
  currentPassword: existingPasswordSchema,
});
export type RevokeRecoverySecretFormValues = z.infer<typeof RevokeRecoverySecretSchema>;
