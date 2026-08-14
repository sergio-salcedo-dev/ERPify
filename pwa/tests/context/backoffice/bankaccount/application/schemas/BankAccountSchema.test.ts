import { describe, expect, it } from "vitest";
import {
  BankAccountSchema,
  type BankAccountFormValues,
} from "@/context/backoffice/bankaccount/application/schemas/BankAccountSchema";
import { ZodValidatorAdapter } from "@/context/shared/validation/infrastructure/ZodValidatorAdapter";

const validator = new ZodValidatorAdapter<BankAccountFormValues>(BankAccountSchema);

const VALID = {
  holderName: "Acme Corp",
  iban: "ES9121000418450200051332",
  bic: "CAIXESBBXXX",
  alias: "Payroll",
  currency: "EUR",
};

describe("BankAccountSchema", () => {
  it("accepts a well-formed payload and trims whitespace", () => {
    const result = validator.validate({ ...VALID, holderName: "  Acme Corp  " });
    expect(result.success).toBe(true);
    if (result.success) {
      expect(result.data.holderName).toBe("Acme Corp");
    }
  });

  it("canonicalises the IBAN (strips spaces, upper-cases) so the validated value is the value sent", () => {
    const result = validator.validate({ ...VALID, iban: "es91 2100 0418 4502 0005 1332" });
    expect(result.success).toBe(true);
    if (result.success) {
      expect(result.data.iban).toBe("ES9121000418450200051332");
    }
  });

  it("canonicalises the BIC the same way, so a grouped copy-paste is not measured as typed", () => {
    const result = validator.validate({ ...VALID, bic: "caix esbb xxx" });
    expect(result.success).toBe(true);
    if (result.success) {
      expect(result.data.bic).toBe("CAIXESBBXXX");
    }
  });

  it("accepts a grouped IBAN whose typed form is longer than the canonical ceiling", () => {
    // Malta: 31 canonical characters, 38 as a statement groups them.
    const result = validator.validate({ ...VALID, iban: "MT84 MALT 0110 0001 2345 MTLC AST0 01S" });
    expect(result.success).toBe(true);
    if (result.success) {
      expect(result.data.iban).toBe("MT84MALT011000012345MTLCAST001S");
    }
  });

  it("requires holderName (NotBlank)", () => {
    for (const value of ["", "   "]) {
      const result = validator.validate({ ...VALID, holderName: value });
      expect(result.success).toBe(false);
      if (!result.success) {
        expect(result.issues.find((i) => i.path === "holderName")?.message).toBe(
          "The holder name field is required.",
        );
      }
    }
  });

  it("rejects holderName longer than 255 chars (mirrors Length max=255)", () => {
    const result = validator.validate({ ...VALID, holderName: "x".repeat(256) });
    expect(result.success).toBe(false);
    if (!result.success) {
      expect(result.issues.find((i) => i.path === "holderName")?.message).toBe(
        "The holder name must not exceed 255 characters.",
      );
    }
  });

  it("requires the IBAN and rejects a malformed one", () => {
    const blank = validator.validate({ ...VALID, iban: "" });
    expect(blank.success).toBe(false);
    if (!blank.success) {
      expect(blank.issues.find((i) => i.path === "iban")?.message).toBe(
        "The IBAN field is required.",
      );
    }
    const malformed = validator.validate({ ...VALID, iban: "not-an-iban!!" });
    expect(malformed.success).toBe(false);
    if (!malformed.success) {
      expect(malformed.issues.find((i) => i.path === "iban")?.message).toBe(
        "Please enter a valid IBAN.",
      );
    }
  });

  it("accepts an absent/empty BIC and alias (both optional) and rejects a malformed BIC", () => {
    expect(validator.validate({ ...VALID, bic: "", alias: "" }).success).toBe(true);
    const bad = validator.validate({ ...VALID, bic: "NOPE" });
    expect(bad.success).toBe(false);
    if (!bad.success) {
      expect(bad.issues.find((i) => i.path === "bic")?.message).toBe("Please enter a valid BIC.");
    }
  });

  it("rejects an alias longer than 100 chars", () => {
    const result = validator.validate({ ...VALID, alias: "x".repeat(101) });
    expect(result.success).toBe(false);
    if (!result.success) {
      expect(result.issues.find((i) => i.path === "alias")?.message).toBe(
        "The alias must not exceed 100 characters.",
      );
    }
  });

  it("rejects an unknown currency enum value", () => {
    expect(validator.validate({ ...VALID, currency: "USD" }).success).toBe(false);
  });
});
