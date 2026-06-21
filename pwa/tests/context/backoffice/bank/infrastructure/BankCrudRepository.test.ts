import { describe, expect, it, vi } from "vitest";
import { BankCrudRepository } from "@/context/backoffice/bank/infrastructure/BankCrudRepository";
import { Bank } from "@/context/backoffice/bank/domain/Bank";
import type {
  BankInput,
  BankRepository,
  BankSearchPage,
} from "@/context/backoffice/bank/domain/BankRepository";
import type { ResourceSearchCriteria } from "@/context/shared/resource/domain/CrudRepository";
import { SortDirection } from "@/context/shared/search/domain/SortDirection";
import { HttpError } from "@/context/shared/http-client/domain/HttpError";
import type { ProblemDetails } from "@/context/shared/error/domain/ProblemDetails";

const ACME = Bank.fromPrimitives({
  id: "11111111-1111-4111-8111-111111111111",
  name: "Acme Savings",
  shortName: "ACME",
  createdAt: "2026-01-01T10:00:00Z",
  updatedAt: "2026-04-15T14:30:00Z",
  accountCount: 0,
});

const PAGE: BankSearchPage = {
  banks: [ACME],
  hasNext: true,
  hasPrev: false,
  count: null,
  links: { next: "/api/back_office/banks?after=cursor&limit=25", prev: null },
};

const CRITERIA: ResourceSearchCriteria = {
  filters: [{ field: "name", operator: "contains", value: "ac" }],
  sort: { field: "name", direction: SortDirection.ASC },
  limit: 25,
};

const PROBLEM: ProblemDetails = {
  type: "about:blank",
  title: "Boom",
  status: 500,
  instance: "01926e7e-7b8a-7c4e-9f31-000000000500",
  "correlation-id": "01926e7e-7b8a-7c4e-9f30-000000000500",
};

function fakeRepo(overrides: Partial<BankRepository> = {}): BankRepository {
  return {
    search: vi.fn(),
    find: vi.fn(),
    create: vi.fn(),
    update: vi.fn(),
    delete: vi.fn(),
    ...overrides,
  };
}

describe("BankCrudRepository", () => {
  it("forwards the criteria verbatim and remaps the page from {banks} to {items}", async () => {
    const search = vi.fn().mockResolvedValue(PAGE);
    const repo = new BankCrudRepository(fakeRepo({ search }));

    const result = await repo.search(CRITERIA);

    expect(search).toHaveBeenCalledWith(CRITERIA);
    expect(result).toEqual({
      items: [ACME],
      hasNext: true,
      hasPrev: false,
      count: null,
      links: { next: "/api/back_office/banks?after=cursor&limit=25", prev: null },
    });
  });

  it("delegates find/create/update/delete to the wrapped repository", async () => {
    const input: BankInput = { name: "Acme Savings", shortName: "ACME" };
    const find = vi.fn().mockResolvedValue(ACME);
    const create = vi.fn().mockResolvedValue(ACME);
    const update = vi.fn().mockResolvedValue(ACME);
    const remove = vi.fn().mockResolvedValue(undefined);
    const repo = new BankCrudRepository(fakeRepo({ find, create, update, delete: remove }));

    expect(await repo.find(ACME.id)).toBe(ACME);
    expect(find).toHaveBeenCalledWith(ACME.id);
    expect(await repo.create(input)).toBe(ACME);
    expect(create).toHaveBeenCalledWith(input);
    expect(await repo.update(ACME.id, input)).toBe(ACME);
    expect(update).toHaveBeenCalledWith(ACME.id, input);
    await repo.delete(ACME.id);
    expect(remove).toHaveBeenCalledWith(ACME.id);
  });

  it("propagates a search failure untouched", async () => {
    const error = new HttpError(PROBLEM);
    const repo = new BankCrudRepository(fakeRepo({ search: vi.fn().mockRejectedValue(error) }));

    await expect(repo.search(CRITERIA)).rejects.toBe(error);
  });
});
