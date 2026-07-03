import { describe, expect, it, vi } from "vitest";
import { BankAccountCrudRepository } from "@/context/backoffice/bankaccount/infrastructure/BankAccountCrudRepository";
import { BankAccount } from "@/context/backoffice/bankaccount/domain/BankAccount";
import type {
  BankAccountRepository,
  CreateBankAccountInput,
} from "@/context/backoffice/bankaccount/domain/BankAccountRepository";
import type { BankAccountCollectionPage } from "@/context/backoffice/bankaccount/domain/BankAccountCollectionRow";
import type { ResourceSearchCriteria } from "@/context/shared/resource/domain/CrudRepository";
import { SortDirection } from "@/context/shared/search/domain/SortDirection";
import { HttpError } from "@/context/shared/http-client/domain/HttpError";
import type { ProblemDetails } from "@/context/shared/error/domain/ProblemDetails";

const ACCOUNT_ID = "0190ffff-aaaa-7bbb-8ccc-0d1e2f3a4b5c";
const BANK_ID = "11111111-1111-7111-8111-111111111111";

const ROW = {
  id: ACCOUNT_ID,
  bankId: BANK_ID,
  bankName: "Acme Savings",
  bankShortName: "ACME",
  holderName: "Acme Corp",
  iban: "ES9121000418450200051332",
  bic: null,
  alias: null,
  currency: "EUR",
  status: "ACTIVE",
} as const;

const PAGE: BankAccountCollectionPage = {
  rows: [ROW],
  hasNext: true,
  hasPrev: false,
  count: null,
  links: { next: "/api/v1/backoffice/bank-accounts?after=cursor&limit=25", prev: null },
};

const CRITERIA: ResourceSearchCriteria = {
  filters: [{ field: "holderName", operator: "contains", value: "ac" }],
  sort: { field: "holderName", direction: SortDirection.ASC },
  limit: 25,
};

const ENTITY = BankAccount.fromPrimitives({
  id: ACCOUNT_ID,
  bankId: BANK_ID,
  holderName: "Acme Corp",
  iban: "ES9121000418450200051332",
  bic: null,
  alias: null,
  currency: "EUR",
  status: "ACTIVE",
  createdAt: "2026-01-01T00:00:00+00:00",
  updatedAt: "2026-01-02T00:00:00+00:00",
});

const PROBLEM: ProblemDetails = {
  type: "about:blank",
  title: "Boom",
  status: 500,
  instance: "01926e7e-7b8a-7c4e-9f31-000000000500",
  "correlation-id": "01926e7e-7b8a-7c4e-9f30-000000000500",
};

function fakeRepo(overrides: Partial<BankAccountRepository> = {}): BankAccountRepository {
  return {
    search: vi.fn(),
    searchAll: vi.fn(),
    find: vi.fn(),
    create: vi.fn(),
    update: vi.fn(),
    changeStatus: vi.fn(),
    delete: vi.fn(),
    ...overrides,
  };
}

describe("BankAccountCrudRepository", () => {
  it("runs searchAll and remaps {rows} to {items}, preserving bankName/bankShortName", async () => {
    const searchAll = vi.fn().mockResolvedValue(PAGE);
    const repo = new BankAccountCrudRepository(fakeRepo({ searchAll }));

    const result = await repo.search(CRITERIA);

    expect(searchAll).toHaveBeenCalledWith(CRITERIA);
    expect(result).toEqual({
      items: [ROW],
      hasNext: true,
      hasPrev: false,
      count: null,
      links: { next: "/api/v1/backoffice/bank-accounts?after=cursor&limit=25", prev: null },
    });
    expect(result.items[0].bankName).toBe("Acme Savings");
    expect(result.items[0].bankShortName).toBe("ACME");
  });

  it("delegates find/delete to the wrapped repository", async () => {
    const find = vi.fn().mockResolvedValue(ENTITY);
    const remove = vi.fn().mockResolvedValue(undefined);
    const repo = new BankAccountCrudRepository(fakeRepo({ find, delete: remove }));

    // The existence-probe value is adapted to the row shape; only its id matters.
    const found = await repo.find(ACCOUNT_ID);
    expect(find).toHaveBeenCalledWith(ACCOUNT_ID);
    expect(found.id).toBe(ACCOUNT_ID);
    expect(found.bankId).toBe(BANK_ID);

    await repo.delete(ACCOUNT_ID);
    expect(remove).toHaveBeenCalledWith(ACCOUNT_ID);
  });

  it("delegates create/update to the wrapped repository", async () => {
    const input: CreateBankAccountInput = {
      bankId: BANK_ID,
      holderName: "Acme Corp",
      iban: "ES9121000418450200051332",
      bic: null,
      alias: null,
      currency: "EUR",
    };
    const create = vi.fn().mockResolvedValue(ENTITY);
    const update = vi.fn().mockResolvedValue(ENTITY);
    const repo = new BankAccountCrudRepository(fakeRepo({ create, update }));

    await repo.create(input);
    expect(create).toHaveBeenCalledWith(input);

    await repo.update(ACCOUNT_ID, input);
    expect(update).toHaveBeenCalledWith(ACCOUNT_ID, input);
  });

  it("propagates a search failure untouched", async () => {
    const error = new HttpError(PROBLEM);
    const repo = new BankAccountCrudRepository(
      fakeRepo({ searchAll: vi.fn().mockRejectedValue(error) }),
    );

    await expect(repo.search(CRITERIA)).rejects.toBe(error);
  });
});
