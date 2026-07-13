import { test, expect, type Page } from "../fixtures/authenticatedTest";
import { VIEWPORT_DESKTOP } from "../constants";
import { SAMPLE_BANK_A, SAMPLE_BANK_B, mockBanksApi } from "../fixtures/banks-api";

/**
 * Delete preconditions land in the persistent mutation-error surface (UX
 * contract «Errores de mutación — superficie persistente», 2026-06-04): the
 * confirm dialog closes itself on failure, the problem renders verbatim above
 * the list with copy affordances, and typed recovery (404 → Refresh list,
 * 409 `bank-in-use` → View accounts) lives in that surface — never in the
 * dialog, never only in a toast. Upstream of that, an optimistic delete-guard
 * intercepts a delete when the bank's `accountCount > 0` and never issues the
 * `DELETE` (the backend stays the authoritative guard for the racing 0 → 409).
 */

async function confirmSingleDelete(page: Page, id: string): Promise<void> {
  await page.getByTestId(`banks-table__actions-${id}`).click();
  await page.getByTestId(`banks-table__delete-${id}`).click();
  await page.getByTestId("banks-detail__delete-confirm").click();
}

const ITEM_DELETE_PATH = /\/api\/v1\/backoffice\/banks\/[^/?#]+$/;

test.describe("BackOffice - Banks delete preconditions (mocked API)", () => {
  test.use({ viewport: VIEWPORT_DESKTOP });

  test("optimistic guard: a bank with accounts never reaches DELETE; View accounts routes to its accounts", async ({
    page,
  }) => {
    const inUseBank = { ...SAMPLE_BANK_A, accountCount: 3 };
    await mockBanksApi(page, { list: "happy", list_banks: [inUseBank, SAMPLE_BANK_B] });

    let deleteAttempts = 0;
    page.on("request", (request) => {
      if (request.method() === "DELETE" && ITEM_DELETE_PATH.test(new URL(request.url()).pathname)) {
        deleteAttempts += 1;
      }
    });

    await page.goto("/backoffice/banks");
    await expect(page.getByTestId("banks-list")).toHaveAttribute("data-state", "ready");

    // Opening the row's delete surfaces the neutral guard, not the destructive
    // confirmation — the DELETE is never issued.
    await page.getByTestId(`banks-table__actions-${inUseBank.id}`).click();
    await page.getByTestId(`banks-table__delete-${inUseBank.id}`).click();

    const viewAccounts = page.getByTestId("banks-detail__delete-guard-view-accounts");
    await expect(viewAccounts).toBeVisible();
    await expect(page.getByTestId("banks-detail__delete-confirm")).toHaveCount(0);
    await expect(viewAccounts).toHaveAttribute(
      "href",
      `/backoffice/banks/${inUseBank.id}/accounts`,
    );

    await viewAccounts.click();
    await expect(page).toHaveURL(new RegExp(`/backoffice/banks/${inUseBank.id}/accounts$`));
    expect(deleteAttempts).toBe(0);
  });

  test("single 409 bank-in-use: dialog closes, error persists above the list, copyable, View accounts recovery", async ({
    page,
  }) => {
    await mockBanksApi(page, { list: "happy", delete: "in-use" });
    await page.goto("/backoffice/banks");
    await expect(page.getByTestId("banks-list")).toHaveAttribute("data-state", "ready");

    await confirmSingleDelete(page, SAMPLE_BANK_A.id);

    const surface = page.getByTestId("banks-list__delete-error");
    await expect(surface).toBeVisible();
    await expect(page.getByTestId("banks-detail__delete-dialog")).toBeHidden();
    await expect(surface).toContainText("Bank cannot be deleted: 3 associated bank accounts");
    await expect(surface).toContainText("bank-in-use");
    await expect(surface).toContainText("HTTP 409");
    // Reading/copying is the point: the surface owns focus and the copy toolbar.
    await expect(surface).toBeFocused();
    await expect(page.getByTestId("banks-list__delete-error__copy-message")).toBeVisible();
    await expect(page.getByTestId("banks-list__delete-error__copy-code")).toBeVisible();
    await expect(page.getByTestId("banks-list__delete-error__copy-json")).toBeVisible();
    // A raced 409 recovers by routing to the bank's accounts surface, not a refresh.
    await expect(page.getByTestId("banks-list__delete-error-refresh")).toHaveCount(0);
    await expect(page.getByTestId("banks-list__delete-error-view-accounts")).toHaveAttribute(
      "href",
      `/backoffice/banks/${SAMPLE_BANK_A.id}/accounts`,
    );
    await expect(page.getByTestId(`banks-table__row-${SAMPLE_BANK_A.id}`)).toBeVisible();

    // Dismiss closes it; nothing else changes.
    await page.getByTestId("banks-list__delete-error__dismiss").click();
    await expect(surface).toHaveCount(0);
    await expect(page.getByTestId(`banks-table__row-${SAMPLE_BANK_A.id}`)).toBeVisible();
  });

  test("single stale 404: Refresh list clears the error, drops the row, and focuses its neighbor", async ({
    page,
  }) => {
    await mockBanksApi(page, { list: "happy", delete: "not-found" });
    await page.goto("/backoffice/banks");
    await expect(page.getByTestId("banks-list")).toHaveAttribute("data-state", "ready");

    await confirmSingleDelete(page, SAMPLE_BANK_A.id);

    const surface = page.getByTestId("banks-list__delete-error");
    await expect(surface).toBeVisible();
    await expect(surface).toContainText("bank-not-found");

    // The server no longer has A: the refresh must drop the stale row.
    await mockBanksApi(page, { list: "happy", list_banks: [SAMPLE_BANK_B] });
    await page.getByTestId("banks-list__delete-error-refresh").click();

    await expect(surface).toHaveCount(0);
    await expect(page.getByTestId(`banks-table__row-${SAMPLE_BANK_A.id}`)).toHaveCount(0);
    await expect(page.getByTestId(`banks-table__row-${SAMPLE_BANK_B.id}`)).toBeFocused();
  });

  test("bulk pre-check: one stale id aborts the whole batch; Refresh recalculates the selection", async ({
    page,
  }) => {
    await mockBanksApi(page, {
      list: "happy",
      stale_ids: [SAMPLE_BANK_B.id],
    });
    await page.goto("/backoffice/banks");
    await expect(page.getByTestId("banks-list")).toHaveAttribute("data-state", "ready");

    await page.getByLabel(`Select row ${SAMPLE_BANK_A.id}`).click();
    await page.getByLabel(`Select row ${SAMPLE_BANK_B.id}`).click();
    await expect(page.getByTestId("banks-list__bulk-count")).toHaveText("2 selected");

    await page.getByTestId("banks-list__bulk-delete").click();
    await page.getByTestId("banks-list__bulk-delete-confirm").click();

    // Nothing was deleted: both rows survive and the probe's 404 is embedded.
    const surface = page.getByTestId("banks-list__delete-error");
    await expect(surface).toBeVisible();
    await expect(surface).toContainText("bank-not-found");
    await expect(page.getByTestId(`banks-table__row-${SAMPLE_BANK_A.id}`)).toBeVisible();
    await expect(page.getByTestId(`banks-table__row-${SAMPLE_BANK_B.id}`)).toBeVisible();

    // Refresh: stale row drops, the selection recalculates (2 → 1), focus
    // returns to the bulk bar's Delete for the retry.
    await mockBanksApi(page, { list: "happy", list_banks: [SAMPLE_BANK_A] });
    await page.getByTestId("banks-list__delete-error-refresh").click();

    await expect(surface).toHaveCount(0);
    await expect(page.getByTestId(`banks-table__row-${SAMPLE_BANK_B.id}`)).toHaveCount(0);
    await expect(page.getByTestId("banks-list__bulk-count")).toHaveText("1 selected");
    await expect(page.getByTestId("banks-list__bulk-delete")).toBeFocused();
  });

  test("the persistent error does not follow the user across navigation", async ({ page }) => {
    await mockBanksApi(page, { list: "happy", delete: "in-use" });
    await page.goto("/backoffice/banks");
    await expect(page.getByTestId("banks-list")).toHaveAttribute("data-state", "ready");

    await confirmSingleDelete(page, SAMPLE_BANK_A.id);
    await expect(page.getByTestId("banks-list__delete-error")).toBeVisible();

    // Page-scoped by contract: navigating away and back starts clean.
    await page.goto("/backoffice");
    await page.goto("/backoffice/banks");

    await expect(page.getByTestId("banks-list")).toHaveAttribute("data-state", "ready");
    await expect(page.getByTestId("banks-list__delete-error")).toHaveCount(0);
  });

  test("bulk partial failure: 409 rows are restored with their selection; deleted rows stay gone", async ({
    page,
  }) => {
    await mockBanksApi(page, {
      list: "happy",
      delete_in_use_ids: [SAMPLE_BANK_B.id],
    });
    await page.goto("/backoffice/banks");
    await expect(page.getByTestId("banks-list")).toHaveAttribute("data-state", "ready");

    await page.getByLabel(`Select row ${SAMPLE_BANK_A.id}`).click();
    await page.getByLabel(`Select row ${SAMPLE_BANK_B.id}`).click();
    await page.getByTestId("banks-list__bulk-delete").click();
    await page.getByTestId("banks-list__bulk-delete-confirm").click();

    // A deleted; B failed with 409 → row AND selection restored for a retry.
    await expect(page.getByTestId(`banks-table__row-${SAMPLE_BANK_A.id}`)).toHaveCount(0);
    await expect(page.getByTestId(`banks-table__row-${SAMPLE_BANK_B.id}`)).toBeVisible();
    await expect(page.getByTestId("banks-list__bulk-count")).toHaveText("1 selected");

    const surface = page.getByTestId("banks-list__delete-error");
    await expect(surface).toBeVisible();
    await expect(surface).toContainText("bank-in-use");
    await expect(page.getByTestId("banks-list__delete-error-refresh")).toHaveCount(0);
  });
});
