import { describe, expect, it, vi } from "vitest";
import { SearchAllBankAccounts } from "@/context/backoffice/bankaccount/application/SearchAllBankAccounts";
import type {
  BankAccountRepository,
  BankAccountSearchCriteria,
} from "@/context/backoffice/bankaccount/domain/BankAccountRepository";
import type { BankAccountCollectionPage } from "@/context/backoffice/bankaccount/domain/BankAccountCollectionRow";

const PAGE: BankAccountCollectionPage = {
  rows: [
    {
      id: "0190ffff-aaaa-7bbb-8ccc-0d1e2f3a4b5c",
      bankId: "11111111-1111-7111-8111-111111111111",
      bankName: "Acme Savings",
      bankShortName: "ACME",
      holderName: "Acme Corp",
      iban: "ES9121000418450200051332",
      bic: null,
      alias: null,
      currency: "EUR",
      status: "ACTIVE",
    },
  ],
  hasNext: false,
  hasPrev: false,
  count: 1,
  links: { next: null, prev: null },
};

describe("SearchAllBankAccounts", () => {
  it("delegates the criteria to the repository's cross-bank searchAll", async () => {
    const searchAll = vi.fn().mockResolvedValue(PAGE);
    const repository: BankAccountRepository = {
      search: vi.fn(),
      searchAll,
      find: vi.fn(),
      create: vi.fn(),
      update: vi.fn(),
      changeStatus: vi.fn(),
      delete: vi.fn(),
    };
    const criteria: BankAccountSearchCriteria = { filters: [], sort: null, limit: 25 };

    const result = await new SearchAllBankAccounts(repository).run(criteria);

    expect(searchAll).toHaveBeenCalledWith(criteria);
    expect(result).toBe(PAGE);
  });
});
