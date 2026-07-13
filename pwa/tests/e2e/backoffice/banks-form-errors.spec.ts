import { test, expect, type Page } from "../fixtures/authenticatedTest";
import { VIEWPORT_DESKTOP } from "../constants";
import { SAMPLE_BANK_A, mockBanksApi } from "../fixtures/banks-api";

const ITEM_PATH = /\/api\/v1\/backoffice\/banks\/[^/?#]+$/;

/**
 * Item-route override (registered after `mockBanksApi`, so Playwright runs it
 * first): the GET answers happy until a PUT has fired, then 404 — so the edit
 * page loads the form, the PUT 404s, and the form-initiated Refresh re-runs the
 * load straight into the not-found empty state. The PUT itself falls back to
 * `mockBanksApi` (which returns its `update: "not-found"` 404).
 */
async function staleAfterPut(page: Page): Promise<void> {
  let putFired = false;
  await page.route(
    (url) => ITEM_PATH.test(url.pathname),
    async (route) => {
      const method = route.request().method();
      if (method === "PUT") {
        putFired = true;
        await route.fallback();
        return;
      }
      if (method !== "GET") {
        await route.fallback();
        return;
      }
      if (putFired) {
        await route.fulfill({
          status: 404,
          contentType: "application/problem+json",
          headers: { "X-Correlation-Id": "01H-get-after-stale", "Cache-Control": "no-store" },
          body: JSON.stringify({
            type: "bank-not-found",
            title: "Bank not found.",
            status: 404,
            detail: "It may have been deleted.",
            instance: "01H-get-after-stale-instance",
            "correlation-id": "01H-get-after-stale",
          }),
        });
        return;
      }
      await route.fulfill({
        status: 200,
        contentType: "application/json",
        body: JSON.stringify({ data: SAMPLE_BANK_A }),
      });
    },
  );
}

test.describe("BackOffice - Banks form mutation errors", () => {
  test.use({ viewport: VIEWPORT_DESKTOP });

  test("create 500: the persistent error is visible, copyable, and dismissible", async ({
    page,
    browserName,
    context,
  }) => {
    if (browserName === "chromium") {
      await context.grantPermissions(["clipboard-read", "clipboard-write"]);
    }
    await mockBanksApi(page, { create: "server-error" });
    await page.goto("/backoffice/banks/new");
    await expect(page.getByTestId("bank-form")).toHaveAttribute("data-hydrated", "true");

    await page.getByTestId("bank-form__name").fill("Acme Savings");
    await page.getByTestId("bank-form__short-name").fill("ACME");
    await page.getByTestId("bank-form__submit").click();

    const surface = page.getByTestId("bank-form__mutation-error");
    await expect(surface).toBeVisible();
    await expect(surface).toContainText("Something went wrong.");
    await expect(surface).toContainText("The bank could not be created.");
    // Create mode never offers a recovery action.
    await expect(page.getByTestId("bank-form__mutation-error-refresh")).toHaveCount(0);

    // The message is copyable.
    const copyMessage = surface.getByTestId("bank-form__mutation-error__copy-message");
    await expect(copyMessage).toHaveAttribute("data-copy-status", "idle");
    await copyMessage.click();
    await expect(copyMessage).toHaveAttribute("data-copy-status", "copied");
    if (browserName === "chromium") {
      const clip = await page.evaluate(() => navigator.clipboard.readText());
      expect(clip).toContain("Something went wrong.");
    }

    // Dismiss removes the surface without touching the typed values.
    await surface.getByTestId("bank-form__mutation-error__dismiss").click();
    await expect(page.getByTestId("bank-form__mutation-error")).toHaveCount(0);
    await expect(page.getByTestId("bank-form__name")).toHaveValue("Acme Savings");
  });

  test("edit 404: Refresh re-runs the load into the not-found empty state", async ({ page }) => {
    await mockBanksApi(page, { get: "happy", update: "not-found", bank: SAMPLE_BANK_A });
    await staleAfterPut(page);

    await page.goto(`/backoffice/banks/${SAMPLE_BANK_A.id}/edit`);
    await expect(page.getByTestId("bank-form")).toHaveAttribute("data-hydrated", "true");

    await page.getByTestId("bank-form__name").fill("Acme Savings (renamed)");
    await page.getByTestId("bank-form__submit").click();

    const surface = page.getByTestId("bank-form__mutation-error");
    await expect(surface).toBeVisible();
    await expect(surface).toContainText("Bank not found.");

    await page.getByTestId("bank-form__mutation-error-refresh").click();

    await expect(page.getByTestId("banks-edit__not-found")).toBeVisible();
    await expect(page.getByRole("heading", { name: "Bank not found" })).toBeVisible();
    await expect(page.getByTestId("banks-edit__back-to-list")).toBeVisible();
    await expect(page.getByTestId("bank-form")).toHaveCount(0);
  });

  test("the error does not follow the user when they cancel out of the form", async ({ page }) => {
    await mockBanksApi(page, { create: "server-error", list: "happy" });
    await page.goto("/backoffice/banks/new");
    await expect(page.getByTestId("bank-form")).toHaveAttribute("data-hydrated", "true");

    await page.getByTestId("bank-form__name").fill("Acme Savings");
    await page.getByTestId("bank-form__short-name").fill("ACME");
    await page.getByTestId("bank-form__submit").click();
    await expect(page.getByTestId("bank-form__mutation-error")).toBeVisible();

    await page.getByRole("link", { name: "Cancel and go back" }).click();

    await expect(page).toHaveURL("/backoffice/banks");
    await expect(page.getByTestId("bank-form__mutation-error")).toHaveCount(0);
  });
});
