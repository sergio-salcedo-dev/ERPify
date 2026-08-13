import { describe, expect, it } from "vitest";
import { container } from "@/context/shared/dependency-injection/infrastructure/Container";
import { BankCrudRepository } from "@/context/backoffice/bank/infrastructure/BankCrudRepository";
import { BankResourceNavigator } from "@/context/backoffice/bank/infrastructure/BankResourceNavigator";
import { CountBanks } from "@/context/backoffice/bank/application/CountBanks";
import { BankAccountCrudRepository } from "@/context/backoffice/bankaccount/infrastructure/BankAccountCrudRepository";
import { BankAccountResourceNavigator } from "@/context/backoffice/bankaccount/infrastructure/BankAccountResourceNavigator";
import { SearchBankAccounts } from "@/context/backoffice/bankaccount/application/SearchBankAccounts";

/**
 * The backoffice list surfaces resolve their ports by STRING token, so nothing type-checks that wiring:
 * every list spec substitutes the container module, and a binding dropped from the composition root
 * takes its import with it — leaving `tsc --noEmit` clean and the whole suite green while the screen
 * fails in the browser. These resolutions are the check that would otherwise not exist.
 *
 * `BackOfficeCountBanks` earns its line for a second reason: `useBanksCount` swallows a resolution
 * failure by design, so a lost binding there degrades silently to "0 banks" with nothing red anywhere.
 */
describe("backoffice list bindings", () => {
  it.each([
    ["BackOfficeBankCrudRepository", BankCrudRepository],
    ["BackOfficeBankResourceNavigator", BankResourceNavigator],
    ["BackOfficeCountBanks", CountBanks],
    ["BackOfficeBankAccountCrudRepository", BankAccountCrudRepository],
    ["BackOfficeBankAccountResourceNavigator", BankAccountResourceNavigator],
    ["BackOfficeSearchBankAccounts", SearchBankAccounts],
  ])("resolves %s from the real container", (token, adapter) => {
    expect(container.get(token)).toBeInstanceOf(adapter);
  });
});
