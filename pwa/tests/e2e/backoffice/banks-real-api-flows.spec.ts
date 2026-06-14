import { test, expect, type APIRequestContext } from "@playwright/test";
import { VIEWPORT_DESKTOP } from "../constants";
import {
  createApiContext,
  createBank,
  deleteBanksSafely,
  filterByName,
  seedBanks,
  uniqueRunPrefix,
  type ApiBank,
} from "../fixtures/banks-real-api";

/**
 * Real-API E2E coverage for the Banks CRUD flow, complementary to
 * `banks-real-api.spec.ts`. The other suite walks the whole flow as a
 * single serial test through the **detail-page** edit / delete buttons;
 * this one splits the same concerns into focused tests and drives the
 * **inline row actions** (`banks-table__edit-*` / `banks-table__delete-*`)
 * plus the short-name filter / sort and the form validation surfaced
 * by the live API's 422 responses — paths the original suite does not
 * exercise.
 *
 * Strategy
 * - `beforeAll` seeds 30 banks under a unique run-scoped prefix via
 *   POST /api/v1/backoffice/banks. 30 rows let us assert pagination at
 *   the default page size of 25 (page 1 -> 25, page 2 -> 5) — a
 *   different boundary than `banks-real-api.spec.ts` (26 rows -> 25/1).
 * - The list filter is client-side and the API ships up to 1000 banks
 *   per page, so a name-prefix filter reliably narrows the UI to
 *   test-owned rows even when the dev DB already holds unrelated
 *   banks.
 * - Tests run in `serial` mode because the create / update / delete
 *   tests share the bank id created by the create test. Every id we
 *   touch is tracked so `afterAll` deletes it via the API even if a
 *   later assertion fails.
 *
 * Running locally requires the full stack (`make docker.up.wait`) — the
 * webServer config in `playwright.config.ts` picks up
 * `NEXT_PUBLIC_API_BASE_URL=https://localhost`.
 */
test.describe.configure({ mode: "serial" });

test.describe("BackOffice - Banks per-flow CRUD (real API)", () => {
  test.use({ viewport: VIEWPORT_DESKTOP });

  const SEED_COUNT = 30;
  const runPrefix = uniqueRunPrefix("BankFlows");
  const trackedIds: string[] = [];
  let api: APIRequestContext;
  let seeded: ApiBank[] = [];
  let createdId: string | null = null;

  test.beforeAll(async () => {
    api = await createApiContext();
    seeded = await seedBanks(api, runPrefix, SEED_COUNT);
    trackedIds.push(...seeded.map((bank) => bank.id));
  });

  test.afterAll(async () => {
    await deleteBanksSafely(api, trackedIds);
    await api.dispose();
  });

  test("list — renders seeded rows", async ({ page }) => {
    await page.goto("/backoffice/banks");

    const list = page.getByTestId("banks-list");
    await expect(list).toHaveAttribute("data-state", "ready");
    await expect(page.getByTestId("banks-table")).toBeVisible();
    await expect(page.getByTestId("banks-list__title")).toHaveText("Banks");

    // Narrow the client-side filter to test-owned rows. The filters panel
    // is collapsed by default, so reveal it before driving its inputs.
    await page.getByTestId("banks-filters__toggle").click();
    await filterByName(page, runPrefix);

    // Default sort is name ascending — first row = `<prefix> 001`.
    const firstSeeded = `${runPrefix} 001`;
    await expect(
      page.locator("tbody tr").first().getByRole("cell", { name: firstSeeded, exact: true }),
    ).toBeVisible();
  });

  test("filter — short-name input narrows the list to a single seeded row", async ({ page }) => {
    await page.goto("/backoffice/banks");
    await expect(page.getByTestId("banks-list")).toHaveAttribute("data-state", "ready");

    // Bump the page size so the post-reset assertion below ("after reset, all
    // SEED_COUNT seeded rows are back in the table") isn't truncated by the
    // default page size of 25 (SEED_COUNT is 30 — the difference is the bug
    // that surfaces on CI as a `toHaveCount(SEED_COUNT)` mismatch).
    await page.getByTestId("banks-pagination__page-size").selectOption("50");

    // Pick a bank deterministically and filter by the unique tail of its
    // short name. The seed helper builds short names as
    // `<prefix-tail>-NNN`, so the `-NNN` suffix is unique within the run.
    const target = seeded[3];
    const uniqueTail = target.shortName.slice(-4); // "-004"

    await page.getByTestId("banks-filters__toggle").click();
    await filterByName(page, runPrefix);
    await page.getByTestId("banks-filters__short-name").fill(uniqueTail);

    await expect(page.locator("tbody tr")).toHaveCount(1);
    await expect(page.getByRole("cell", { name: target.name, exact: true })).toBeVisible();
    await expect(page.getByRole("cell", { name: target.shortName, exact: true })).toBeVisible();

    // Reset clears both filters and brings the seeded set back.
    await page.getByTestId("banks-filters__clear-all").click();
    await filterByName(page, runPrefix);
    await expect(page.locator("tbody tr")).toHaveCount(SEED_COUNT);
  });

  test("sort — Code column flips ascending and descending", async ({ page }) => {
    await page.goto("/backoffice/banks");
    await expect(page.getByTestId("banks-list")).toHaveAttribute("data-state", "ready");
    await page.getByTestId("banks-filters__toggle").click();
    await filterByName(page, runPrefix);
    await page.getByTestId("banks-pagination__page-size").selectOption("50");

    const shortNameHeader = page.getByRole("columnheader", { name: "Code", exact: true });
    const shortNameSortButton = shortNameHeader.getByRole("button");

    const firstShort = seeded[0].shortName;
    const lastShort = seeded[SEED_COUNT - 1].shortName;

    // First click — ascending. The seeded short names sort asc by their
    // `-NNN` numeric suffix, so the first row is `… -001`.
    await shortNameSortButton.click();
    await expect(shortNameHeader).toHaveAttribute("aria-sort", "ascending");
    await expect(
      page.locator("tbody tr").first().getByRole("cell", { name: firstShort, exact: true }),
    ).toBeVisible();

    // Second click — descending. First row flips to `… -012`.
    await shortNameSortButton.click();
    await expect(shortNameHeader).toHaveAttribute("aria-sort", "descending");
    await expect(
      page.locator("tbody tr").first().getByRole("cell", { name: lastShort, exact: true }),
    ).toBeVisible();
  });

  test("pagination — default page size 25 walks page 1 <-> page 2 round-trip", async ({ page }) => {
    await page.goto("/backoffice/banks");
    await expect(page.getByTestId("banks-list")).toHaveAttribute("data-state", "ready");
    await page.getByTestId("banks-filters__toggle").click();
    // Wait for the debounced filter to apply before paginating — otherwise the
    // late filter-change resets the page back to 1 mid-test (see filterByName).
    await filterByName(page, runPrefix);

    // The smallest selectable page size is 25; with 30 seeded rows that
    // splits into 25 + 5.
    await expect(page.getByTestId("banks-pagination__page-size")).toHaveValue("25");
    await expect(page.locator("tbody tr")).toHaveCount(25);
    await expect(page.getByTestId("banks-pagination__prev")).toBeDisabled();
    await expect(page.getByTestId("banks-pagination__next")).toBeEnabled();

    await page.getByTestId("banks-pagination__next").click();
    await expect(page.locator("tbody tr")).toHaveCount(SEED_COUNT - 25);
    await expect(page.getByTestId("banks-pagination__next")).toBeDisabled();
    await expect(page.getByTestId("banks-pagination__prev")).toBeEnabled();

    // Prev is wired up — round-tripping back lands on the first page with the
    // full 25-row slice again. The existing suite asserts only the
    // forward direction, so this complements it.
    await page.getByTestId("banks-pagination__prev").click();
    await expect(page.locator("tbody tr")).toHaveCount(25);
    await expect(page.getByTestId("banks-pagination__prev")).toBeDisabled();

    // Bumping the page size to 100 collapses every seeded row onto a
    // single page (cursors discarded, back to the first page).
    await page.getByTestId("banks-pagination__page-size").selectOption("100");
    await expect(page.locator("tbody tr")).toHaveCount(SEED_COUNT);
    await expect(page.getByTestId("banks-pagination__next")).toBeDisabled();
    await expect(page.getByTestId("banks-pagination__prev")).toBeDisabled();
  });

  test("create — surfaces field-level validation when required fields are empty", async ({
    page,
  }) => {
    await page.goto("/backoffice/banks/new");

    // Submit without filling anything — both fields are required by the
    // Zod schema, which mirrors the API's `Assert\NotBlank` constraints.
    await page.getByTestId("bank-form__submit").click();

    await expect(page).toHaveURL(/\/backoffice\/banks\/new$/);
    await expect(page.getByText("The name field is required.")).toBeVisible();
    await expect(page.getByText("The code field is required.")).toBeVisible();
  });

  test("create — happy path lands on the detail page with the new bank", async ({ page }) => {
    const createName = `${runPrefix} INLINE`;
    const createShortNameInput = `${runPrefix.slice(-40)}-INL`.slice(0, 50);

    await page.goto("/backoffice/banks");
    await expect(page.getByTestId("banks-list")).toHaveAttribute("data-state", "ready");

    await page.getByTestId("banks-list__new-button").click();
    await expect(page).toHaveURL(/\/backoffice\/banks\/new$/);

    await page.getByTestId("bank-form__name").fill(createName);
    await page.getByTestId("bank-form__short-name").fill(createShortNameInput);
    await page.getByTestId("bank-form__submit").click();

    await expect(page).toHaveURL(/\/backoffice\/banks\/[0-9a-f-]{36}$/);
    const detailURL = new URL(page.url());
    createdId = detailURL.pathname.split("/").pop()!;
    trackedIds.push(createdId);

    // Confirm the API agrees the bank exists, and assert the short name against
    // the persisted value it returns — the API canonicalizes on create
    // (NormalizedText::toAsciiUpper: uppercase + diacritic stripping), which a
    // local toLocaleUpperCase() does not reproduce.
    const probe = await api.get(`/api/v1/backoffice/banks/${createdId}`);
    expect(probe.ok()).toBe(true);
    const { data: createdBank } = (await probe.json()) as { data: ApiBank };

    await expect(page.getByTestId("banks-detail")).toHaveAttribute("data-state", "ready");
    await expect(page.getByTestId("banks-detail__name")).toHaveText(createName);
    await expect(page.getByTestId("banks-detail__shortname")).toHaveText(createdBank.shortName);
    await expect(page.getByTestId("banks-detail__id")).toHaveText(createdId);
  });

  test("update — inline row edit renames the created bank", async ({ page }) => {
    expect(createdId, "the create test must run first to produce a row to edit").not.toBeNull();
    const id = createdId!;
    const updatedName = `${runPrefix} INLINE (renamed)`;

    await page.goto("/backoffice/banks");
    await expect(page.getByTestId("banks-list")).toHaveAttribute("data-state", "ready");
    // 30 seeded + 1 created by the previous test = 31 rows under the prefix.
    // The default page size of 25 hides the newly-created `INLINE` bank on
    // page 2 (default sort is name asc), so widen the window before locating.
    await page.getByTestId("banks-pagination__page-size").selectOption("50");
    await page.getByTestId("banks-filters__toggle").click();
    await filterByName(page, runPrefix);

    await page.getByTestId(`banks-table__edit-${id}`).click();
    await expect(page).toHaveURL(`/backoffice/banks/${id}/edit`);

    await page.getByTestId("bank-form__name").fill(updatedName);
    await page.getByTestId("bank-form__submit").click();

    await expect(page).toHaveURL(`/backoffice/banks/${id}`);
    await expect(page.getByTestId("banks-detail__name")).toHaveText(updatedName);

    // The API reflects the rename.
    const probe = await api.get(`/api/v1/backoffice/banks/${id}`);
    expect(probe.ok()).toBe(true);
    const body = (await probe.json()) as { data: ApiBank };
    expect(body.data.name).toBe(updatedName);
  });

  test("delete — inline row delete removes the bank without leaving the list", async ({ page }) => {
    expect(createdId, "the create test must run first to produce a row to delete").not.toBeNull();
    const id = createdId!;

    await page.goto("/backoffice/banks");
    await expect(page.getByTestId("banks-list")).toHaveAttribute("data-state", "ready");
    // Same page-size-vs-row-count gotcha as the update test above: 31 rows
    // under the prefix, default page size 25, target row sorted last by name.
    await page.getByTestId("banks-pagination__page-size").selectOption("50");
    await page.getByTestId("banks-filters__toggle").click();
    await filterByName(page, runPrefix);

    await expect(page.getByTestId(`banks-table__row-${id}`)).toBeVisible();
    // Delete lives in the per-row overflow (⋯) menu.
    await page.getByTestId(`banks-table__actions-${id}`).click();
    await page.getByTestId(`banks-table__delete-${id}`).click();
    await page.getByTestId("banks-detail__delete-confirm").click();

    // Inline delete keeps us on the list page (unlike the detail-page
    // delete which routes back to /backoffice/banks).
    await expect(page).toHaveURL(/\/backoffice\/banks$/);
    await expect(page.getByTestId(`banks-table__row-${id}`)).toBeHidden();

    // The API returns 404 for the deleted id.
    const probe = await api.get(`/api/v1/backoffice/banks/${id}`);
    expect(probe.status()).toBe(404);
  });

  test("create — diacritic short name is canonicalized to ASCII upper by the API", async ({
    page,
  }) => {
    // The API canonicalizes codes via NormalizedText::toAsciiUpper
    // (Any-Latin; Latin-ASCII; Upper()) — uppercase AND accent-stripping — so a
    // "-GLÉ" input persists as "-GLE". Seeding a non-ASCII code locks that the
    // detail view shows the API's rule, which a local toLocaleUpperCase()
    // (accent preserved) would not reproduce.
    const accentedInput = `${runPrefix.slice(-40)}-GLÉ`.slice(0, 50);
    const bank = await createBank(api, `${runPrefix} Accented`, accentedInput);
    trackedIds.push(bank.id);

    expect(bank.shortName).toMatch(/-GLE$/);
    // Pure printable ASCII — every diacritic folded away, not just Latin-1 ones.
    expect(bank.shortName).not.toMatch(/[^\x20-\x7E]/);

    // The detail page renders exactly the canonical value the API returned.
    await page.goto(`/backoffice/banks/${bank.id}`);
    await expect(page.getByTestId("banks-detail")).toHaveAttribute("data-state", "ready");
    await expect(page.getByTestId("banks-detail__shortname")).toHaveText(bank.shortName);
  });
});
