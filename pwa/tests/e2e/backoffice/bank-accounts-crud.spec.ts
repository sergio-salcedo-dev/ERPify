import { test, expect } from "@playwright/test";
import { VIEWPORT_DESKTOP } from "../constants";
import {
  ACTIVE_ACCOUNT,
  CLOSED_ACCOUNT,
  SAMPLE_BANK_ID,
  mockBankAccountsApi,
} from "../fixtures/bank-accounts-api";

/**
 * Mocked-route E2E for the bank-account CRUD hub nested under a bank. Every
 * request to the nested list and the standalone `/bank-accounts` endpoints is
 * intercepted so the response shape is fully under test control.
 */

const LIST_URL = `/backoffice/banks/${SAMPLE_BANK_ID}/accounts`;

test.describe("BackOffice - Bank account CRUD (mocked API)", () => {
  test.use({ viewport: VIEWPORT_DESKTOP });

  test.describe("list hub", () => {
    test("renders the accounts table with a New account button and per-row actions", async ({
      page,
    }) => {
      await mockBankAccountsApi(page, { list: "happy" });
      await page.goto(LIST_URL);

      await expect(page.getByTestId("bank-accounts")).toHaveAttribute("data-state", "ready");
      await expect(page.getByTestId("bank-accounts__new-button")).toHaveAttribute(
        "href",
        `${LIST_URL}/new`,
      );
      await expect(page.getByTestId(`bank-accounts-table__row-${CLOSED_ACCOUNT.id}`)).toBeVisible();
      await expect(
        page.getByTestId(`bank-accounts-table__edit-${CLOSED_ACCOUNT.id}`),
      ).toBeVisible();
      await page.getByTestId(`bank-accounts-table__actions-${CLOSED_ACCOUNT.id}`).click();
      await expect(
        page.getByTestId(`bank-accounts-table__delete-${CLOSED_ACCOUNT.id}`),
      ).toBeVisible();
    });
  });

  test.describe("create", () => {
    test("creates an account and returns to the accounts list", async ({ page }) => {
      await mockBankAccountsApi(page, { create: "happy", list: "happy" });
      await page.goto(`${LIST_URL}/new`);
      await expect(page.getByTestId("bank-account-form")).toHaveAttribute("data-hydrated", "true");

      await page.getByTestId("bank-account-form__holder-name").fill("New Holder");
      await page.getByTestId("bank-account-form__iban").fill(ACTIVE_ACCOUNT.iban);
      await page.getByTestId("bank-account-form__submit").click();

      await expect(page).toHaveURL(LIST_URL);
      await expect(page.getByTestId("bank-accounts")).toHaveAttribute("data-state", "ready");
    });

    test("rejects a malformed IBAN with a visible field error, never truncating input", async ({
      page,
    }) => {
      await mockBankAccountsApi(page, { create: "happy" });
      await page.goto(`${LIST_URL}/new`);
      await expect(page.getByTestId("bank-account-form")).toHaveAttribute("data-hydrated", "true");

      await page.getByTestId("bank-account-form__holder-name").fill("New Holder");
      await page.getByTestId("bank-account-form__iban").fill("not-a-valid-iban");
      await page.getByTestId("bank-account-form__submit").click();

      await expect(page.getByText("Please enter a valid IBAN.")).toBeVisible();
      await expect(page.getByTestId("bank-account-form__iban")).toHaveValue("not-a-valid-iban");
    });

    test("create 500: the persistent error is visible and dismissible", async ({ page }) => {
      await mockBankAccountsApi(page, { create: "server-error" });
      await page.goto(`${LIST_URL}/new`);
      await expect(page.getByTestId("bank-account-form")).toHaveAttribute("data-hydrated", "true");

      await page.getByTestId("bank-account-form__holder-name").fill("New Holder");
      await page.getByTestId("bank-account-form__iban").fill(ACTIVE_ACCOUNT.iban);
      await page.getByTestId("bank-account-form__submit").click();

      const surface = page.getByTestId("bank-account-form__mutation-error");
      await expect(surface).toBeVisible();
      await expect(surface).toContainText("The account could not be created.");
      await expect(page.getByTestId("bank-account-form__mutation-error-refresh")).toHaveCount(0);

      await surface.getByTestId("bank-account-form__mutation-error__dismiss").click();
      await expect(page.getByTestId("bank-account-form__mutation-error")).toHaveCount(0);
    });
  });

  test.describe("edit", () => {
    test("loads the account and saves changes, returning to the list", async ({ page }) => {
      await mockBankAccountsApi(page, {
        get: "happy",
        update: "happy",
        list: "happy",
        account: CLOSED_ACCOUNT,
      });
      await page.goto(`${LIST_URL}/${CLOSED_ACCOUNT.id}/edit`);
      await expect(page.getByTestId("bank-account-form")).toHaveAttribute("data-hydrated", "true");

      await expect(page.getByTestId("bank-account-form__holder-name")).toHaveValue(
        CLOSED_ACCOUNT.holderName,
      );
      // Status is no longer a form field — it transitions through its own dedicated control.
      await expect(page.getByTestId("bank-account-form__status")).toHaveCount(0);
      await expect(page.getByTestId("bank-account-status")).toBeVisible();

      await page.getByTestId("bank-account-form__holder-name").fill("Renamed Holder");
      await page.getByTestId("bank-account-form__submit").click();

      await expect(page).toHaveURL(LIST_URL);
    });

    test("surfaces a server 422 violation on its field", async ({ page }) => {
      await mockBankAccountsApi(page, {
        get: "happy",
        update: "validation-error",
        account: CLOSED_ACCOUNT,
      });
      await page.goto(`${LIST_URL}/${CLOSED_ACCOUNT.id}/edit`);
      await expect(page.getByTestId("bank-account-form")).toHaveAttribute("data-hydrated", "true");

      await page.getByTestId("bank-account-form__holder-name").fill("Renamed Holder");
      await page.getByTestId("bank-account-form__submit").click();

      await expect(page.getByText("The holder name field is required.")).toBeVisible();
    });
  });

  test.describe("delete", () => {
    test("deletes a CLOSED account from the row and removes it with a success toast", async ({
      page,
    }) => {
      await mockBankAccountsApi(page, {
        list: "happy",
        delete: "happy",
        list_accounts: [CLOSED_ACCOUNT],
      });
      await page.goto(LIST_URL);
      await expect(page.getByTestId(`bank-accounts-table__row-${CLOSED_ACCOUNT.id}`)).toBeVisible();

      await page.getByTestId(`bank-accounts-table__actions-${CLOSED_ACCOUNT.id}`).click();
      await page.getByTestId(`bank-accounts-table__delete-${CLOSED_ACCOUNT.id}`).click();
      await page.getByTestId("bank-accounts-row__delete-confirm").click();

      await expect(page.getByTestId(`bank-accounts-table__row-${CLOSED_ACCOUNT.id}`)).toHaveCount(
        0,
      );
      await expect(page.getByText("Account deleted")).toBeVisible();
    });

    test("optimistic guard: a non-CLOSED account shows the guard and never issues DELETE", async ({
      page,
    }) => {
      let deleteAttempts = 0;
      page.on("request", (request) => {
        if (request.method() === "DELETE") deleteAttempts += 1;
      });
      await mockBankAccountsApi(page, { list: "happy", list_accounts: [ACTIVE_ACCOUNT] });
      await page.goto(LIST_URL);
      await expect(page.getByTestId(`bank-accounts-table__row-${ACTIVE_ACCOUNT.id}`)).toBeVisible();

      await page.getByTestId(`bank-accounts-table__actions-${ACTIVE_ACCOUNT.id}`).click();
      await page.getByTestId(`bank-accounts-table__delete-${ACTIVE_ACCOUNT.id}`).click();

      await expect(page.getByTestId("bank-accounts-row__delete-guard-message")).toBeVisible();
      await expect(page.getByTestId("bank-accounts-row__delete-confirm")).toHaveCount(0);
      await expect(page.getByTestId("bank-accounts-row__delete-guard-edit")).toHaveAttribute(
        "href",
        `${LIST_URL}/${ACTIVE_ACCOUNT.id}/edit`,
      );
      expect(deleteAttempts).toBe(0);
    });

    test("a raced 409 not-closed lands in the persistent surface with an Edit recovery", async ({
      page,
    }) => {
      await mockBankAccountsApi(page, {
        list: "happy",
        delete: "not-closed",
        list_accounts: [CLOSED_ACCOUNT],
      });
      await page.goto(LIST_URL);
      await expect(page.getByTestId(`bank-accounts-table__row-${CLOSED_ACCOUNT.id}`)).toBeVisible();

      await page.getByTestId(`bank-accounts-table__actions-${CLOSED_ACCOUNT.id}`).click();
      await page.getByTestId(`bank-accounts-table__delete-${CLOSED_ACCOUNT.id}`).click();
      await page.getByTestId("bank-accounts-row__delete-confirm").click();

      const surface = page.getByTestId("bank-accounts__delete-error");
      await expect(surface).toBeVisible();
      await expect(surface).toContainText("Account cannot be deleted: it is not closed");
      await expect(surface).toContainText("bank-account-not-closed");
      await expect(page.getByTestId("bank-accounts__delete-error-edit")).toHaveAttribute(
        "href",
        `${LIST_URL}/${CLOSED_ACCOUNT.id}/edit`,
      );
      // The row survives the failed delete.
      await expect(page.getByTestId(`bank-accounts-table__row-${CLOSED_ACCOUNT.id}`)).toBeVisible();
    });
  });
});
