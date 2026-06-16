import { test, expect, type Page } from "@playwright/test";
import { VIEWPORT_DESKTOP } from "../constants";

/**
 * Mocked-route E2E for the bank-accounts surface (Story 2.3). Every request to
 * the per-bank accounts endpoint is intercepted so the response shape is fully
 * under test control — no live backend or seeded data required.
 */

const BANK_ID = "11111111-1111-7111-8111-111111111111";
const ACCOUNTS_PATH = /\/api\/v1\/backoffice\/banks\/[^/?#]+\/accounts(\?.*)?$/;

const ACCOUNT = {
  id: "0190ffff-aaaa-7bbb-8ccc-0d1e2f3a4b5c",
  holderName: "Acme Corp",
  iban: "ES9121000418450200051332",
  bic: null as string | null,
  alias: "Payroll",
  currency: "EUR",
  status: "ACTIVE",
};

type Scenario = "happy" | "empty" | "server-error";

async function mockAccounts(page: Page, scenario: Scenario): Promise<void> {
  await page.route(ACCOUNTS_PATH, async (route) => {
    if (scenario === "server-error") {
      const correlationId = "0190aaaa-bbbb-7ccc-8ddd-0e1f2a3b4c5d";
      await route.fulfill({
        status: 500,
        contentType: "application/problem+json",
        body: JSON.stringify({
          type: "about:blank",
          title: "Internal Server Error",
          status: 500,
          instance: `${correlationId}-instance`,
          "correlation-id": correlationId,
        }),
      });
      return;
    }
    await route.fulfill({
      status: 200,
      contentType: "application/json",
      body: JSON.stringify({
        data: scenario === "empty" ? [] : [ACCOUNT],
        pagination: {
          hasNext: false,
          hasPrev: false,
          count: null,
          links: { next: null, prev: null },
        },
      }),
    });
  });
}

test.describe("BackOffice - Bank accounts surface", () => {
  test.use({ viewport: VIEWPORT_DESKTOP, permissions: ["clipboard-read", "clipboard-write"] });

  test("renders the accounts table on a cold load", async ({ page }) => {
    await mockAccounts(page, "happy");
    await page.goto(`/backoffice/banks/${BANK_ID}/accounts`);

    await expect(page.getByTestId("bank-accounts")).toHaveAttribute("data-state", "ready");
    await expect(page.getByTestId("bank-accounts-table")).toBeVisible();
    await expect(page.getByTestId(`bank-accounts-table__row-${ACCOUNT.id}`)).toBeVisible();
    await expect(page.getByRole("columnheader", { name: "Holder", exact: true })).toBeVisible();
    await expect(page.getByRole("columnheader", { name: "IBAN", exact: true })).toBeVisible();
    // IBAN is masked by default; the integral value is not in the DOM.
    await expect(page.getByText("ES•• ···· 1332")).toBeVisible();
    await expect(page.getByText(ACCOUNT.iban)).toHaveCount(0);
  });

  test("reveals the integral IBAN on toggle and copies it", async ({ page }) => {
    await mockAccounts(page, "happy");
    await page.goto(`/backoffice/banks/${BANK_ID}/accounts`);

    await expect(page.getByTestId("bank-accounts-table")).toBeVisible();
    await page.getByRole("button", { name: "Show IBAN" }).click();
    await expect(page.getByText(ACCOUNT.iban)).toBeVisible();

    await page.getByTestId(`bank-accounts-table__iban-${ACCOUNT.id}__copy`).click();
    const clipboard = await page.evaluate(() => navigator.clipboard.readText());
    expect(clipboard).toBe(ACCOUNT.iban);
  });

  test("shows the empty state when the bank has no accounts", async ({ page }) => {
    await mockAccounts(page, "empty");
    await page.goto(`/backoffice/banks/${BANK_ID}/accounts`);

    await expect(page.getByText("This bank has no associated accounts.")).toBeVisible();
  });

  test("renders the error boundary with a correlation id on a server error", async ({ page }) => {
    await mockAccounts(page, "server-error");
    await page.goto(`/backoffice/banks/${BANK_ID}/accounts`);

    await expect(page.getByTestId("bank-accounts")).toHaveAttribute("data-state", "error");
    await expect(page.getByTestId("correlation-id-display")).toBeVisible();
    await expect(page.getByTestId("bank-accounts__retry")).toBeVisible();
  });
});
