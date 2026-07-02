import { FilterOperator, type Filter } from "@/context/shared/search/domain";
import type { BankAccountSort } from "@/context/backoffice/bankaccount/domain/BankAccountRepository";
import type { BankAccountsFilter, BankAccountsSort } from "./bankAccountsFilterSort";

/**
 * Maps the accounts UI filter state to the generic `Filter[]` vocabulary the API
 * understands: `holderName`/`alias`/`bank` all become `contains` (substring
 * search). `bank` matches the owning bank's name or short code through one
 * server-side CONTAINS over the joined bank, so a partial value is safe to emit
 * on every keystroke (unlike an exact UUID id). Blank / whitespace-only values
 * are omitted, so they never reach the server.
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

  const bank = filter.bank.trim();
  if (bank) {
    filters.push({ field: "bank", operator: FilterOperator.Contains, value: bank });
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
