import { describe, expect, it } from "vitest";
import { Bank } from "@/context/backoffice/bank/domain/Bank";

const BASE = {
  id: "11111111-1111-4111-8111-111111111111",
  name: "Acme Savings",
  shortName: "ACME",
  createdAt: "2026-01-01T00:00:00Z",
  updatedAt: "2026-01-02T00:00:00Z",
};

describe("Bank.fromPrimitives — accountCount", () => {
  it("maps accountCount: 3 as a number", () => {
    const bank = Bank.fromPrimitives({ ...BASE, accountCount: 3 });
    expect(bank.accountCount).toBe(3);
    expect(typeof bank.accountCount).toBe("number");
  });

  it("maps accountCount: 0 as a number", () => {
    const bank = Bank.fromPrimitives({ ...BASE, accountCount: 0 });
    expect(bank.accountCount).toBe(0);
    expect(typeof bank.accountCount).toBe("number");
  });
});

describe("Bank.fromPrimitives — logoImageId", () => {
  const LOGO = "0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5c";

  it("carries the id the API sent", () => {
    expect(Bank.fromPrimitives({ ...BASE, accountCount: 0, logoImageId: LOGO }).logoImageId).toBe(
      LOGO,
    );
  });

  it("reads an explicit null and an absent key alike as no logo", () => {
    expect(
      Bank.fromPrimitives({ ...BASE, accountCount: 0, logoImageId: null }).logoImageId,
    ).toBeNull();
    expect(Bank.fromPrimitives({ ...BASE, accountCount: 0 }).logoImageId).toBeNull();
  });
});
