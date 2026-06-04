import { z } from "zod";

/**
 * Zod schema for the Bank create / edit form. Mirrors the Symfony entity
 * constraints declared on `Erpify\Backoffice\Bank\Domain\Entity\Bank`:
 *
 *   - `name`      — `#[Assert\NotBlank]`, `#[Assert\Length(max: 255)]`
 *   - `shortName` — `#[Assert\NotBlank]`, `#[Assert\Length(max: 50)]`
 *
 * The error messages match the strings the API returns in 422 responses,
 * so the same UI test assertions cover both client- and server-side
 * surfacing.
 */
/** Mirror of the API's `#[Assert\Length(max: 255)]` on Bank::name. */
export const BANK_NAME_MAX_LENGTH = 255;

export const BankSchema = z.object({
  name: z
    .string({ error: "The name field is required." })
    .trim()
    .min(1, "The name field is required.")
    .max(BANK_NAME_MAX_LENGTH, "The name must not exceed 255 characters."),
  shortName: z
    .string({ error: "The shortName field is required." })
    .trim()
    .min(1, "The shortName field is required.")
    .max(50, "The shortName must not exceed 50 characters."),
});

/** Inferred form-values shape — consume this from React components. */
export type BankFormValues = z.infer<typeof BankSchema>;
