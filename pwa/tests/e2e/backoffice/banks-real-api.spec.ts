import { test, expect, type APIRequestContext } from "@playwright/test";
import { VIEWPORT_DESKTOP } from "../constants";
import {
  createApiContext,
  deleteBanksSafely,
  filterByName,
  seedBanks,
  uniqueRunPrefix,
  type ApiBank,
} from "../fixtures/banks-real-api";

/**
 * Real-API E2E coverage for the Banks CRUD flow. Unlike `banks.spec.ts`,
 * which intercepts every request via `page.route`, this suite drives the
 * actual Symfony backend so the contract between the PWA and the API is
 * exercised end-to-end (HTTP shape, validators, persistence).
 *
 * Strategy
 * - `beforeAll` seeds 26 banks under a unique run-scoped prefix via
 *   POST /api/v1/backoffice/banks. 26 rows comfortably exceed the default
 *   page size (25) so pagination crosses to a second page.
 * - The list filter is client-side and the API ships up to 1000 banks per
 *   page, so a name-prefix filter reliably narrows the UI to test-owned
 *   rows even when the dev DB already holds unrelated banks.
 * - The CRUD walkthrough creates a fresh bank from the UI, edits it, and
 *   deletes it — never reusing the seeded rows so the seeded set keeps a
 *   stable shape for sort/pagination assertions.
 * - `afterAll` deletes every seeded + UI-created bank via DELETE so reruns
 *   stay deterministic.
 *
 * Running locally requires the full stack (`make docker.up.wait`) and the
 * webServer config in `playwright.config.ts` to pick up
 * `NEXT_PUBLIC_SYMFONY_API_BASE_URL=https://localhost`.
 */
test.describe.configure({ mode: "serial" });

test.describe("BackOffice - Banks CRUD (real API)", () => {
  test.use({ viewport: VIEWPORT_DESKTOP });

  const SEED_COUNT = 26;
  const runPrefix = uniqueRunPrefix("BankCrud");
  const trackedIds: string[] = [];
  let api: APIRequestContext;
  let seeded: ApiBank[] = [];

  test.beforeAll(async () => {
    api = await createApiContext();
    seeded = await seedBanks(api, runPrefix, SEED_COUNT);
    trackedIds.push(...seeded.map((bank) => bank.id));
  });

  test.afterAll(async () => {
    await deleteBanksSafely(api, trackedIds);
    await api.dispose();
  });

  test("displays the list, sorts, paginates, then creates / updates / deletes a bank", async ({
    page,
  }) => {
    const firstSeeded = `${runPrefix} 001`;
    const lastSeeded = `${runPrefix} ${SEED_COUNT.toString().padStart(3, "0")}`;
    const secondToLastSeeded = `${runPrefix} ${(SEED_COUNT - 1).toString().padStart(3, "0")}`;

    // -----------------------------------------------------------------
    // List — render rows from the live API.
    // -----------------------------------------------------------------
    await page.goto("/backoffice/banks");

    const list = page.getByTestId("banks-list");
    await expect(list).toHaveAttribute("data-state", "ready");
    await expect(page.getByTestId("banks-table")).toBeVisible();

    // Narrow the client-side filter to test-owned rows so other banks in
    // the dev DB don't leak into our assertions.
    await page.getByTestId("banks-filters__toggle").click();
    // Wait for the debounced filter to apply before paginating — otherwise the
    // late filter-change resets the page back to 1 mid-test (see filterByName).
    await filterByName(page, runPrefix);

    // Default sort is name ascending — the first row should be `… 001`.
    const firstDataRow = page.locator("tbody tr").first();
    await expect(firstDataRow.getByRole("cell", { name: firstSeeded, exact: true })).toBeVisible();
    await expect(page.getByRole("columnheader", { name: "Name", exact: true })).toHaveAttribute(
      "aria-sort",
      "ascending",
    );

    // -----------------------------------------------------------------
    // Pagination — 26 rows fit on two pages of 25.
    // -----------------------------------------------------------------
    await expect(page.getByTestId("banks-pagination__indicator")).toHaveText("Page 1");
    await expect(page.getByTestId("banks-pagination__page-size")).toHaveValue("25");
    await expect(page.locator("tbody tr")).toHaveCount(25);
    await expect(page.getByRole("cell", { name: firstSeeded, exact: true })).toBeVisible();
    await expect(page.getByRole("cell", { name: lastSeeded, exact: true })).toBeHidden();
    await expect(page.getByTestId("banks-pagination__prev")).toBeHidden();

    await page.getByTestId("banks-pagination__next").click();
    await expect(page.getByTestId("banks-pagination__indicator")).toHaveText("Page 2");
    await expect(page.locator("tbody tr")).toHaveCount(1);
    await expect(page.getByRole("cell", { name: lastSeeded, exact: true })).toBeVisible();
    await expect(page.getByTestId("banks-pagination__next")).toBeHidden();

    // Bumping the page size collapses the seeded set onto a single page.
    await page.getByTestId("banks-pagination__page-size").selectOption("50");
    await expect(page.getByTestId("banks-pagination__indicator")).toHaveText("Page 1");
    await expect(page.locator("tbody tr")).toHaveCount(SEED_COUNT);
    await expect(page.getByTestId("banks-pagination__next")).toBeHidden();
    await expect(page.getByTestId("banks-pagination__prev")).toBeHidden();

    // -----------------------------------------------------------------
    // Sort — flip name to descending; first row should now be `… 026`.
    // -----------------------------------------------------------------
    const nameHeaderButton = page
      .getByRole("columnheader", { name: "Name", exact: true })
      .getByRole("button");
    await nameHeaderButton.click();
    await expect(page.getByRole("columnheader", { name: "Name", exact: true })).toHaveAttribute(
      "aria-sort",
      "descending",
    );
    await expect(firstDataRow.getByRole("cell", { name: lastSeeded, exact: true })).toBeVisible();
    await expect(
      page.locator("tbody tr").nth(1).getByRole("cell", { name: secondToLastSeeded, exact: true }),
    ).toBeVisible();

    // -----------------------------------------------------------------
    // Create — drive the UI form against the real POST endpoint.
    // -----------------------------------------------------------------
    const createName = `${runPrefix} CRUD`;
    const createShortName = `${runPrefix.slice(-40)}-CRUD`.slice(0, 50).toLocaleUpperCase();
    const updatedName = `${createName} (renamed)`;

    await page.getByTestId("banks-list__new-button").click();
    await expect(page).toHaveURL(/\/backoffice\/banks\/new$/);

    await page.getByTestId("bank-form__name").fill(createName);
    await page.getByTestId("bank-form__short-name").fill(createShortName);
    await page.getByTestId("bank-form__submit").click();

    // The detail page lives at /backoffice/banks/<uuid>. Capture the id so
    // afterAll cleans it up even if a later assertion fails.
    await expect(page).toHaveURL(/\/backoffice\/banks\/[0-9a-f-]{36}$/);
    const detailURL = new URL(page.url());
    const createdId = detailURL.pathname.split("/").pop()!;
    trackedIds.push(createdId);

    await expect(page.getByTestId("banks-detail")).toHaveAttribute("data-state", "ready");
    await expect(page.getByTestId("banks-detail__name")).toHaveText(createName);
    await expect(page.getByTestId("banks-detail__shortname")).toHaveText(createShortName);
    await expect(page.getByTestId("banks-detail__id")).toHaveText(createdId);

    // -----------------------------------------------------------------
    // Update — edit the bank, expect detail page to reflect the rename.
    // -----------------------------------------------------------------
    await page.getByTestId("banks-detail__edit-button").click();
    await expect(page).toHaveURL(`/backoffice/banks/${createdId}/edit`);

    await page.getByTestId("bank-form__name").fill(updatedName);
    await page.getByTestId("bank-form__submit").click();

    await expect(page).toHaveURL(`/backoffice/banks/${createdId}`);
    await expect(page.getByTestId("banks-detail__name")).toHaveText(updatedName);
    await expect(page.getByTestId("banks-detail__field-name")).toHaveText(updatedName);

    // -----------------------------------------------------------------
    // Delete — confirm the dialog and verify the row is gone server-side.
    // -----------------------------------------------------------------
    await page.getByTestId("banks-detail__delete-button").click();
    await page.getByTestId("banks-detail__delete-confirm").click();

    await expect(page).toHaveURL(/\/backoffice\/banks$/);
    await page.getByTestId("banks-filters__toggle").click();
    await page.getByTestId("banks-filters__name").fill(updatedName);
    await expect(page.getByTestId("banks-list__empty-filtered")).toBeVisible();

    // Also confirm the API itself returns 404 for the deleted id.
    const probe = await api.get(`/api/v1/backoffice/banks/${createdId}`);
    expect(probe.status()).toBe(404);
  });
});
