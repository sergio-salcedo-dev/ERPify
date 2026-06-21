import { describe, expect, it } from "vitest";
import {
  BankSchema,
  type BankFormValues,
} from "@/context/backoffice/bank/application/schemas/BankSchema";
import { ZodValidatorAdapter } from "@/context/shared/Validation/infrastructure/ZodValidatorAdapter";

const validator = new ZodValidatorAdapter<BankFormValues>(BankSchema);

describe("BankSchema", () => {
  it("accepts a well-formed payload and trims whitespace", () => {
    const result = validator.validate({ name: "  Acme Savings  ", shortName: "  ACME  " });
    expect(result.success).toBe(true);
    if (result.success) {
      expect(result.data).toEqual({ name: "Acme Savings", shortName: "ACME" });
    }
  });

  it("requires `name` (NotBlank, mirrors Symfony Assert\\NotBlank)", () => {
    const empty = validator.validate({ name: "", shortName: "ACME" });
    const whitespace = validator.validate({ name: "   ", shortName: "ACME" });
    for (const result of [empty, whitespace]) {
      expect(result.success).toBe(false);
      if (!result.success) {
        const nameIssue = result.issues.find((i) => i.path === "name");
        expect(nameIssue?.message).toBe("The name field is required.");
      }
    }
  });

  it("requires `shortName` (NotBlank, mirrors Symfony Assert\\NotBlank)", () => {
    const result = validator.validate({ name: "Acme", shortName: "" });
    expect(result.success).toBe(false);
    if (!result.success) {
      const issue = result.issues.find((i) => i.path === "shortName");
      expect(issue?.message).toBe("The code field is required.");
    }
  });

  it("rejects names longer than 255 chars (mirrors Symfony Length max=255)", () => {
    const result = validator.validate({ name: "x".repeat(256), shortName: "ACME" });
    expect(result.success).toBe(false);
    if (!result.success) {
      expect(result.issues.find((i) => i.path === "name")?.message).toBe(
        "The name must not exceed 255 characters.",
      );
    }
  });

  it("rejects shortNames longer than 50 chars (mirrors Symfony Length max=50)", () => {
    const result = validator.validate({ name: "Acme", shortName: "x".repeat(51) });
    expect(result.success).toBe(false);
    if (!result.success) {
      expect(result.issues.find((i) => i.path === "shortName")?.message).toBe(
        "The code must not exceed 50 characters.",
      );
    }
  });

  it("rejects non-string fields", () => {
    const result = validator.validate({ name: 123, shortName: null });
    expect(result.success).toBe(false);
  });

  it("BankFormValues type matches { name: string; shortName: string }", () => {
    const sample: BankFormValues = { name: "x", shortName: "y" };
    expect(typeof sample.name).toBe("string");
    expect(typeof sample.shortName).toBe("string");
  });
});
