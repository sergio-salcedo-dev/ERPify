/**
 * Cross-cutting validation port. Bounded contexts depend on this interface
 * (or on the concrete schema types they own); they MUST NOT depend on the
 * adapter (Zod, Yup, Joi, …) directly. Swap the adapter out by changing
 * the binding in `Validation/index.ts` only.
 */

/** A single field-level violation. */
export interface ValidationIssue {
  /** Dotted path to the offending field (`"name"`, `"address.city"`). */
  path: string;
  /** Human-readable violation message — mirrors Symfony Validator's `title`. */
  message: string;
}

/** Result of validating a value against a schema. */
export type ValidationResult<T> =
  | { readonly success: true; readonly data: T }
  | { readonly success: false; readonly issues: readonly ValidationIssue[] };

/**
 * Generic validator port. Implementations adapt a specific schema library
 * (Zod, Yup, …) into this contract.
 *
 * The single `validate` method returns a discriminated union rather than
 * throwing, so callers can pattern-match on `result.success` without a
 * try/catch.
 */
export interface Validator<T> {
  validate(data: unknown): ValidationResult<T>;
}
