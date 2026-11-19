import { test, expect, type APIRequestContext } from "../fixtures/authenticatedTest";
import { VIEWPORT_DESKTOP } from "../constants";
import {
  createApiContext,
  createBank,
  deleteBanksSafely,
  filterByName,
  uniqueRunPrefix,
  type ApiBank,
} from "../fixtures/banks-real-api";

/**
 * Real-API E2E for the bank logo: a back-office user with `bank.write` sets, replaces and removes a
 * bank's logo from the edit screen, and the list and the detail, loaded afterwards in the same
 * client, show each state. No refetch drives the edit screen: it shows the id the write answered.
 *
 * Needs the full stack (`make docker.up.wait`); the write goes through Caddy to
 * `PUT|DELETE /api/v1/backoffice/banks/{id}/logo`. Other open clients learn of a logo-only change
 * on their next load (no realtime push), so no assertion here waits for one.
 */
test.describe.configure({ mode: "serial" });

// Two valid, distinct 1x1 PNGs, so a replace changes the digest as well as the id.
const PNG_A = Buffer.from(
  "iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==",
  "base64",
);
const PNG_B = Buffer.from(
  "iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M/wHwAEBgIApD5fRAAAAABJRU5ErkJggg==",
  "base64",
);

const upload = (name: string, buffer: Buffer) => ({ name, mimeType: "image/png", buffer });

test.describe("BackOffice - Bank logo (real API)", () => {
  test.use({ viewport: VIEWPORT_DESKTOP });

  const runPrefix = uniqueRunPrefix("BankLogo");
  let api: APIRequestContext;
  let bank: ApiBank;

  test.beforeAll(async ({ workerStorageState }) => {
    api = await createApiContext(workerStorageState);
    bank = await createBank(api, `${runPrefix} Logo Bank`, runPrefix.slice(-40));
  });

  test.afterAll(async () => {
    await deleteBanksSafely(api, [bank.id]);
    await api.dispose();
  });

  test("a bank without a logo shows the placeholder, and the edit screen offers an upload", async ({
    page,
  }) => {
    await page.goto(`/backoffice/banks/${bank.id}`);
    await expect(page.getByTestId("banks-detail__logo__placeholder")).toBeVisible();

    await page.goto(`/backoffice/banks/${bank.id}/edit`);
    await expect(page.getByTestId("banks-edit__logo-field__pick")).toHaveAttribute(
      "aria-label",
      "Upload",
    );
    await expect(page.getByTestId("banks-edit__logo-field__remove")).toHaveCount(0);
  });

  test("set, replace and remove through the edit screen, and both screens follow", async ({
    page,
  }) => {
    await page.goto(`/backoffice/banks/${bank.id}/edit`);
    const field = page.getByTestId("banks-edit__logo-field");
    const preview = page.getByTestId("banks-edit__logo-field__preview");
    await expect(field).toBeVisible();

    // Set.
    await page.getByTestId("banks-edit__logo-field__input").setInputFiles(upload("a.png", PNG_A));
    await expect(preview).toHaveAttribute("data-image-state", "loaded");
    const firstSrc = await page
      .getByTestId("banks-edit__logo-field__preview__image")
      .getAttribute("src");
    expect(firstSrc).toMatch(/\/api\/v1\/images\/[0-9a-f-]{36}$/);

    // Replace: a new id, hence a new URL.
    await page.getByTestId("banks-edit__logo-field__input").setInputFiles(upload("b.png", PNG_B));
    await expect(preview).toHaveAttribute("data-image-state", "loaded");
    await expect(page.getByTestId("banks-edit__logo-field__preview__image")).not.toHaveAttribute(
      "src",
      firstSrc ?? "",
    );
    const secondSrc = await page
      .getByTestId("banks-edit__logo-field__preview__image")
      .getAttribute("src");

    // The detail, loaded afterwards, shows the replaced logo.
    await page.goto(`/backoffice/banks/${bank.id}`);
    await expect(page.getByTestId("banks-detail__logo")).toHaveAttribute(
      "data-image-state",
      "loaded",
    );
    await expect(page.getByTestId("banks-detail__logo__image")).toHaveAttribute(
      "src",
      secondSrc ?? "",
    );

    // So does the list.
    await page.goto("/backoffice/banks");
    await expect(page.getByTestId("banks-list")).toHaveAttribute("data-state", "ready");
    await page.getByTestId("banks-filters__toggle").click();
    await filterByName(page, runPrefix);
    await expect(page.getByTestId(`banks-table__logo-${bank.id}`)).toHaveAttribute(
      "data-image-state",
      "loaded",
    );

    // Remove.
    await page.goto(`/backoffice/banks/${bank.id}/edit`);
    await page.getByTestId("banks-edit__logo-field__remove").click();
    await expect(page.getByTestId("banks-edit__logo-field__remove")).toHaveCount(0);
    await expect(page.getByTestId("banks-edit__logo-field__preview__placeholder")).toBeVisible();

    await page.goto(`/backoffice/banks/${bank.id}`);
    await expect(page.getByTestId("banks-detail__logo__placeholder")).toBeVisible();
    await expect(page.getByTestId("banks-detail__logo__image")).toHaveCount(0);
  });

  test("a name typed and not yet saved survives a logo change", async ({ page }) => {
    await page.goto(`/backoffice/banks/${bank.id}/edit`);
    const name = page.getByTestId("bank-form__name");
    await name.fill(`${runPrefix} Typed`);

    await page.getByTestId("banks-edit__logo-field__input").setInputFiles(upload("a.png", PNG_A));
    await expect(page.getByTestId("banks-edit__logo-field__preview")).toHaveAttribute(
      "data-image-state",
      "loaded",
    );

    await expect(name).toHaveValue(`${runPrefix} Typed`);
  });
});
