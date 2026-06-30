import { z } from "zod";

/**
 * Zod schema for the bank-account create / edit form. Mirrors the Symfony
 * constraints on the API `Create`/`Update` command DTOs:
 *
 *   - `holderName` — `#[Assert\NotBlank]`, `#[Assert\Length(max: 255)]`
 *   - `iban`       — `#[Assert\NotBlank]`, `#[Assert\Iban]`, `#[Assert\Length(max: 34)]`
 *   - `bic`        — optional `#[Assert\Bic(ibanPropertyPath: 'iban')]`, `#[Assert\Length(max: 11)]`
 *   - `alias`      — optional `#[Assert\Length(max: 100)]`
 *   - `currency`   — `Currency` enum (EUR today)
 *
 * `status` is NOT a form field: a lifecycle transition is a distinct intent that
 * goes through its own control / `PATCH /bank-accounts/{id}/status` endpoint.
 *
 * The per-field messages mirror the API's 422 violation strings field-by-field
 * (the API messages are dev-facing; the form copy is user-facing, so they read
 * differently — the mapping keys off the violation `field`, not the message). The
 * IBAN is canonicalised here (spaces stripped, upper-cased) the same way the API
 * canonicalises before persisting, so the validated value is the value sent —
 * no needless 422 round-trip on casing/spacing. Limits live ONLY in the schema
 * (`.max()`), never as a `maxLength` that would silently truncate input.
 */
export const HOLDER_NAME_MAX_LENGTH = 255;
export const IBAN_MAX_LENGTH = 34;
export const BIC_MAX_LENGTH = 11;
export const ALIAS_MAX_LENGTH = 100;

/** Closed sets mirroring the API enums; a new member is a typed change, not a silent string. */
export const BANK_ACCOUNT_CURRENCIES = ["EUR"] as const;
export const BANK_ACCOUNT_STATUSES = ["ACTIVE", "INACTIVE", "CLOSED"] as const;

/** IBAN: 2 letters + 2 check digits + up to 30 alphanumerics (canonical, no spaces). */
const IBAN_PATTERN = /^[A-Z]{2}\d{2}[A-Z0-9]{1,30}$/;
/** BIC/SWIFT: 6 letters + 2 alphanumerics + optional 3-char branch. */
const BIC_PATTERN = /^[A-Z]{6}[A-Z0-9]{2}(?:[A-Z0-9]{3})?$/;

export const BankAccountSchema = z.object({
  holderName: z
    .string({ error: "The holder name field is required." })
    .trim()
    .min(1, "The holder name field is required.")
    .max(HOLDER_NAME_MAX_LENGTH, "The holder name must not exceed 255 characters."),
  iban: z
    .string({ error: "The IBAN field is required." })
    .trim()
    .min(1, "The IBAN field is required.")
    .transform((value) => value.replace(/\s+/g, "").toUpperCase())
    .pipe(
      z
        .string()
        .max(IBAN_MAX_LENGTH, "The IBAN must not exceed 34 characters.")
        .regex(IBAN_PATTERN, "Please enter a valid IBAN."),
    ),
  bic: z
    .string()
    .trim()
    .max(BIC_MAX_LENGTH, "The BIC must not exceed 11 characters.")
    .refine(
      (value) => value === "" || BIC_PATTERN.test(value.toUpperCase()),
      "Please enter a valid BIC.",
    )
    .optional(),
  alias: z
    .string()
    .trim()
    .max(ALIAS_MAX_LENGTH, "The alias must not exceed 100 characters.")
    .optional(),
  currency: z.enum(BANK_ACCOUNT_CURRENCIES, { error: "Please select a valid currency." }),
});

/** Inferred form-values shape — consume this from React components. */
export type BankAccountFormValues = z.infer<typeof BankAccountSchema>;
