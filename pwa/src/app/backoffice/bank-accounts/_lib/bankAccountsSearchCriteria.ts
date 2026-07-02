import { FilterOperator, type Filter } from "@/context/shared/search/domain";
import type { BankAccountSort } from "@/context/backoffice/bankaccount/domain/BankAccountRepository";
import { isUuid } from "@/context/shared/uuid/infrastructure/isUuid";
import type { BankAccountsFilter, BankAccountsSort } from "./bankAccountsFilterSort";

/**
 * Maps the accounts UI filter state to the generic `Filter[]` vocabulary the API
 * understands: `holderName`/`alias` become `contains` (substring search),
 * `bankId` becomes an exact `eq` match (it is an identity, not free text).
 * Blank / whitespace-only values are omitted, so they never reach the server.
 */
export function toBankAccountFilters(filter: BankAccountsFilter): Filter[] {
  const filters: Filter[] = [];

  const holderName = filter.holderName.trim();
  if (holderName) {
    filters.push({ field: "holderName", operator: FilterOperator.Contains, value: holderName });
  }

  const alias = filter.alias.trim();
  if (alias) {
    filters.push({ field: "alias", operator: FilterOperator.Contains, value: alias });
  }

  // The API rejects a non-UUID `bankId` with a 422, so a partial value typed into
  // the debounced filter would error the whole list mid-keystroke; only emit the
  // exact-match filter once the value is a complete UUID (a shorter value filters
  // nothing rather than failing the page).
  const bankId = filter.bankId.trim();
  if (isUuid(bankId)) {
    filters.push({ field: "bankId", operator: FilterOperator.Eq, value: bankId });
  }

  return filters;
}

/** Maps the UI sort (table column id + direction) to the domain sort, or null. */
export function toBankAccountSort(sort: BankAccountsSort): BankAccountSort | null {
  if (!sort) {
    return null;
  }
  return { field: sort.columnId, direction: sort.direction };
}
