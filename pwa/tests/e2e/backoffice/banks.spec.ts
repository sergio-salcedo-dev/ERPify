import { test, expect } from "@playwright/test";
import { VIEWPORT_DESKTOP } from "../constants";
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

      await expect(page.getByRole("heading", { name: "Banks", level: 1 })).toBeVisible();
      await expect(page.getByRole("link", { name: /New bank/i })).toBeVisible();
      await expect(page.getByRole("cell", { name: "ACME", exact: true })).toBeVisible();
      await expect(page.getByRole("cell", { name: "Acme Savings", exact: true })).toBeVisible();
      await expect(page.getByRole("cell", { name: "BRT", exact: true })).toBeVisible();
    });

    test("shows the empty state when there are no banks", async ({ page }) => {
      await mockBanksApi(page, { list: "empty" });
      await page.goto("/backoffice/banks");

      await expect(page.getByRole("heading", { name: "No banks yet" })).toBeVisible();
      await expect(page.getByRole("link", { name: "Create your first bank" })).toBeVisible();
    });
  });

  test.describe("view", () => {
    test("renders bank details on the happy path", async ({ page }) => {
      await mockBanksApi(page, { get: "happy", bank: SAMPLE_BANK_A });
      await page.goto(`/backoffice/banks/${SAMPLE_BANK_A.id}`);

      await expect(page.getByRole("heading", { name: SAMPLE_BANK_A.name })).toBeVisible();
      await expect(page.getByTestId("banks-detail__shortname")).toHaveText(SAMPLE_BANK_A.shortName);
      await expect(page.getByTestId("banks-detail__edit-button")).toBeVisible();
      await expect(page.getByTestId("banks-detail__delete-button")).toBeVisible();
    });

    test("shows EmptyState + correlation chip on 404 from the legacy envelope", async ({
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

    test("surfaces 422 violations as field-level errors via the translator", async ({ page }) => {
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

    test("surfaces 422 from the legacy envelope on update", async ({ page }) => {
      await mockBanksApi(page, { get: "happy", update: "validation-error", bank: SAMPLE_BANK_A });
      await page.goto(`/backoffice/banks/${SAMPLE_BANK_A.id}/edit`);

      const overlong = "x".repeat(60);
      await page.getByTestId("bank-form__short-name").fill(overlong);
      await page.getByTestId("bank-form__submit").click();

      await expect(page.getByText("The shortName must not exceed 50 characters.")).toBeVisible();
    });
  });

  test.describe("delete", () => {
    test("deletes a bank and redirects to the list", async ({ page }) => {
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

    test("filters by name case-insensitively, leaves the URL unchanged, and resets via the button", async ({
      page,
    }) => {
      await mockBanksApi(page, { list: "happy", list_banks: allBanks });
      await page.goto("/backoffice/banks");

      await expect(page.getByRole("cell", { name: "Acme Savings" })).toBeVisible();
      await expect(page.getByRole("cell", { name: "Cosmos Bank" })).toBeVisible();

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

      await page.getByTestId("banks-filters__name").fill("bank");
      await page.getByTestId("banks-filters__short-name").fill("cos");

      await expect(page.getByRole("cell", { name: "Cosmos Bank" })).toBeVisible();
      await expect(page.getByRole("cell", { name: "Acme Savings" })).toBeHidden();
      await expect(page.getByRole("cell", { name: "Brookline Trust" })).toBeHidden();
    });

    test("filters by createdAt range (inclusive bounds)", async ({ page }) => {
      await mockBanksApi(page, { list: "happy", list_banks: allBanks });
      await page.goto("/backoffice/banks");

      await page.getByTestId("banks-filters__created-from").fill("2026-02-01");
      await page.getByTestId("banks-filters__created-to").fill("2026-03-31");

      await expect(page.getByRole("cell", { name: "Brookline Trust" })).toBeVisible();
      await expect(page.getByRole("cell", { name: "Cosmos Bank" })).toBeVisible();
      await expect(page.getByRole("cell", { name: "Acme Savings" })).toBeHidden();
      await expect(page.getByRole("cell", { name: "Delta Credit Union" })).toBeHidden();
    });

    test("shows the no-matches panel when filters narrow to zero", async ({ page }) => {
      await mockBanksApi(page, { list: "happy", list_banks: allBanks });
      await page.goto("/backoffice/banks");

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

    test("sorts by name with the asc → desc → unsorted cycle", async ({ page }) => {
      await mockBanksApi(page, { list: "happy", list_banks: allBanks });
      await page.goto("/backoffice/banks");

      const nameHeader = page
        .getByRole("columnheader", { name: "Name", exact: true })
        .getByRole("button");
      // Resilient to column reorder: assert the first body row contains the expected name cell.
      const firstRow = page.getByRole("row").nth(1); // row 0 is the header
      const expectFirstRowToContain = (name: string) =>
        expect(firstRow.getByRole("cell", { name, exact: true })).toBeVisible();

      await nameHeader.click();
      await expectFirstRowToContain("Acme Savings");
      await expect(page.getByRole("columnheader", { name: "Name", exact: true })).toHaveAttribute(
        "aria-sort",
        "ascending",
      );

      await nameHeader.click();
      await expectFirstRowToContain("Delta Credit Union");
      await expect(page.getByRole("columnheader", { name: "Name", exact: true })).toHaveAttribute(
        "aria-sort",
        "descending",
      );

      await nameHeader.click();
      await expectFirstRowToContain(SAMPLE_BANK_A.name);
      await expect(page.getByRole("columnheader", { name: "Name", exact: true })).toHaveAttribute(
        "aria-sort",
        "none",
      );
    });

    test("combines name + createdFrom + sort by createdAt desc simultaneously", async ({
      page,
    }) => {
      await mockBanksApi(page, { list: "happy", list_banks: allBanks });
      await page.goto("/backoffice/banks");

      // Filter to anything containing "bank" in the name with createdAt >= 2026-03-01.
      // Of the four fixtures, only Cosmos Bank (name="Cosmos Bank", createdAt 2026-03-20) matches.
      // Adding sort by createdAt desc must not change correctness — just confirm the row remains.
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
    const fiftyBanks = makeBanks(50);

    test("renders 10 rows on page 1 with Prev disabled and 'Page 1 of 5'", async ({ page }) => {
      await mockBanksApi(page, { list: "happy", list_banks: fiftyBanks });
      await page.goto("/backoffice/banks");

      await expect(page.getByTestId("banks-pagination__indicator")).toHaveText("Page 1 of 5");
      await expect(page.getByTestId("banks-pagination__prev")).toBeDisabled();
      await expect(page.getByTestId("banks-pagination__next")).toBeEnabled();
      await expect(page.getByRole("cell", { name: "Bank 001", exact: true })).toBeVisible();
      await expect(page.getByRole("cell", { name: "Bank 010", exact: true })).toBeVisible();
      await expect(page.getByRole("cell", { name: "Bank 011", exact: true })).toBeHidden();
      // Body has exactly 10 data rows.
      await expect(page.locator("tbody tr")).toHaveCount(10);
    });

    test("Next advances to page 2 with a fresh row set", async ({ page }) => {
      await mockBanksApi(page, { list: "happy", list_banks: fiftyBanks });
      await page.goto("/backoffice/banks");

      await page.getByTestId("banks-pagination__next").click();

      await expect(page.getByTestId("banks-pagination__indicator")).toHaveText("Page 2 of 5");
      await expect(page.getByTestId("banks-pagination__prev")).toBeEnabled();
      await expect(page.getByRole("cell", { name: "Bank 011", exact: true })).toBeVisible();
      await expect(page.getByRole("cell", { name: "Bank 020", exact: true })).toBeVisible();
      await expect(page.getByRole("cell", { name: "Bank 010", exact: true })).toBeHidden();
    });

    test("walks to the last page and disables Next; Prev returns to page 4", async ({ page }) => {
      await mockBanksApi(page, { list: "happy", list_banks: fiftyBanks });
      await page.goto("/backoffice/banks");

      const nextBtn = page.getByTestId("banks-pagination__next");
      for (let i = 0; i < 4; i++) {
        await nextBtn.click();
      }

      await expect(page.getByTestId("banks-pagination__indicator")).toHaveText("Page 5 of 5");
      await expect(nextBtn).toBeDisabled();
      await expect(page.getByRole("cell", { name: "Bank 050", exact: true })).toBeVisible();

      await page.getByTestId("banks-pagination__prev").click();
      await expect(page.getByTestId("banks-pagination__indicator")).toHaveText("Page 4 of 5");
      await expect(page.getByRole("cell", { name: "Bank 040", exact: true })).toBeVisible();
    });

    test("typing a filter that narrows below the page size hides the pagination block", async ({
      page,
    }) => {
      await mockBanksApi(page, { list: "happy", list_banks: fiftyBanks });
      await page.goto("/backoffice/banks");

      // Move off page 1 first to prove the filter resets it.
      await page.getByTestId("banks-pagination__next").click();
      await expect(page.getByTestId("banks-pagination__indicator")).toHaveText("Page 2 of 5");

      // "Bank 005" is unique → 1 match → pagination block hides entirely.
      await page.getByTestId("banks-filters__name").fill("Bank 005");

      await expect(page.getByTestId("banks-pagination__indicator")).toBeHidden();
      await expect(page.getByRole("cell", { name: "Bank 005", exact: true })).toBeVisible();
      await expect(page.getByRole("cell", { name: "Bank 015", exact: true })).toBeHidden();
    });

    test("a filter narrowing to >10 still paginates and resets to page 1", async ({ page }) => {
      await mockBanksApi(page, { list: "happy", list_banks: fiftyBanks });
      await page.goto("/backoffice/banks");

      // Move off page 1 first.
      await page.getByTestId("banks-pagination__next").click();
      await page.getByTestId("banks-pagination__next").click();
      await expect(page.getByTestId("banks-pagination__indicator")).toHaveText("Page 3 of 5");

      // makeBanks walks createdAt forward one UTC day from 2026-01-01, so Bank 040 = 2026-02-09.
      // createdFrom = 2026-02-09 keeps Bank 040..050 = 11 rows → 2 pages of 10 + 1.
      await page.getByTestId("banks-filters__created-from").fill("2026-02-09");

      await expect(page.getByTestId("banks-pagination__indicator")).toHaveText("Page 1 of 2");
      await expect(page.getByTestId("banks-pagination__prev")).toBeDisabled();
    });

    test("AC: navigates to page 4, then filters to 8 matches → block hides, 8 rows render", async ({
      page,
    }) => {
      await mockBanksApi(page, { list: "happy", list_banks: fiftyBanks });
      await page.goto("/backoffice/banks");

      const nextBtn = page.getByTestId("banks-pagination__next");
      for (let i = 0; i < 3; i++) {
        await nextBtn.click();
      }
      await expect(page.getByTestId("banks-pagination__indicator")).toHaveText("Page 4 of 5");

      // shortName "BNK04" matches BNK040..BNK049 → 10 matches; need 8.
      // shortName "BNK04" then exclude two via createdFrom 2026-02-11 (Bank 042 onward) → 8 matches.
      await page.getByTestId("banks-filters__short-name").fill("BNK04");
      await page.getByTestId("banks-filters__created-from").fill("2026-02-11");

      await expect(page.getByTestId("banks-pagination__indicator")).toBeHidden();
      await expect(page.locator("tbody tr")).toHaveCount(8);
      await expect(page.getByRole("cell", { name: "Bank 042", exact: true })).toBeVisible();
      await expect(page.getByRole("cell", { name: "Bank 049", exact: true })).toBeVisible();
      await expect(page.getByRole("cell", { name: "Bank 041", exact: true })).toBeHidden();
    });

    test("sorting resets the indicator to page 1", async ({ page }) => {
      await mockBanksApi(page, { list: "happy", list_banks: fiftyBanks });
      await page.goto("/backoffice/banks");

      await page.getByTestId("banks-pagination__next").click();
      await page.getByTestId("banks-pagination__next").click();
      await expect(page.getByTestId("banks-pagination__indicator")).toHaveText("Page 3 of 5");

      await page
        .getByRole("columnheader", { name: "Name", exact: true })
        .getByRole("button")
        .click();

      await expect(page.getByTestId("banks-pagination__indicator")).toHaveText("Page 1 of 5");
    });

    test("does not render the pagination block when the source list fits on one page", async ({
      page,
    }) => {
      await mockBanksApi(page, { list: "happy", list_banks: makeBanks(5) });
      await page.goto("/backoffice/banks");

      await expect(page.getByRole("cell", { name: "Bank 005", exact: true })).toBeVisible();
      await expect(page.getByTestId("banks-pagination__indicator")).toBeHidden();
      await expect(page.getByTestId("banks-pagination__prev")).toBeHidden();
      await expect(page.getByTestId("banks-pagination__next")).toBeHidden();
    });

    test("nextCursor notice text mentions pagination too", async ({ page }) => {
      await mockBanksApi(page, {
        list: "happy",
        list_banks: makeBanks(50),
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

  test.describe("nav", () => {
    test("Catalogs > Banks appears in the sidebar and links to the list", async ({ page }) => {
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
