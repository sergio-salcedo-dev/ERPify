/**
 * Validation kernel. Bounded contexts and React components import from
 * this barrel only; they MUST NOT import `zod` or
 * `@hookform/resolvers/zod` directly.
 *
 * - {@link Validator} / {@link ValidationResult} / {@link ValidationIssue}
 *   — the framework-agnostic port.
 * - {@link ZodValidatorAdapter} — the Zod-backed adapter implementing the
 *   port. Use it from application services that need to validate data
 *   outside of a React form.
 * - {@link useZodForm} — the React Hook Form integration helper. Pass a
 *   bounded-context schema and receive a typed `useForm` return.
 */
export type { ValidationIssue, ValidationResult, Validator } from "./Validator";
export { ZodValidatorAdapter } from "./ZodValidatorAdapter";
export { useZodForm } from "./useZodForm";
