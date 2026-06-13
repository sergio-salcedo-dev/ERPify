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
