import { describe, expect, it } from "vitest";
import { z } from "zod";
import { ZodValidatorAdapter } from "@/context/shared/validation/infrastructure/ZodValidatorAdapter";
import type { Validator } from "@/context/shared/validation/infrastructure/Validator";

const sampleSchema = z.object({
  name: z.string().min(1, "name required").max(10, "name too long"),
  age: z.number().int().min(0, "age must be >= 0"),
});

type Sample = z.infer<typeof sampleSchema>;

describe("ZodValidatorAdapter", () => {
  it("conforms to the Validator port", () => {
    const validator: Validator<Sample> = new ZodValidatorAdapter(sampleSchema);
    expect(typeof validator.validate).toBe("function");
  });

  it("returns success + parsed data when the input is valid", () => {
    const validator = new ZodValidatorAdapter(sampleSchema);
    const result = validator.validate({ name: "Acme", age: 7 });
    expect(result.success).toBe(true);
    if (result.success) {
      expect(result.data).toEqual({ name: "Acme", age: 7 });
    }
  });

  it("returns failure + per-field issues when the input is invalid", () => {
    const validator = new ZodValidatorAdapter(sampleSchema);
    const result = validator.validate({ name: "", age: -1 });
    expect(result.success).toBe(false);
    if (!result.success) {
      const messagesByPath = Object.fromEntries(result.issues.map((i) => [i.path, i.message]));
      expect(messagesByPath).toEqual({
        name: "name required",
        age: "age must be >= 0",
      });
    }
  });

  it("returns a structural error with empty path when the root value is the wrong type", () => {
    const validator = new ZodValidatorAdapter(sampleSchema);
    const result = validator.validate(null);
    expect(result.success).toBe(false);
    if (!result.success) {
      expect(result.issues).toHaveLength(1);
      expect(result.issues[0]?.path).toBe("");
    }
  });

  it("does not throw for unknown input shapes — always returns a result", () => {
    const validator = new ZodValidatorAdapter(sampleSchema);
    expect(() => validator.validate(undefined)).not.toThrow();
    expect(() => validator.validate("not an object")).not.toThrow();
    expect(() => validator.validate([])).not.toThrow();
  });
});
