import { z } from "zod";

/**
 * The API's ceiling on a presented secret (`Assert\Length(max: 200)`), counted in code points
 * for the reason the password policy counts them: `Assert\Length` counts code points too, so
 * a bound written against `String.length` would refuse at half the real limit for anything
 * outside the BMP.
 */
export const RECOVERY_SECRET_MAX_LENGTH = 200;

export const RECOVERY_SECRET_REQUIRED_MESSAGE = "Enter your recovery secret.";
export const RECOVERY_SECRET_TOO_LONG_MESSAGE =
  "The recovery secret must not exceed 200 characters.";

/**
 * The secret a locked-out user types or pastes in. Surrounding whitespace is trimmed before
 * anything else, because a secret is minted as `<uuid>.<token>` and can never legitimately
 * carry any — so a trailing newline picked up by a copy is a paste artefact, and failing on
 * it would spend one of the account's few attempts on the clipboard's punctuation.
 *
 * Nothing else about the shape is asserted here. The server treats every malformed, unknown,
 * expired and spent presentation identically, and a client-side format rule would be the one
 * place in this flow that tells an attacker their guess was the wrong *shape*.
 */
export const RedeemRecoverySecretSchema = z.object({
  secret: z
    .string()
    .trim()
    .min(1, RECOVERY_SECRET_REQUIRED_MESSAGE)
    .refine(
      (value) => [...value].length <= RECOVERY_SECRET_MAX_LENGTH,
      RECOVERY_SECRET_TOO_LONG_MESSAGE,
    ),
});
export type RedeemRecoverySecretFormValues = z.infer<typeof RedeemRecoverySecretSchema>;
