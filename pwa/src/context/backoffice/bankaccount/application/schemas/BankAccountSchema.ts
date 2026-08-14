import { z } from "zod";
import { BANK_ACCOUNT_CURRENCIES } from "@/context/backoffice/bankaccount/domain/BankAccount";

/**
 * Zod schema for the bank-account create / edit form. Mirrors the Symfony
 * constraints on the API `Create`/`Update` command DTOs:
 *
 *   - `holderName` — `#[Assert\NotBlank]`, `#[Assert\Length(max: 255)]`
 *   - `iban`       — `#[Assert\NotBlank]`, `#[Assert\Iban]`
 *   - `bic`        — optional `#[Assert\Bic(ibanPropertyPath: 'iban')]`
 *   - `alias`      — optional `#[Assert\Length(max: 100)]`
 *   - `currency`   — `Currency` enum (EUR today)
 *
 * `status` is NOT a form field: a lifecycle transition is a distinct intent that
 * goes through its own control / `PATCH /bank-accounts/{id}/status` endpoint.
 *
 * The per-field messages mirror the API's 422 violation strings field-by-field
 * (the API messages are dev-facing; the form copy is user-facing, so they read
 * differently — the mapping keys off the violation `field`, not the message).
 *
 * IBAN and BIC are canonicalised here (whitespace stripped, upper-cased) before
 * anything measures them, so the validated value is the value sent — no needless
 * 422 round-trip on casing/spacing, and no width measured against a spelling the
 * system never keeps. A statement or PDF copy-paste carries its grouping into both
 * fields, so both must absorb it. This strip is JS `\s`, deliberately wider than
 * the three separators the API's own canonicaliser removes: a character it absorbs
 * and the API would have refused leaves here as the canonical value the API
 * accepts, so the extra tolerance costs a rejection nobody wanted and changes no
 * stored value.
 *
 * The two `.max()` bounds below mirror the ENTITY, not the command DTOs listed
 * above: 34 is the aggregate's `#[Assert\Length(max: 34)]` over the canonical IBAN,
 * and 11 the same over the canonical BIC. Both are live API constraints — do not
 * delete them for being absent from the DTO list. Limits live ONLY in the schema
 * (`.max()`), never as a `maxLength` that would silently truncate input.
 */
export const HOLDER_NAME_MAX_LENGTH = 255;
export const IBAN_MAX_LENGTH = 34;
export const BIC_MAX_LENGTH = 11;
export const ALIAS_MAX_LENGTH = 100;

/**
 * Re-exported from the domain, which declares both vocabularies once for the whole PWA. Kept
 * reachable from here because the form and the status control read them alongside this schema.
 */
export {
  BANK_ACCOUNT_CURRENCIES,
  BANK_ACCOUNT_STATUSES,
} from "@/context/backoffice/bankaccount/domain/BankAccount";

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
    .transform((value) => value.replace(/\s+/g, "").toUpperCase())
    .pipe(
      z
        .string()
        .max(BIC_MAX_LENGTH, "The BIC must not exceed 11 characters.")
        .refine((value) => value === "" || BIC_PATTERN.test(value), "Please enter a valid BIC."),
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
