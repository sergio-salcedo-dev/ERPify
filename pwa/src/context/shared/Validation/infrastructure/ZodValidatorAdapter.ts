import type { ZodType } from "zod";
import type { ValidationIssue, ValidationResult, Validator } from "./Validator";

/**
 * Zod-backed adapter for {@link Validator}. Wraps a `ZodType` schema and
 * exposes the project-wide `validate(data: unknown)` contract.
 *
 * Bounded contexts that own the schema (e.g. `application/schemas/BankSchema.ts`)
 * MAY depend on Zod to declare the schema; the rest of the application —
 * use cases, services, React components — depends on `Validator` (or on the
 * inferred TS type) and therefore stays decoupled from the schema library.
 */
export class ZodValidatorAdapter<T> implements Validator<T> {
  constructor(private readonly schema: ZodType<T>) {}

  public validate(data: unknown): ValidationResult<T> {
    const parsed = this.schema.safeParse(data);
    if (parsed.success) {
      return { success: true, data: parsed.data };
    }
    const issues: ValidationIssue[] = parsed.error.issues.map((issue) => ({
      path: issue.path.length > 0 ? issue.path.join(".") : "",
      message: issue.message,
    }));
    return { success: false, issues };
  }
}
