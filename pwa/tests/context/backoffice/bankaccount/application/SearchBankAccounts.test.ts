import { describe, expect, it, vi } from "vitest";
import { SearchBankAccounts } from "@/context/backoffice/bankaccount/application/SearchBankAccounts";
import { BankAccount } from "@/context/backoffice/bankaccount/domain/BankAccount";
import type {
  BankAccountRepository,
  BankAccountSearchCriteria,
  BankAccountSearchPage,
} from "@/context/backoffice/bankaccount/domain/BankAccountRepository";

const PAGE: BankAccountSearchPage = {
  accounts: [
    BankAccount.fromPrimitives({
      id: "0190ffff-aaaa-7bbb-8ccc-0d1e2f3a4b5c",
      holderName: "Acme Corp",
      iban: "ES9121000418450200051332",
      bic: null,
      alias: null,
      currency: "EUR",
      status: "ACTIVE",
    }),
  ],
  hasNext: false,
  hasPrev: false,
  count: 1,
  links: { next: null, prev: null },
};

describe("SearchBankAccounts", () => {
  it("delegates to the repository with the bank id and criteria", async () => {
    const repository: BankAccountRepository = { search: vi.fn().mockResolvedValue(PAGE) };
    const criteria: BankAccountSearchCriteria = { filters: [], sort: null, limit: 25 };

    const result = await new SearchBankAccounts(repository).run("bank-1", criteria);

    expect(repository.search).toHaveBeenCalledWith("bank-1", criteria);
    expect(result).toBe(PAGE);
  });
});
