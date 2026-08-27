import { z } from "zod";
import { BankAccountSchema } from "./BankAccountSchema";

/**
 * Single-field schema for the "find account by IBAN" search box. Reuses the
 * account form's `iban` field validator (shape, canonicalization) so the two
 * surfaces validate — and canonicalize — identically rather than restating
 * the rule (see `BankAccountSchema`).
 */
export const BankAccountIbanLookupSchema = z.object({
  iban: BankAccountSchema.shape.iban,
});

/** Inferred form-values shape — consume this from React components. */
export type BankAccountIbanLookupFormValues = z.infer<typeof BankAccountIbanLookupSchema>;
