import { inject, injectable } from "inversify";
import type { ResourceSearchPage } from "@/context/shared/resource/domain/CrudRepository";
import type { ResourceSearchNavigator } from "@/context/shared/resource/domain/ResourceSearchNavigator";
import type { Bank } from "../domain/Bank";
import type { BankSearchNavigator } from "../application/BankSearchNavigator";
import { toResourcePage } from "./bankResourcePage";

/**
 * Adapts the bank-specific {@link BankSearchNavigator} (`follow` → `{ banks }`)
 * to the generic {@link ResourceSearchNavigator} (`follow` → `{ items }`) the
 * shared toolkit consumes. The opaque link is forwarded verbatim; only the page
 * result is remapped. Errors propagate untouched.
 */
@injectable()
export class BankResourceNavigator implements ResourceSearchNavigator<Bank> {
  constructor(
    @inject("BackOfficeBankSearchNavigator") private readonly navigator: BankSearchNavigator,
  ) {}

  async follow(link: string): Promise<ResourceSearchPage<Bank>> {
    return toResourcePage(await this.navigator.follow(link));
  }
}
