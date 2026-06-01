import { test, expect } from "@playwright/test";
import { VIEWPORT_DESKTOP, VIEWPORT_MOBILE } from "../constants";
import {
  SAMPLE_BANK_A,
  SAMPLE_BANK_B,
  SAMPLE_BANK_C,
  SAMPLE_BANK_D,
  makeBanks,
  mockBanksApi,
} from "../fixtures/banks-api";

test.describe("BackOffice - Banks CRUD", () => {
  test.use({ viewport: VIEWPORT_DESKTOP });

  test.describe("list", () => {
    test("renders rows from the API", async ({ page }) => {
      await mockBanksApi(page, { list: "happy" });
      await page.goto("/backoffice/banks");

      await expect(page.getByTestId("banks-list")).toHaveAttribute("data-state", "ready");
      await expect(page.getByTestId("banks-list__title")).toHaveText("Banks");
      await expect(page.getByTestId("banks-list__subtitle")).toBeVisible();
      await expect(page.getByTestId("banks-table")).toBeVisible();
      await expect(page.getByTestId("banks-pagination")).toBeVisible();
      await expect(page.getByRole("link", { name: /New bank/i })).toBeVisible();
      await expect(page.getByRole("cell", { name: "ACME", exact: true })).toBeVisible();
      await expect(page.getByRole("cell", { name: "Acme Savings", exact: true })).toBeVisible();
      await expect(page.getByRole("cell", { name: "BRT", exact: true })).toBeVisible();
      await expect(page.getByTestId(`banks-table__row-${SAMPLE_BANK_A.id}`)).toBeVisible();
      await expect(page.getByTestId(`banks-table__row-${SAMPLE_BANK_B.id}`)).toBeVisible();
    });

    test("shows the empty state when there are no banks", async ({ page }) => {
      await mockBanksApi(page, { list: "empty" });
      await page.goto("/backoffice/banks");

      await expect(page.getByRole("heading", { name: "No banks yet" })).toBeVisible();
      await expect(page.getByRole("link", { name: "Create your first bank" })).toBeVisible();
    });

    test("renders an Actions column with edit and delete controls per row", async ({ page }) => {
      await mockBanksApi(page, { list: "happy" });
      await page.goto("/backoffice/banks");

      await expect(page.getByRole("columnheader", { name: "Actions", exact: true })).toBeVisible();
      await expect(page.getByTestId(`banks-table__edit-${SAMPLE_BANK_A.id}`)).toBeVisible();
      await expect(page.getByTestId(`banks-table__actions-${SAMPLE_BANK_A.id}`)).toBeVisible();
      // Delete is demoted into the per-row overflow (⋯) menu.
      await page.getByTestId(`banks-table__actions-${SAMPLE_BANK_A.id}`).click();
      await expect(page.getByTestId(`banks-table__delete-${SAMPLE_BANK_A.id}`)).toBeVisible();
    });

    test("clicking the inline edit button navigates to the edit page", async ({ page }) => {
      await mockBanksApi(page, { list: "happy", get: "happy", bank: SAMPLE_BANK_A });
      await page.goto("/backoffice/banks");

      await page.getByTestId(`banks-table__edit-${SAMPLE_BANK_A.id}`).click();
      await expect(page).toHaveURL(`/backoffice/banks/${SAMPLE_BANK_A.id}/edit`);
    });

    test("inline delete confirms and removes the row without navigating away", async ({ page }) => {
      await mockBanksApi(page, { list: "happy", delete: "happy", bank: SAMPLE_BANK_A });
      await page.goto("/backoffice/banks");

      await expect(page.getByRole("cell", { name: "Acme Savings", exact: true })).toBeVisible();
      await page.getByTestId(`banks-table__actions-${SAMPLE_BANK_A.id}`).click();
      await page.getByTestId(`banks-table__delete-${SAMPLE_BANK_A.id}`).click();
      await page.getByTestId("banks-detail__delete-confirm").click();

      await expect(page).toHaveURL(/\/backoffice\/banks$/);
      await expect(page.getByRole("cell", { name: "Acme Savings", exact: true })).toBeHidden();
      await expect(page.getByRole("cell", { name: "Brookline Trust", exact: true })).toBeVisible();
      // The list mirrors the detail view's feedback: a success toast naming the
      // removed bank (the row vanished without navigating away, so the toast is
      // the only acknowledgement).
      await expect(page.getByText("Bank deleted")).toBeVisible();
      await expect(page.getByText(SAMPLE_BANK_A.name)).toBeVisible();
    });

    test("deleting two banks in a row stacks both toasts newest-on-top", async ({ page }) => {
      await mockBanksApi(page, {
        list: "happy",
        delete: "happy",
        list_banks: [SAMPLE_BANK_A, SAMPLE_BANK_B, SAMPLE_BANK_C],
      });
      await page.goto("/backoffice/banks");

      // Delete A, then B — back-to-back so both toasts are still on-screen
      // (well within Sonner's default lifetime) when we assert ordering.
      await page.getByTestId(`banks-table__actions-${SAMPLE_BANK_A.id}`).click();
      await page.getByTestId(`banks-table__delete-${SAMPLE_BANK_A.id}`).click();
      await page.getByTestId("banks-detail__delete-confirm").click();
      await expect(page.getByRole("cell", { name: SAMPLE_BANK_A.name, exact: true })).toBeHidden();

      await page.getByTestId(`banks-table__actions-${SAMPLE_BANK_B.id}`).click();
      await page.getByTestId(`banks-table__delete-${SAMPLE_BANK_B.id}`).click();
      await page.getByTestId("banks-detail__delete-confirm").click();
      await expect(page.getByRole("cell", { name: SAMPLE_BANK_B.name, exact: true })).toBeHidden();

      // Both deletions surface their own toast (Cosmos Bank stays in the list).
      await expect(page.getByText("Bank deleted")).toHaveCount(2);
      const toastA = page.locator("[data-sonner-toast]", { hasText: SAMPLE_BANK_A.name });
      const toastB = page.locator("[data-sonner-toast]", { hasText: SAMPLE_BANK_B.name });
      await expect(toastA).toBeVisible();
      await expect(toastB).toBeVisible();

      // Newest first: B (deleted last) sits above A in the top-center stack.
      const boxA = await toastA.boundingBox();
      const boxB = await toastB.boundingBox();
      if (!boxA || !boxB) {
        throw new Error("expected both delete toasts to be measurable");
      }
      expect(boxB.y).toBeLessThan(boxA.y);
    });
  });

  test.describe("view", () => {
    test("renders bank details on the happy path", async ({ page }) => {
      await mockBanksApi(page, { get: "happy", bank: SAMPLE_BANK_A });
      await page.goto(`/backoffice/banks/${SAMPLE_BANK_A.id}`);

      await expect(page.getByTestId("banks-detail")).toHaveAttribute("data-state", "ready");
      await expect(page.getByTestId("banks-detail__name")).toHaveText(SAMPLE_BANK_A.name);
      await expect(page.getByTestId("banks-detail__shortname")).toHaveText(SAMPLE_BANK_A.shortName);
      await expect(page.getByTestId("banks-detail__edit-button")).toBeVisible();
      await expect(page.getByTestId("banks-detail__delete-button")).toBeVisible();
      await expect(page.getByTestId("banks-detail__id-copy")).toBeVisible();
      await expect(page.getByTestId("banks-detail__id")).toHaveText(SAMPLE_BANK_A.id);
      await expect(page.getByTestId("banks-detail__field-name")).toHaveText(SAMPLE_BANK_A.name);
      await expect(page.getByTestId("banks-detail__field-shortname")).toHaveText(
        SAMPLE_BANK_A.shortName,
      );
      await expect(page.getByTestId("banks-detail__field-created")).toBeVisible();
      await expect(page.getByTestId("banks-detail__field-updated")).toBeVisible();
    });

    test("copies the bank ID to the clipboard and flips the button to 'Copied'", async ({
      page,
      browserName,
      context,
    }) => {
      // Clipboard read/write requires explicit permission in Chromium.
      if (browserName === "chromium") {
        await context.grantPermissions(["clipboard-read", "clipboard-write"]);
      }
      await mockBanksApi(page, { get: "happy", bank: SAMPLE_BANK_A });
      await page.goto(`/backoffice/banks/${SAMPLE_BANK_A.id}`);

      const copyBtn = page.getByTestId("banks-detail__id-copy");
      await expect(copyBtn).toHaveAttribute("data-copy-status", "idle");
      await copyBtn.click();
      await expect(copyBtn).toHaveAttribute("data-copy-status", "copied");
      await expect(copyBtn).toContainText("ID copied");

      const clipboardValue = await page.evaluate(() => navigator.clipboard.readText());
      expect(clipboardValue).toBe(SAMPLE_BANK_A.id);
    });

    test("shows EmptyState + correlation chip on 404 from the RFC 9457 envelope", async ({
      page,
    }) => {
      await mockBanksApi(page, { get: "not-found" });
      await page.goto(`/backoffice/banks/${SAMPLE_BANK_A.id}`);

      await expect(page.getByRole("heading", { name: "Bank not found" })).toBeVisible();
      await expect(page.getByTestId("correlation-id-display")).toBeVisible();
    });
  });

  test.describe("create", () => {
    test("creates a bank and navigates to the detail page", async ({ page }) => {
      await mockBanksApi(page, { create: "happy", get: "happy", bank: SAMPLE_BANK_A });
      await page.goto("/backoffice/banks/new");

      await page.getByTestId("bank-form__name").fill(SAMPLE_BANK_A.name);
      await page.getByTestId("bank-form__short-name").fill(SAMPLE_BANK_A.shortName);
      await page.getByTestId("bank-form__submit").click();

      await expect(page).toHaveURL(`/backoffice/banks/${SAMPLE_BANK_A.id}`);
      await expect(page.getByRole("heading", { name: SAMPLE_BANK_A.name })).toBeVisible();
    });

    test("surfaces validation-failed violations as field-level errors", async ({ page }) => {
      await mockBanksApi(page, { create: "validation-error" });
      await page.goto("/backoffice/banks/new");

      await page.getByTestId("bank-form__short-name").fill("XYZ");
      await page.getByTestId("bank-form__submit").click();

      await expect(page.getByText("The name field is required.")).toBeVisible();
      // Form keeps state — short name input retains the user value.
      await expect(page.getByTestId("bank-form__short-name")).toHaveValue("XYZ");
    });
  });

  test.describe("edit", () => {
    test("updates a bank and navigates to the detail page", async ({ page }) => {
      await mockBanksApi(page, { get: "happy", update: "happy", bank: SAMPLE_BANK_A });
      await page.goto(`/backoffice/banks/${SAMPLE_BANK_A.id}/edit`);

      await page.getByTestId("bank-form__name").fill("Acme Savings (renamed)");
      await page.getByTestId("bank-form__submit").click();

      await expect(page).toHaveURL(`/backoffice/banks/${SAMPLE_BANK_A.id}`);
    });

    test("surfaces validation-failed violations on update", async ({ page }) => {
      await mockBanksApi(page, { get: "happy", update: "validation-error", bank: SAMPLE_BANK_A });
      await page.goto(`/backoffice/banks/${SAMPLE_BANK_A.id}/edit`);

      const overlong = "x".repeat(60);
      await page.getByTestId("bank-form__short-name").fill(overlong);
      await page.getByTestId("bank-form__submit").click();

      await expect(page.getByText("The shortName must not exceed 50 characters.")).toBeVisible();
    });
  });

  test.describe("delete", () => {
    test("deletes a bank from the detail page and redirects to the list", async ({ page }) => {
      await mockBanksApi(page, {
        get: "happy",
        delete: "happy",
        list: "empty",
        bank: SAMPLE_BANK_A,
      });
      await page.goto(`/backoffice/banks/${SAMPLE_BANK_A.id}`);

      await page.getByTestId("banks-detail__delete-button").click();
      await page.getByTestId("banks-detail__delete-confirm").click();

      await expect(page).toHaveURL("/backoffice/banks");
      await expect(page.getByRole("heading", { name: "No banks yet" })).toBeVisible();
    });

    test("redirects to the list with a success toast and never flashes 'Bank not found'", async ({
      page,
    }) => {
      await mockBanksApi(page, {
        get: "happy",
        delete: "happy",
        list: "empty",
        bank: SAMPLE_BANK_A,
      });
      await page.goto(`/backoffice/banks/${SAMPLE_BANK_A.id}`);

      await page.getByTestId("banks-detail__delete-button").click();
      await page.getByTestId("banks-detail__delete-confirm").click();

      // Lands cleanly on the list — the deleted id never refetches into the
      // "Bank not found" empty state.
      await expect(page).toHaveURL("/backoffice/banks");
      await expect(page.getByRole("heading", { name: "No banks yet" })).toBeVisible();
      await expect(page.getByTestId("banks-detail__not-found")).toHaveCount(0);
      // The success toast surfaces on the list after the redirect, naming the
      // bank that was removed.
      await expect(page.getByText("Bank deleted")).toBeVisible();
      await expect(page.getByText(SAMPLE_BANK_A.name)).toBeVisible();
    });

    test("shows ProblemDisplay inside the dialog when DELETE returns 404", async ({ page }) => {
      await mockBanksApi(page, { get: "happy", delete: "not-found", bank: SAMPLE_BANK_A });
      await page.goto(`/backoffice/banks/${SAMPLE_BANK_A.id}`);

      await page.getByTestId("banks-detail__delete-button").click();
      await page.getByTestId("banks-detail__delete-confirm").click();

      // User stays on the detail page (URL unchanged).
      await expect(page).toHaveURL(`/backoffice/banks/${SAMPLE_BANK_A.id}`);
      await expect(
        page.getByRole("alert").getByRole("heading", { name: "Bank not found." }),
      ).toBeVisible();
    });
  });

  test.describe("filters and sort", () => {
    const allBanks = [SAMPLE_BANK_A, SAMPLE_BANK_B, SAMPLE_BANK_C, SAMPLE_BANK_D];

    test("filter panel is collapsed by default; the toggle reveals it", async ({ page }) => {
      await mockBanksApi(page, { list: "happy", list_banks: allBanks });
      await page.goto("/backoffice/banks");

      const toggle = page.getByTestId("banks-filters__toggle");
      const panel = page.getByTestId("banks-filters__panel");

      // Collapsed by default — minimalist landing state.
      await expect(toggle).toBeVisible();
      await expect(toggle).toHaveAttribute("aria-expanded", "false");
      await expect(panel).toBeHidden();
      // No filters active → no count badge.
      await expect(page.getByTestId("banks-filters__count")).toHaveCount(0);

      await toggle.click();
      await expect(toggle).toHaveAttribute("aria-expanded", "true");
      await expect(panel).toBeVisible();
      await expect(page.getByTestId("banks-filters__name")).toBeVisible();

      // Toggling again hides the panel without losing values.
      await toggle.click();
      await expect(toggle).toHaveAttribute("aria-expanded", "false");
      await expect(panel).toBeHidden();
    });

    test("Reset button is hidden when no filters are active and appears once a filter is set", async ({
      page,
    }) => {
      await mockBanksApi(page, { list: "happy", list_banks: allBanks });
      await page.goto("/backoffice/banks");

      await page.getByTestId("banks-filters__toggle").click();
      // Reset is removed from the DOM (not just disabled) when nothing to reset.
      await expect(page.getByTestId("banks-filters__reset")).toHaveCount(0);

      await page.getByTestId("banks-filters__name").fill("cosmos");
      await expect(page.getByTestId("banks-filters__reset")).toBeVisible();

      await page.getByTestId("banks-filters__reset").click();
      await expect(page.getByTestId("banks-filters__name")).toHaveValue("");
      await expect(page.getByTestId("banks-filters__reset")).toHaveCount(0);
    });

    test("toggle button shows a count badge with the number of active filters", async ({
      page,
    }) => {
      await mockBanksApi(page, { list: "happy", list_banks: allBanks });
      await page.goto("/backoffice/banks");

      await page.getByTestId("banks-filters__toggle").click();
      await expect(page.getByTestId("banks-filters__count")).toHaveCount(0);

      await page.getByTestId("banks-filters__name").fill("bank");
      await expect(page.getByTestId("banks-filters__count")).toHaveText("1");

      await page.getByTestId("banks-filters__short-name").fill("cos");
      await expect(page.getByTestId("banks-filters__count")).toHaveText("2");

      await page.getByTestId("banks-filters__created-from").fill("2026-01-01");
      await expect(page.getByTestId("banks-filters__count")).toHaveText("3");

      // Badge persists when the panel is collapsed — that's the whole point.
      await page.getByTestId("banks-filters__toggle").click();
      await expect(page.getByTestId("banks-filters__panel")).toBeHidden();
      await expect(page.getByTestId("banks-filters__count")).toHaveText("3");
    });

    test("filters by name case-insensitively, leaves the URL unchanged, and resets via the button", async ({
      page,
    }) => {
      await mockBanksApi(page, { list: "happy", list_banks: allBanks });
      await page.goto("/backoffice/banks");

      await expect(page.getByRole("cell", { name: "Acme Savings" })).toBeVisible();
      await expect(page.getByRole("cell", { name: "Cosmos Bank" })).toBeVisible();

      await page.getByTestId("banks-filters__toggle").click();
      await page.getByTestId("banks-filters__name").fill("cosmos");
      await expect(page.getByRole("cell", { name: "Cosmos Bank" })).toBeVisible();
      await expect(page.getByRole("cell", { name: "Acme Savings" })).toBeHidden();
      await expect(page.getByRole("cell", { name: "Brookline Trust" })).toBeHidden();
      // AC: filter input does not change the URL.
      await expect(page).toHaveURL(/\/backoffice\/banks$/);

      await page.getByTestId("banks-filters__reset").click();
      await expect(page.getByTestId("banks-filters__name")).toHaveValue("");
      await expect(page.getByRole("cell", { name: "Acme Savings" })).toBeVisible();
      await expect(page.getByRole("cell", { name: "Brookline Trust" })).toBeVisible();
    });

    test("AND-combines name and shortName filters", async ({ page }) => {
      await mockBanksApi(page, { list: "happy", list_banks: allBanks });
      await page.goto("/backoffice/banks");

      await page.getByTestId("banks-filters__toggle").click();
      await page.getByTestId("banks-filters__name").fill("bank");
      await page.getByTestId("banks-filters__short-name").fill("cos");

      await expect(page.getByRole("cell", { name: "Cosmos Bank" })).toBeVisible();
      await expect(page.getByRole("cell", { name: "Acme Savings" })).toBeHidden();
      await expect(page.getByRole("cell", { name: "Brookline Trust" })).toBeHidden();
    });

    test("filters by createdAt range (inclusive bounds, native date picker)", async ({ page }) => {
      await mockBanksApi(page, { list: "happy", list_banks: allBanks });
      await page.goto("/backoffice/banks");

      await page.getByTestId("banks-filters__toggle").click();
      await page.getByTestId("banks-filters__created-from").fill("2026-02-01");
      await page.getByTestId("banks-filters__created-to").fill("2026-03-31");

      await expect(page.getByRole("cell", { name: "Brookline Trust" })).toBeVisible();
      await expect(page.getByRole("cell", { name: "Cosmos Bank" })).toBeVisible();
      await expect(page.getByRole("cell", { name: "Acme Savings" })).toBeHidden();
      await expect(page.getByRole("cell", { name: "Delta Credit Union" })).toBeHidden();
    });

    test("created-from/to filter inputs are native date pickers", async ({ page }) => {
      await mockBanksApi(page, { list: "happy", list_banks: allBanks });
      await page.goto("/backoffice/banks");

      await page.getByTestId("banks-filters__toggle").click();
      await expect(page.getByTestId("banks-filters__created-from")).toHaveAttribute("type", "date");
      await expect(page.getByTestId("banks-filters__created-to")).toHaveAttribute("type", "date");
    });

    test("shows the no-matches panel when filters narrow to zero", async ({ page }) => {
      await mockBanksApi(page, { list: "happy", list_banks: allBanks });
      await page.goto("/backoffice/banks");

      await page.getByTestId("banks-filters__toggle").click();
      await page.getByTestId("banks-filters__name").fill("zzz-does-not-match");

      const panel = page.getByTestId("banks-list__empty-filtered");
      await expect(panel).toBeVisible();
      await expect(
        panel.getByRole("heading", { name: "No banks match your filters" }),
      ).toBeVisible();

      await page.getByTestId("banks-list__reset-filters").click();
      await expect(panel).toBeHidden();
      await expect(page.getByTestId("banks-filters__name")).toHaveValue("");
      await expect(page.getByRole("cell", { name: "Acme Savings" })).toBeVisible();
    });

    test("default sort is alphabetical name ascending (A → Z)", async ({ page }) => {
      await mockBanksApi(page, { list: "happy", list_banks: allBanks });
      await page.goto("/backoffice/banks");

      const firstRow = page.getByRole("row").nth(1); // row 0 is the header
      await expect(firstRow.getByRole("cell", { name: "Acme Savings", exact: true })).toBeVisible();
      await expect(page.getByRole("columnheader", { name: "Name", exact: true })).toHaveAttribute(
        "aria-sort",
        "ascending",
      );
    });

    test("name header cycles desc → unsorted → asc from the default ascending state", async ({
      page,
    }) => {
      await mockBanksApi(page, { list: "happy", list_banks: allBanks });
      await page.goto("/backoffice/banks");

      const nameHeader = page
        .getByRole("columnheader", { name: "Name", exact: true })
        .getByRole("button");
      const firstRow = page.getByRole("row").nth(1);
      const expectFirstRowToContain = (name: string) =>
        expect(firstRow.getByRole("cell", { name, exact: true })).toBeVisible();

      // Default state is asc; first click flips to desc.
      await nameHeader.click();
      await expectFirstRowToContain("Delta Credit Union");
      await expect(page.getByRole("columnheader", { name: "Name", exact: true })).toHaveAttribute(
        "aria-sort",
        "descending",
      );

      // Second click clears sort — rows fall back to API insertion order.
      await nameHeader.click();
      await expectFirstRowToContain(SAMPLE_BANK_A.name);
      await expect(page.getByRole("columnheader", { name: "Name", exact: true })).toHaveAttribute(
        "aria-sort",
        "none",
      );

      // Third click re-applies asc.
      await nameHeader.click();
      await expectFirstRowToContain("Acme Savings");
      await expect(page.getByRole("columnheader", { name: "Name", exact: true })).toHaveAttribute(
        "aria-sort",
        "ascending",
      );
    });

    test("the Updated column header is sortable", async ({ page }) => {
      await mockBanksApi(page, { list: "happy", list_banks: allBanks });
      await page.goto("/backoffice/banks");

      const updatedHeader = page.getByRole("columnheader", { name: "Updated", exact: true });
      await expect(updatedHeader).toBeVisible();
      const updatedSortBtn = updatedHeader.getByRole("button");
      await expect(updatedSortBtn).toBeVisible();

      const firstRow = page.getByRole("row").nth(1);

      // Click once → asc; SAMPLE_BANK_A has the earliest updatedAt (2026-04-15).
      await updatedSortBtn.click();
      await expect(updatedHeader).toHaveAttribute("aria-sort", "ascending");
      await expect(firstRow.getByRole("cell", { name: "Acme Savings", exact: true })).toBeVisible();

      // Click again → desc; SAMPLE_BANK_D has the latest updatedAt (2026-04-25).
      await updatedSortBtn.click();
      await expect(updatedHeader).toHaveAttribute("aria-sort", "descending");
      await expect(
        firstRow.getByRole("cell", { name: "Delta Credit Union", exact: true }),
      ).toBeVisible();
    });

    test("Reset returns to the default sort (name ascending) and clears all filters", async ({
      page,
    }) => {
      await mockBanksApi(page, { list: "happy", list_banks: allBanks });
      await page.goto("/backoffice/banks");

      // Drift away from the defaults.
      await page.getByTestId("banks-filters__toggle").click();
      await page.getByTestId("banks-filters__name").fill("cosmos");
      const nameHeader = page
        .getByRole("columnheader", { name: "Name", exact: true })
        .getByRole("button");
      await nameHeader.click(); // asc → desc

      await page.getByTestId("banks-filters__reset").click();

      await expect(page.getByTestId("banks-filters__name")).toHaveValue("");
      await expect(page.getByRole("columnheader", { name: "Name", exact: true })).toHaveAttribute(
        "aria-sort",
        "ascending",
      );
      const firstRow = page.getByRole("row").nth(1);
      await expect(firstRow.getByRole("cell", { name: "Acme Savings", exact: true })).toBeVisible();
    });

    test("combines name + createdFrom + sort by createdAt desc simultaneously", async ({
      page,
    }) => {
      await mockBanksApi(page, { list: "happy", list_banks: allBanks });
      await page.goto("/backoffice/banks");

      // Filter to anything containing "bank" in the name with createdAt >= 2026-03-01.
      // Of the four fixtures, only Cosmos Bank (name="Cosmos Bank", createdAt 2026-03-20) matches.
      // Adding sort by createdAt desc must not change correctness — just confirm the row remains.
      await page.getByTestId("banks-filters__toggle").click();
      await page.getByTestId("banks-filters__name").fill("bank");
      await page.getByTestId("banks-filters__created-from").fill("2026-03-01");

      const createdHeader = page
        .getByRole("columnheader", { name: "Created", exact: true })
        .getByRole("button");
      await createdHeader.click(); // asc
      await createdHeader.click(); // desc

      await expect(page.getByRole("cell", { name: "Cosmos Bank" })).toBeVisible();
      await expect(page.getByRole("cell", { name: "Acme Savings" })).toBeHidden();
      await expect(page.getByRole("cell", { name: "Brookline Trust" })).toBeHidden();
      await expect(page.getByRole("cell", { name: "Delta Credit Union" })).toBeHidden();
      await expect(
        page.getByRole("columnheader", { name: "Created", exact: true }),
      ).toHaveAttribute("aria-sort", "descending");
    });

    test("filters panel exposes Sort by + Direction selects mirroring the default table sort", async ({
      page,
    }) => {
      await mockBanksApi(page, { list: "happy", list_banks: allBanks });
      await page.goto("/backoffice/banks");

      await page.getByTestId("banks-filters__toggle").click();

      const sortBy = page.getByTestId("banks-filters__sort-by");
      const sortDirection = page.getByTestId("banks-filters__sort-direction");
      await expect(sortBy).toBeVisible();
      await expect(sortDirection).toBeVisible();
      // Defaults match DEFAULT_SORT (name ascending).
      await expect(sortBy).toHaveValue("name");
      await expect(sortDirection).toHaveValue("asc");
    });

    test("changing Sort by from the filters panel re-orders rows and updates the column header", async ({
      page,
    }) => {
      await mockBanksApi(page, { list: "happy", list_banks: allBanks });
      await page.goto("/backoffice/banks");

      await page.getByTestId("banks-filters__toggle").click();
      await page.getByTestId("banks-filters__sort-by").selectOption("createdAt");
      await page.getByTestId("banks-filters__sort-direction").selectOption("desc");

      // Table column header reflects the same sort (panel ↔ headers two-way sync).
      await expect(
        page.getByRole("columnheader", { name: "Created", exact: true }),
      ).toHaveAttribute("aria-sort", "descending");
      // Newest first: Delta Credit Union has the latest createdAt (2026-04-10) of the four fixtures.
      const firstRow = page.getByRole("row").nth(1);
      await expect(
        firstRow.getByRole("cell", { name: "Delta Credit Union", exact: true }),
      ).toBeVisible();
    });

    test("clicking a column header keeps the filters panel selects in sync", async ({ page }) => {
      await mockBanksApi(page, { list: "happy", list_banks: allBanks });
      await page.goto("/backoffice/banks");

      await page.getByTestId("banks-filters__toggle").click();
      // Panel reflects the default.
      await expect(page.getByTestId("banks-filters__sort-by")).toHaveValue("name");
      await expect(page.getByTestId("banks-filters__sort-direction")).toHaveValue("asc");

      // Click the Name header → asc cycles to desc.
      await page
        .getByRole("columnheader", { name: "Name", exact: true })
        .getByRole("button")
        .click();
      await expect(page.getByTestId("banks-filters__sort-direction")).toHaveValue("desc");

      // Click again → clears the sort entirely. Panel switches to "None" and disables Direction.
      await page
        .getByRole("columnheader", { name: "Name", exact: true })
        .getByRole("button")
        .click();
      await expect(page.getByTestId("banks-filters__sort-by")).toHaveValue("__none__");
      await expect(page.getByTestId("banks-filters__sort-direction")).toBeDisabled();
    });

    test("Sort by = None disables the Direction select and removes the column-header sort", async ({
      page,
    }) => {
      await mockBanksApi(page, { list: "happy", list_banks: allBanks });
      await page.goto("/backoffice/banks");

      await page.getByTestId("banks-filters__toggle").click();
      await page.getByTestId("banks-filters__sort-by").selectOption("__none__");

      await expect(page.getByTestId("banks-filters__sort-direction")).toBeDisabled();
      await expect(page.getByRole("columnheader", { name: "Name", exact: true })).toHaveAttribute(
        "aria-sort",
        "none",
      );
    });

    test("Reset button appears when sort drifts even without an active filter", async ({
      page,
    }) => {
      await mockBanksApi(page, { list: "happy", list_banks: allBanks });
      await page.goto("/backoffice/banks");

      await page.getByTestId("banks-filters__toggle").click();
      await expect(page.getByTestId("banks-filters__reset")).toHaveCount(0);

      await page.getByTestId("banks-filters__sort-direction").selectOption("desc");
      await expect(page.getByTestId("banks-filters__reset")).toBeVisible();

      await page.getByTestId("banks-filters__reset").click();
      await expect(page.getByTestId("banks-filters__sort-by")).toHaveValue("name");
      await expect(page.getByTestId("banks-filters__sort-direction")).toHaveValue("asc");
      await expect(page.getByTestId("banks-filters__reset")).toHaveCount(0);
    });

    test("sort selected in the panel applies to the cards view as well", async ({ page }) => {
      await mockBanksApi(page, { list: "happy", list_banks: allBanks });
      await page.goto("/backoffice/banks");

      // Switch to cards before changing sort — sort still applies.
      await page.getByTestId("banks-list__view-toggle-cards").click();
      await page.getByTestId("banks-filters__toggle").click();
      await page.getByTestId("banks-filters__sort-by").selectOption("createdAt");
      await page.getByTestId("banks-filters__sort-direction").selectOption("desc");

      // The first card in the grid is the most-recently-created bank.
      const firstCard = page.getByTestId("banks-cards").locator("li").first();
      await expect(firstCard).toHaveAttribute(
        "data-testid",
        `banks-cards__item-${SAMPLE_BANK_D.id}`,
      );
    });

    test("notice copy when meta.nextCursor is present calls out the page-only scope", async ({
      page,
    }) => {
      await mockBanksApi(page, {
        list: "happy",
        list_banks: allBanks,
        list_next_cursor: "next-page-cursor",
      });
      await page.goto("/backoffice/banks");

      await expect(
        page.getByText(
          "More banks available. Filters, sort, and pagination apply only to this page.",
        ),
      ).toBeVisible();
    });
  });

  test.describe("pagination", () => {
    test("defaults to 25 rows on page 1, with Prev hidden and Next visible", async ({ page }) => {
      await mockBanksApi(page, { list: "happy", list_banks: makeBanks(60) });
      await page.goto("/backoffice/banks");

      await expect(page.getByTestId("banks-pagination__indicator")).toHaveText("Page 1");
      await expect(page.getByTestId("banks-pagination__page-size")).toHaveValue("25");
      await expect(page.getByTestId("banks-pagination__prev")).toBeHidden();
      await expect(page.getByTestId("banks-pagination__next")).toBeVisible();
      await expect(page.getByRole("cell", { name: "Bank 001", exact: true })).toBeVisible();
      await expect(page.getByRole("cell", { name: "Bank 025", exact: true })).toBeVisible();
      await expect(page.getByRole("cell", { name: "Bank 026", exact: true })).toBeHidden();
      // Body has exactly 25 data rows.
      await expect(page.locator("tbody tr")).toHaveCount(25);
    });

    test("Next advances to page 2 with a fresh row set", async ({ page }) => {
      await mockBanksApi(page, { list: "happy", list_banks: makeBanks(60) });
      await page.goto("/backoffice/banks");

      await page.getByTestId("banks-pagination__next").click();

      await expect(page.getByTestId("banks-pagination__indicator")).toHaveText("Page 2");
      await expect(page.getByTestId("banks-pagination__prev")).toBeVisible();
      await expect(page.getByRole("cell", { name: "Bank 026", exact: true })).toBeVisible();
      await expect(page.getByRole("cell", { name: "Bank 050", exact: true })).toBeVisible();
      await expect(page.getByRole("cell", { name: "Bank 025", exact: true })).toBeHidden();
    });

    test("walks to the last page and hides Next; Prev returns to the previous page", async ({
      page,
    }) => {
      await mockBanksApi(page, { list: "happy", list_banks: makeBanks(60) });
      await page.goto("/backoffice/banks");

      const nextBtn = page.getByTestId("banks-pagination__next");
      await nextBtn.click();
      await nextBtn.click();

      await expect(page.getByTestId("banks-pagination__indicator")).toHaveText("Page 3");
      await expect(nextBtn).toBeHidden();
      await expect(page.getByRole("cell", { name: "Bank 060", exact: true })).toBeVisible();

      await page.getByTestId("banks-pagination__prev").click();
      await expect(page.getByTestId("banks-pagination__indicator")).toHaveText("Page 2");
      await expect(page.getByRole("cell", { name: "Bank 050", exact: true })).toBeVisible();
    });

    test("changing the page size to 50 collapses 60 rows into two pages", async ({ page }) => {
      await mockBanksApi(page, { list: "happy", list_banks: makeBanks(60) });
      await page.goto("/backoffice/banks");

      await page.getByTestId("banks-pagination__page-size").selectOption("50");

      await expect(page.locator("tbody tr")).toHaveCount(50);
      await expect(page.getByTestId("banks-pagination__indicator")).toHaveText("Page 1");
      await expect(page.getByTestId("banks-pagination__next")).toBeVisible();
      await page.getByTestId("banks-pagination__next").click();
      await expect(page.getByTestId("banks-pagination__indicator")).toHaveText("Page 2");
      await expect(page.getByTestId("banks-pagination__next")).toBeHidden();
      await expect(page.locator("tbody tr")).toHaveCount(10);
    });

    test("changing the page size resets to page 1", async ({ page }) => {
      await mockBanksApi(page, { list: "happy", list_banks: makeBanks(60) });
      await page.goto("/backoffice/banks");

      await page.getByTestId("banks-pagination__next").click();
      await expect(page.getByTestId("banks-pagination__indicator")).toHaveText("Page 2");

      await page.getByTestId("banks-pagination__page-size").selectOption("100");
      await expect(page.getByTestId("banks-pagination__indicator")).toHaveText("Page 1");
      await expect(page.getByTestId("banks-pagination__next")).toBeHidden();
      await expect(page.getByTestId("banks-pagination__prev")).toBeHidden();
      await expect(page.locator("tbody tr")).toHaveCount(60);
    });

    test("offers 25, 50, 100, 500, and 1000 in the page-size dropdown", async ({ page }) => {
      await mockBanksApi(page, { list: "happy", list_banks: makeBanks(30) });
      await page.goto("/backoffice/banks");

      const select = page.getByTestId("banks-pagination__page-size");
      // Read the options off the select element directly. `select.locator("option")`
      // can return zero matches when the dropdown is closed because Playwright's
      // locators auto-wait for visibility and option elements inside a closed
      // <select> are not laid out. Evaluating the <select>'s `.options` collection
      // sidesteps that entirely.
      const optionValues = await select.evaluate((node) =>
        Array.from((node as HTMLSelectElement).options).map((option) => option.value),
      );
      expect(optionValues).toEqual(["25", "50", "100", "500", "1000"]);

      // Sanity-check selecting each value still applies and clamps to page 1.
      for (const v of ["25", "50", "100", "500", "1000"]) {
        await select.selectOption(v);
        await expect(select).toHaveValue(v);
      }
    });

    test("typing a filter that narrows below the page size hides Prev and Next and resets to page 1", async ({
      page,
    }) => {
      await mockBanksApi(page, { list: "happy", list_banks: makeBanks(60) });
      await page.goto("/backoffice/banks");

      // Move off page 1 first to prove the filter resets it.
      await page.getByTestId("banks-pagination__next").click();
      await expect(page.getByTestId("banks-pagination__indicator")).toHaveText("Page 2");

      // "Bank 005" is unique → 1 match → both nav buttons hidden, indicator on page 1.
      await page.getByTestId("banks-filters__toggle").click();
      await page.getByTestId("banks-filters__name").fill("Bank 005");

      await expect(page.getByTestId("banks-pagination__indicator")).toHaveText("Page 1");
      await expect(page.getByTestId("banks-pagination__prev")).toBeHidden();
      await expect(page.getByTestId("banks-pagination__next")).toBeHidden();
      await expect(page.getByRole("cell", { name: "Bank 005", exact: true })).toBeVisible();
      await expect(page.getByRole("cell", { name: "Bank 015", exact: true })).toBeHidden();
    });

    test("sorting resets the indicator to page 1", async ({ page }) => {
      await mockBanksApi(page, { list: "happy", list_banks: makeBanks(60) });
      await page.goto("/backoffice/banks");

      await page.getByTestId("banks-pagination__next").click();
      await expect(page.getByTestId("banks-pagination__indicator")).toHaveText("Page 2");

      await page
        .getByRole("columnheader", { name: "Name", exact: true })
        .getByRole("button")
        .click();

      await expect(page.getByTestId("banks-pagination__indicator")).toHaveText("Page 1");
    });

    test("renders the pagination bar with no nav buttons when the list fits on one page", async ({
      page,
    }) => {
      await mockBanksApi(page, { list: "happy", list_banks: makeBanks(5) });
      await page.goto("/backoffice/banks");

      await expect(page.getByRole("cell", { name: "Bank 005", exact: true })).toBeVisible();
      await expect(page.getByTestId("banks-pagination__indicator")).toHaveText("Page 1");
      await expect(page.getByTestId("banks-pagination__page-size")).toBeVisible();
      await expect(page.getByTestId("banks-pagination__prev")).toBeHidden();
      await expect(page.getByTestId("banks-pagination__next")).toBeHidden();
    });

    test("nextCursor notice text mentions pagination too", async ({ page }) => {
      await mockBanksApi(page, {
        list: "happy",
        list_banks: makeBanks(60),
        list_next_cursor: "next-page-cursor",
      });
      await page.goto("/backoffice/banks");

      await expect(
        page.getByText(
          "More banks available. Filters, sort, and pagination apply only to this page.",
        ),
      ).toBeVisible();
    });
  });

  test.describe("view toggle (table vs cards)", () => {
    test("defaults to the table view on a fresh visit", async ({ page }) => {
      await mockBanksApi(page, { list: "happy" });
      await page.goto("/backoffice/banks");

      const toggle = page.getByTestId("banks-list__view-toggle");
      const tableButton = page.getByTestId("banks-list__view-toggle-table");
      const cardsButton = page.getByTestId("banks-list__view-toggle-cards");

      await expect(toggle).toBeVisible();
      await expect(tableButton).toHaveAttribute("aria-pressed", "true");
      await expect(cardsButton).toHaveAttribute("aria-pressed", "false");

      await expect(page.getByTestId("banks-table")).toBeVisible();
      await expect(page.getByTestId("banks-cards")).toBeHidden();
    });

    test("switching to cards renders a card per bank with mirrored actions", async ({ page }) => {
      await mockBanksApi(page, { list: "happy" });
      await page.goto("/backoffice/banks");

      await page.getByTestId("banks-list__view-toggle-cards").click();

      await expect(page.getByTestId("banks-list__view-toggle-cards")).toHaveAttribute(
        "aria-pressed",
        "true",
      );
      await expect(page.getByTestId("banks-list__view-toggle-table")).toHaveAttribute(
        "aria-pressed",
        "false",
      );

      // Table is gone, cards are visible.
      await expect(page.getByTestId("banks-table")).toBeHidden();
      await expect(page.getByTestId("banks-cards")).toBeVisible();

      // The two sample banks are both rendered as cards with the same action set
      // as the table (copy / edit / delete).
      await expect(page.getByTestId(`banks-cards__item-${SAMPLE_BANK_A.id}`)).toBeVisible();
      await expect(page.getByTestId(`banks-cards__item-${SAMPLE_BANK_B.id}`)).toBeVisible();
      await expect(page.getByTestId(`banks-cards__name-${SAMPLE_BANK_A.id}`)).toHaveText(
        SAMPLE_BANK_A.name,
      );
      await expect(page.getByTestId(`banks-cards__edit-${SAMPLE_BANK_A.id}`)).toBeVisible();
      await expect(page.getByTestId(`banks-cards__copy-${SAMPLE_BANK_A.id}`)).toBeVisible();
      await expect(page.getByTestId(`banks-cards__actions-${SAMPLE_BANK_A.id}`)).toBeVisible();
    });

    test("clicking a card's name navigates to the detail page", async ({ page }) => {
      await mockBanksApi(page, { list: "happy", get: "happy", bank: SAMPLE_BANK_A });
      await page.goto("/backoffice/banks");

      await page.getByTestId("banks-list__view-toggle-cards").click();
      await page.getByTestId(`banks-cards__name-${SAMPLE_BANK_A.id}`).click();

      await expect(page).toHaveURL(`/backoffice/banks/${SAMPLE_BANK_A.id}`);
    });

    test("inline delete from the cards view removes the card without navigating away", async ({
      page,
    }) => {
      await mockBanksApi(page, { list: "happy", delete: "happy", bank: SAMPLE_BANK_A });
      await page.goto("/backoffice/banks");

      await page.getByTestId("banks-list__view-toggle-cards").click();
      await expect(page.getByTestId(`banks-cards__item-${SAMPLE_BANK_A.id}`)).toBeVisible();

      await page.getByTestId(`banks-cards__actions-${SAMPLE_BANK_A.id}`).click();
      await page.getByTestId(`banks-cards__delete-${SAMPLE_BANK_A.id}`).click();
      await page.getByTestId("banks-detail__delete-confirm").click();

      await expect(page).toHaveURL(/\/backoffice\/banks$/);
      await expect(page.getByTestId(`banks-cards__item-${SAMPLE_BANK_A.id}`)).toHaveCount(0);
      await expect(page.getByTestId(`banks-cards__item-${SAMPLE_BANK_B.id}`)).toBeVisible();
      // Same acknowledgement as the table view: a success toast naming the bank.
      await expect(page.getByText("Bank deleted")).toBeVisible();
      await expect(page.getByText(SAMPLE_BANK_A.name)).toBeVisible();
    });

    test("filters and pagination still apply to the cards view", async ({ page }) => {
      const allBanks = [SAMPLE_BANK_A, SAMPLE_BANK_B, SAMPLE_BANK_C, SAMPLE_BANK_D];
      await mockBanksApi(page, { list: "happy", list_banks: allBanks });
      await page.goto("/backoffice/banks");

      await page.getByTestId("banks-list__view-toggle-cards").click();
      await page.getByTestId("banks-filters__toggle").click();
      await page.getByTestId("banks-filters__name").fill("cosmos");

      await expect(page.getByTestId(`banks-cards__item-${SAMPLE_BANK_C.id}`)).toBeVisible();
      await expect(page.getByTestId(`banks-cards__item-${SAMPLE_BANK_A.id}`)).toHaveCount(0);
      await expect(page.getByTestId(`banks-cards__item-${SAMPLE_BANK_B.id}`)).toHaveCount(0);
      await expect(page.getByTestId(`banks-cards__item-${SAMPLE_BANK_D.id}`)).toHaveCount(0);
    });

    test("the user's view preference persists across reloads via localStorage", async ({
      page,
    }) => {
      await mockBanksApi(page, { list: "happy" });
      await page.goto("/backoffice/banks");

      await page.getByTestId("banks-list__view-toggle-cards").click();
      await expect(page.getByTestId("banks-cards")).toBeVisible();

      const stored = await page.evaluate(() =>
        globalThis.localStorage.getItem("erpify:banks-view"),
      );
      expect(stored).toBe("cards");

      await page.reload();
      await expect(page.getByTestId("banks-list")).toHaveAttribute("data-state", "ready");
      await expect(page.getByTestId("banks-list__view-toggle-cards")).toHaveAttribute(
        "aria-pressed",
        "true",
      );
      await expect(page.getByTestId("banks-cards")).toBeVisible();
      await expect(page.getByTestId("banks-table")).toBeHidden();

      // Switching back persists too.
      await page.getByTestId("banks-list__view-toggle-table").click();
      await expect(
        await page.evaluate(() => globalThis.localStorage.getItem("erpify:banks-view")),
      ).toBe("table");
    });

    test("ignores an unknown localStorage value and falls back to the table default", async ({
      page,
    }) => {
      await page.addInitScript(() => {
        globalThis.localStorage.setItem("erpify:banks-view", "not-a-real-view");
      });
      await mockBanksApi(page, { list: "happy" });
      await page.goto("/backoffice/banks");

      await expect(page.getByTestId("banks-list__view-toggle-table")).toHaveAttribute(
        "aria-pressed",
        "true",
      );
      await expect(page.getByTestId("banks-table")).toBeVisible();
    });
  });

  test.describe("responsive", () => {
    test.use({ viewport: VIEWPORT_MOBILE });

    test("on mobile, Created and Updated columns are hidden but actions remain reachable", async ({
      page,
    }) => {
      await mockBanksApi(page, { list: "happy" });
      await page.goto("/backoffice/banks");

      await expect(
        page.getByRole("columnheader", { name: "Short name", exact: true }),
      ).toBeVisible();
      await expect(page.getByRole("columnheader", { name: "Name", exact: true })).toBeVisible();
      await expect(page.getByRole("columnheader", { name: "Actions", exact: true })).toBeVisible();
      await expect(page.getByRole("columnheader", { name: "Created", exact: true })).toBeHidden();
      await expect(page.getByRole("columnheader", { name: "Updated", exact: true })).toBeHidden();

      await expect(page.getByTestId(`banks-table__edit-${SAMPLE_BANK_A.id}`)).toBeVisible();
      await expect(page.getByTestId(`banks-table__actions-${SAMPLE_BANK_A.id}`)).toBeVisible();
    });

    test("on mobile, the Filters toggle is full-width, opens the panel, and exposes the count badge", async ({
      page,
    }) => {
      await mockBanksApi(page, { list: "happy" });
      await page.goto("/backoffice/banks");

      const toggle = page.getByTestId("banks-filters__toggle");
      await expect(toggle).toBeVisible();
      // Spans the row on mobile (≥ 320px). Allow a small tolerance for borders/padding.
      const toggleBox = await toggle.boundingBox();
      expect(toggleBox).not.toBeNull();
      expect(toggleBox!.width).toBeGreaterThan(VIEWPORT_MOBILE.width * 0.6);

      // Panel collapsed by default on mobile too.
      await expect(page.getByTestId("banks-filters__panel")).toBeHidden();
      await expect(page.getByTestId("banks-filters__reset")).toHaveCount(0);

      await toggle.click();
      await expect(page.getByTestId("banks-filters__panel")).toBeVisible();

      await page.getByTestId("banks-filters__name").fill("acme");
      await expect(page.getByTestId("banks-filters__count")).toHaveText("1");
      await expect(page.getByTestId("banks-filters__reset")).toBeVisible();
    });

    test("on mobile, switching to cards renders a single-column stack", async ({ page }) => {
      await mockBanksApi(page, { list: "happy" });
      await page.goto("/backoffice/banks");

      await page.getByTestId("banks-list__view-toggle-cards").click();

      const cardA = await page.getByTestId(`banks-cards__item-${SAMPLE_BANK_A.id}`).boundingBox();
      const cardB = await page.getByTestId(`banks-cards__item-${SAMPLE_BANK_B.id}`).boundingBox();
      expect(cardA).not.toBeNull();
      expect(cardB).not.toBeNull();
      // Stacked: second card sits below the first (its y is larger than card A's bottom).
      expect(cardB!.y).toBeGreaterThanOrEqual(cardA!.y + cardA!.height - 1);
      // Each card spans most of the mobile viewport width (single column).
      expect(cardA!.width).toBeGreaterThan(VIEWPORT_MOBILE.width * 0.7);
    });
  });

  test.describe("nav", () => {
    test("Banking > Banks appears in the sidebar and links to the list", async ({ page }) => {
      await mockBanksApi(page, { list: "empty" });
      await page.goto("/backoffice");

      const aside = page.locator("aside");
      const banksItem = aside.getByRole("button", { name: "Banks" });
      await expect(banksItem).toBeVisible();
      await banksItem.click();
      await expect(page).toHaveURL("/backoffice/banks");
    });
  });
});
