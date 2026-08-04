import { z } from "zod";

/**
 * The one password policy the PWA states, mirroring `Shared\Validation\Infrastructure\PasswordPolicy`
 * on the API side. Every surface that sets a new credential — change, reset, invitation acceptance —
 * consumes `newPasswordSchema`, so the three cannot drift apart the way their API counterparts did.
 *
 * Length is counted in **code points** (`[...value].length`), not in `String.length`. The two disagree
 * for anything outside the BMP: five astral characters are five code points and ten UTF-16 units, so a
 * `.min(8)` written against `String.length` accepts a credential the API then refuses as too short,
 * under two rules that both claim to say "at least eight characters".
 *
 * Whitespace-only is rejected outright. JavaScript's `trim()` is Unicode-aware, so a non-breaking space
 * (U+00A0) or an ideographic space (U+3000) folds to empty here exactly as `mb_trim` folds it there.
 * The empty string is left to the length rule, so a blank required field keeps saying what it always
 * said instead of lecturing the user about whitespace.
 */
export const PASSWORD_MIN_LENGTH = 8;
/** Upper bound: comfortably above any real password, below slow-hash DoS territory. */
export const PASSWORD_MAX_LENGTH = 128;
/** The API's ceiling on a credential that already exists, which predates the 128 policy. */
export const EXISTING_PASSWORD_MAX_LENGTH = 255;

export const PASSWORD_TOO_SHORT_MESSAGE = "The password must be at least 8 characters.";
export const PASSWORD_TOO_LONG_MESSAGE = "The password must not exceed 128 characters.";
export const PASSWORD_BLANK_MESSAGE =
  "The password must contain at least one non-whitespace character.";

const codePointLength = (value: string): number => [...value].length;

const isWhitespaceOnly = (value: string): boolean => value !== "" && value.trim() === "";

/**
 * The credential being created. Exactly one message fires per input: the three predicates are mutually
 * exclusive by construction, so a whitespace-only value is never also reported as too short.
 */
export const newPasswordSchema = z
  .string()
  .refine((value) => !isWhitespaceOnly(value), PASSWORD_BLANK_MESSAGE)
  .refine(
    (value) => isWhitespaceOnly(value) || codePointLength(value) >= PASSWORD_MIN_LENGTH,
    PASSWORD_TOO_SHORT_MESSAGE,
  )
  .refine(
    (value) => isWhitespaceOnly(value) || codePointLength(value) <= PASSWORD_MAX_LENGTH,
    PASSWORD_TOO_LONG_MESSAGE,
  );

/**
 * The credential that already exists. It carries presence and the API's ceiling, and deliberately not
 * the policy: it may have been minted under an older or wider rule, and telling its owner it is "too
 * short" when the only thing wrong is that it does not match would be both wrong and unactionable.
 */
export const existingPasswordSchema = z
  .string()
  .min(1, "Enter your current password.")
  .max(EXISTING_PASSWORD_MAX_LENGTH, "The password must not exceed 255 characters.");
