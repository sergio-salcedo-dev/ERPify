import { test, expect, type APIRequestContext } from "@playwright/test";
import { VIEWPORT_DESKTOP } from "../constants";
import {
  createApiContext,
  deleteBanksSafely,
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
 * `NEXT_PUBLIC_SYMFONY_API_BASE_URL=https://localhost`.
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

  test("list — renders seeded rows and reports the total", async ({ page }) => {
    await page.goto("/backoffice/banks");

    const list = page.getByTestId("banks-list");
    await expect(list).toHaveAttribute("data-state", "ready");
    await expect(page.getByTestId("banks-table")).toBeVisible();
    await expect(page.getByTestId("banks-list__title")).toHaveText("Banks");

    // The total count counts every bank the API returned, not just the
    // page-size slice. With at least our seeded rows present it must be
    // >= SEED_COUNT.
    const totalText = await page.getByTestId("banks-list__total").innerText();
    const total = Number(totalText.match(/(\d+)/)?.[1] ?? "0");
    expect(total).toBeGreaterThanOrEqual(SEED_COUNT);

    // Narrow the client-side filter to test-owned rows.
    await page.getByTestId("banks-filters__name").fill(runPrefix);

    // Default sort is name ascending — first row = `<prefix> 001`.
    const firstSeeded = `${runPrefix} 001`;
    await expect(
      page.locator("tbody tr").first().getByRole("cell", { name: firstSeeded, exact: true }),
    ).toBeVisible();
  });

  test("filter — short-name input narrows the list to a single seeded row", async ({ page }) => {
    await page.goto("/backoffice/banks");
    await expect(page.getByTestId("banks-list")).toHaveAttribute("data-state", "ready");

    // Pick a bank deterministically and filter by the unique tail of its
    // short name. The seed helper builds short names as
    // `<prefix-tail>-NNN`, so the `-NNN` suffix is unique within the run.
    const target = seeded[3];
    const uniqueTail = target.shortName.slice(-4); // "-004"

    await page.getByTestId("banks-filters__name").fill(runPrefix);
    await page.getByTestId("banks-filters__short-name").fill(uniqueTail);

    await expect(page.locator("tbody tr")).toHaveCount(1);
    await expect(page.getByRole("cell", { name: target.name, exact: true })).toBeVisible();
    await expect(page.getByRole("cell", { name: target.shortName, exact: true })).toBeVisible();

    // Reset clears both filters and brings the seeded set back.
    await page.getByTestId("banks-filters__reset").click();
    await page.getByTestId("banks-filters__name").fill(runPrefix);
    await expect(page.locator("tbody tr")).toHaveCount(SEED_COUNT);
  });

  test("sort — Short name column flips ascending and descending", async ({ page }) => {
    await page.goto("/backoffice/banks");
    await expect(page.getByTestId("banks-list")).toHaveAttribute("data-state", "ready");
    await page.getByTestId("banks-filters__name").fill(runPrefix);
    await page.getByTestId("banks-pagination__page-size").selectOption("50");

    const shortNameHeader = page.getByRole("columnheader", { name: "Short name", exact: true });
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

  test("pagination — default page size 25 walks page 1 <-> page 2 round-trip", async ({
    page,
  }) => {
    await page.goto("/backoffice/banks");
    await expect(page.getByTestId("banks-list")).toHaveAttribute("data-state", "ready");
    await page.getByTestId("banks-filters__name").fill(runPrefix);

    // The smallest selectable page size is 25; with 30 seeded rows that
    // splits into 25 + 5.
    await expect(page.getByTestId("banks-pagination__page-size")).toHaveValue("25");
    await expect(page.getByTestId("banks-pagination__indicator")).toHaveText("Page 1");
    await expect(page.locator("tbody tr")).toHaveCount(25);
    await expect(page.getByTestId("banks-pagination__prev")).toBeHidden();

    await page.getByTestId("banks-pagination__next").click();
    await expect(page.getByTestId("banks-pagination__indicator")).toHaveText("Page 2");
    await expect(page.locator("tbody tr")).toHaveCount(SEED_COUNT - 25);
    await expect(page.getByTestId("banks-pagination__next")).toBeHidden();

    // Prev is wired up — round-tripping back lands on page 1 with the
    // full 25-row slice again. The existing suite asserts only the
    // forward direction, so this complements it.
    await page.getByTestId("banks-pagination__prev").click();
    await expect(page.getByTestId("banks-pagination__indicator")).toHaveText("Page 1");
    await expect(page.locator("tbody tr")).toHaveCount(25);

    // Bumping the page size to 100 collapses every seeded row onto a
    // single page.
    await page.getByTestId("banks-pagination__page-size").selectOption("100");
    await expect(page.getByTestId("banks-pagination__indicator")).toHaveText("Page 1");
    await expect(page.locator("tbody tr")).toHaveCount(SEED_COUNT);
    await expect(page.getByTestId("banks-pagination__next")).toBeHidden();
    await expect(page.getByTestId("banks-pagination__prev")).toBeHidden();
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
    await expect(page.getByText("The shortName field is required.")).toBeVisible();
  });

  test("create — happy path lands on the detail page with the new bank", async ({ page }) => {
    const createName = `${runPrefix} INLINE`;
    const createShort = `${runPrefix.slice(-40)}-INL`.slice(0, 50);

    await page.goto("/backoffice/banks");
    await expect(page.getByTestId("banks-list")).toHaveAttribute("data-state", "ready");

    await page.getByTestId("banks-list__new-button").click();
    await expect(page).toHaveURL(/\/backoffice\/banks\/new$/);

    await page.getByTestId("bank-form__name").fill(createName);
    await page.getByTestId("bank-form__short-name").fill(createShort);
    await page.getByTestId("bank-form__submit").click();

    await expect(page).toHaveURL(/\/backoffice\/banks\/[0-9a-f-]{36}$/);
    const detailURL = new URL(page.url());
    createdId = detailURL.pathname.split("/").pop()!;
    trackedIds.push(createdId);

    await expect(page.getByTestId("banks-detail")).toHaveAttribute("data-state", "ready");
    await expect(page.getByTestId("banks-detail__name")).toHaveText(createName);
    await expect(page.getByTestId("banks-detail__shortname")).toHaveText(createShort);
    await expect(page.getByTestId("banks-detail__id")).toHaveText(createdId);

    // Confirm the API agrees the bank exists.
    const probe = await api.get(`/api/v1/backoffice/banks/${createdId}`);
    expect(probe.ok()).toBe(true);
  });

  test("update — inline row edit renames the created bank", async ({ page }) => {
    expect(createdId, "the create test must run first to produce a row to edit").not.toBeNull();
    const id = createdId!;
    const updatedName = `${runPrefix} INLINE (renamed)`;

    await page.goto("/backoffice/banks");
    await expect(page.getByTestId("banks-list")).toHaveAttribute("data-state", "ready");
    await page.getByTestId("banks-filters__name").fill(runPrefix);

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

  test("delete — inline row delete removes the bank without leaving the list", async ({
    page,
  }) => {
    expect(createdId, "the create test must run first to produce a row to delete").not.toBeNull();
    const id = createdId!;

    await page.goto("/backoffice/banks");
    await expect(page.getByTestId("banks-list")).toHaveAttribute("data-state", "ready");
    await page.getByTestId("banks-filters__name").fill(runPrefix);

    await expect(page.getByTestId(`banks-table__row-${id}`)).toBeVisible();
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
});
