import { test, expect } from "../fixtures/authenticatedTest";
import { VIEWPORT_DESKTOP } from "../constants";
import {
  ACME_CHECKING,
  BETA_RESERVE,
  emitMercure,
  installFakeMercure,
  mockBankAccountsListApi,
} from "../fixtures/bank-accounts-list-api";

test.describe("BackOffice - Bank Accounts (standalone, mocked API)", () => {
  test.use({ viewport: VIEWPORT_DESKTOP });

  test("renders cross-bank rows with the bank column and a masked IBAN", async ({ page }) => {
    await mockBankAccountsListApi(page);
    await page.goto("/backoffice/bank-accounts");

    await expect(page.getByTestId("bank-accounts-list")).toHaveAttribute("data-state", "ready");
    await expect(page.getByTestId("bank-accounts-list__title")).toHaveText("Bank Accounts");

    // The bank column names each row's owning bank (cross-bank hub).
    await expect(page.getByTestId(`bank-accounts-list__bank-${ACME_CHECKING.id}`)).toContainText(
      "Acme Savings",
    );
    await expect(page.getByTestId(`bank-accounts-list__bank-${BETA_RESERVE.id}`)).toContainText(
      "Beta Bank",
    );

    // IBAN is masked at rest — the last four show, the integral value never does.
    const ibanCell = page.getByTestId(`bank-accounts-list__iban-${ACME_CHECKING.id}`);
    await expect(ibanCell).toContainText("1332");
    await expect(ibanCell).not.toContainText(ACME_CHECKING.iban);

    // Reveal shows the integral value on demand.
    await ibanCell.getByRole("button", { name: "Show IBAN" }).click();
    await expect(ibanCell).toContainText(ACME_CHECKING.iban);
  });

  test("navigates to the account detail from a row", async ({ page }) => {
    await mockBankAccountsListApi(page);
    await page.goto("/backoffice/bank-accounts");
    await expect(page.getByTestId("bank-accounts-list")).toHaveAttribute("data-state", "ready");

    await page.getByTestId(`bank-accounts-list__bank-${ACME_CHECKING.id}`).click();

    await expect(page).toHaveURL(`/backoffice/bank-accounts/${ACME_CHECKING.id}`);
    await expect(page.getByTestId("bank-accounts-detail__holder")).toHaveText(
      ACME_CHECKING.holderName,
    );
  });

  test("finds an account by its exact IBAN and navigates to its detail page", async ({ page }) => {
    await mockBankAccountsListApi(page);
    await page.goto("/backoffice/bank-accounts");
    await expect(page.getByTestId("bank-accounts-list")).toHaveAttribute("data-state", "ready");

    // A human-grouped, lower-case IBAN still matches (client-side canonicalization).
    await page
      .getByTestId("bank-accounts-iban-lookup__input")
      .fill("es91 2100 0418 4502 0005 1332");
    await page.getByTestId("bank-accounts-iban-lookup__submit").click();

    await expect(page).toHaveURL(`/backoffice/bank-accounts/${ACME_CHECKING.id}`);
    await expect(page.getByTestId("bank-accounts-detail__holder")).toHaveText(
      ACME_CHECKING.holderName,
    );
  });

  test("shows an inline not-found result for a well-formed IBAN with no match", async ({
    page,
  }) => {
    await mockBankAccountsListApi(page);
    await page.goto("/backoffice/bank-accounts");
    await expect(page.getByTestId("bank-accounts-list")).toHaveAttribute("data-state", "ready");

    await page.getByTestId("bank-accounts-iban-lookup__input").fill("GB29NWBK60161331926819");
    await page.getByTestId("bank-accounts-iban-lookup__submit").click();

    await expect(page.getByTestId("bank-accounts-iban-lookup__not-found")).toHaveText(
      "No account found for that IBAN.",
    );
    await expect(page).toHaveURL("/backoffice/bank-accounts");
  });

  test("reflects a realtime update live over the global collection topic", async ({ page }) => {
    await installFakeMercure(page);
    const api = await mockBankAccountsListApi(page);
    await page.goto("/backoffice/bank-accounts");
    await expect(page.getByTestId("bank-accounts-list")).toHaveAttribute("data-state", "ready");

    // Wait for the (faked) subscription to open before publishing.
    await page.waitForFunction(() => {
      const w = window as unknown as { __mercureInstances?: unknown[] };
      return Array.isArray(w.__mercureInstances) && w.__mercureInstances.length > 0;
    });

    // Another client renames the account; the PII-free delta triggers a silent
    // refetch, which returns the updated holder.
    api.rename(ACME_CHECKING.id, "Alice Renamed");
    await emitMercure(page, {
      type: "bank_account.updated",
      id: ACME_CHECKING.id,
      bankId: ACME_CHECKING.bankId,
    });

    await expect(page.getByRole("cell", { name: "Alice Renamed" })).toBeVisible();
  });
});
