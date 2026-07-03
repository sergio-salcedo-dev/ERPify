import type { ResourceSearchPage } from "@/context/shared/resource/domain/CrudRepository";
import type {
  BankAccountCollectionPage,
  BankAccountCollectionRow,
} from "../domain/BankAccountCollectionRow";

/**
 * Translates a collection search page ({@link BankAccountCollectionPage}, items
 * in `rows`) into the generic resource page the shared list toolkit consumes
 * (items in `items`). The pagination envelope is identical, so only the items
 * key moves.
 */
export function toResourcePage(
  page: BankAccountCollectionPage,
): ResourceSearchPage<BankAccountCollectionRow> {
  return {
    items: page.rows,
    hasNext: page.hasNext,
    hasPrev: page.hasPrev,
    count: page.count,
    links: page.links,
  };
}
