import type { ResourceSearchPage } from "@/context/shared/resource/domain/CrudRepository";
import type { Bank } from "../domain/Bank";
import type { BankSearchPage } from "../domain/BankRepository";

/**
 * Translates a bank-shaped search page ({@link BankSearchPage}, items in `banks`)
 * into the generic resource page the shared list toolkit consumes (items in
 * `items`). The pagination envelope is identical, so only the items key moves.
 */
export function toResourcePage(page: BankSearchPage): ResourceSearchPage<Bank> {
  return {
    items: page.banks,
    hasNext: page.hasNext,
    hasPrev: page.hasPrev,
    count: page.count,
    links: page.links,
  };
}
