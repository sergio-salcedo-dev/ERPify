import { describe, expect, it, vi } from "vitest";
import { LookupBankAccountByIban } from "@/context/backoffice/bankaccount/application/LookupBankAccountByIban";
import type { BankAccountRepository } from "@/context/backoffice/bankaccount/domain/BankAccountRepository";
import type { BankAccountCollectionRow } from "@/context/backoffice/bankaccount/domain/BankAccountCollectionRow";

const ROW: BankAccountCollectionRow = {
  id: "0190ffff-aaaa-7bbb-8ccc-0d1e2f3a4b5c",
  bankId: "11111111-1111-7000-8000-000000000001",
  bankName: "JPMorgan Chase",
  bankShortName: "JPM",
  holderName: "Globex Corporation",
  iban: "DE89370400440532013000",
  bic: "DEUTDEFFXXX",
  alias: "Globex Treasury",
  currency: "EUR",
  status: "ACTIVE",
};

describe("LookupBankAccountByIban", () => {
  it("delegates to the repository's findByIban and returns its result", async () => {
    const repository: BankAccountRepository = {
      search: vi.fn(),
      searchAll: vi.fn(),
      find: vi.fn(),
      findByIban: vi.fn().mockResolvedValue(ROW),
      create: vi.fn(),
      update: vi.fn(),
      changeStatus: vi.fn(),
      delete: vi.fn(),
    };

    const result = await new LookupBankAccountByIban(repository).run(ROW.iban);

    expect(repository.findByIban).toHaveBeenCalledWith(ROW.iban);
    expect(result).toBe(ROW);
  });
});
