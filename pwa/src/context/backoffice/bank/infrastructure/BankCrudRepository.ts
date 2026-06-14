import { inject, injectable } from "inversify";
import type {
  CrudRepository,
  ResourceSearchCriteria,
  ResourceSearchPage,
} from "@/context/shared/resource/domain/CrudRepository";
import type { Bank } from "../domain/Bank";
import type { BankInput, BankRepository } from "../domain/BankRepository";
import { toResourcePage } from "./bankResourcePage";

/**
 * Adapts the bank-specific {@link BankRepository} (`{ banks }` pages) to the
 * generic {@link CrudRepository} the shared `useResourceList` toolkit consumes
 * (`{ items }` pages). `ResourceSearchCriteria` and `BankSearchCriteria` are
 * structurally identical, so the criteria forwards verbatim; only the page
 * result is remapped. Errors propagate untouched.
 */
@injectable()
export class BankCrudRepository implements CrudRepository<Bank, BankInput> {
  constructor(@inject("BackOfficeBankRepository") private readonly banks: BankRepository) {}

  async search(criteria: ResourceSearchCriteria): Promise<ResourceSearchPage<Bank>> {
    return toResourcePage(await this.banks.search(criteria));
  }

  find(id: string): Promise<Bank> {
    return this.banks.find(id);
  }

  create(input: BankInput): Promise<Bank> {
    return this.banks.create(input);
  }

  update(id: string, input: BankInput): Promise<Bank> {
    return this.banks.update(id, input);
  }

  delete(id: string): Promise<void> {
    return this.banks.delete(id);
  }
}
