import { test, expect } from "@playwright/test";
import { VIEWPORT_DESKTOP } from "../constants";
import { SAMPLE_BANK_A, mockBanksApi } from "../fixtures/banks-api";

test.describe("BackOffice - Banks CRUD", () => {
  test.use({ viewport: VIEWPORT_DESKTOP });

  test.describe("list", () => {
    test("renders rows from the API", async ({ page }) => {
      await mockBanksApi(page, { list: "happy" });
      await page.goto("/backoffice/banks");

      await expect(page.getByRole("heading", { name: "Banks", level: 1 })).toBeVisible();
      await expect(page.getByRole("link", { name: /New bank/i })).toBeVisible();
      await expect(page.getByText("ACME")).toBeVisible();
      await expect(page.getByText("Acme Savings")).toBeVisible();
      await expect(page.getByText("BRT")).toBeVisible();
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
      await expect(page.getByRole("alert").getByText("Bank not found.")).toBeVisible();
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
