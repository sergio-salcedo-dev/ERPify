import { test, expect, type APIRequestContext, type Page } from "@playwright/test";
import { VIEWPORT_DESKTOP, VIEWPORT_MOBILE } from "../constants";
import {
  createApiContext,
  createBank,
  deleteBanksSafely,
  filterByName,
  uniqueRunPrefix,
  type ApiBank,
} from "../fixtures/banks-real-api";

/**
 * Regression suite for the entity-list containment contract (UX spines
 * 2026-06-03/04): a 255-char name must never deform the table, the cards or
 * the detail page, and the full value must stay reachable — tooltip on
 * hover/row-focus, canonical home in the detail. Also locks the
 * accessibility behaviors that ride the same surfaces: tri-state header
 * checkbox, Esc precedence (tooltip before selection) and the stacked
 * mobile rows without horizontal scroll.
 */
const MAX_NAME_LENGTH = 255;

function nameOfLength(prefix: string, length: number): string {
  return `${prefix} ${"A".repeat(length - prefix.length - 1)}`;
}

test.describe("BackOffice - Banks long-name containment (real API)", () => {
  test.use({ viewport: VIEWPORT_DESKTOP });

  const runPrefix = uniqueRunPrefix("BankContain");
  const trackedIds: string[] = [];
  let api: APIRequestContext;
  let longBank: ApiBank;
  let shortBank: ApiBank;

  const longName = (): string => longBank.name;

  async function gotoFilteredList(page: Page): Promise<void> {
    await page.goto("/backoffice/banks");
    await page.getByTestId("banks-filters__toggle").click();
    await filterByName(page, runPrefix);
  }

  test.beforeAll(async () => {
    api = await createApiContext();
    longBank = await createBank(
      api,
      nameOfLength(runPrefix, MAX_NAME_LENGTH),
      `${runPrefix}-LONG`.slice(0, 50),
    );
    shortBank = await createBank(api, `${runPrefix} Tiny`, `${runPrefix}-TINY`.slice(0, 50));
    trackedIds.push(longBank.id, shortBank.id);
  });

  test.afterAll(async () => {
    await deleteBanksSafely(api, trackedIds);
    await api.dispose();
  });

  test("table rows keep a constant height with a 255-char name", async ({ page }) => {
    await gotoFilteredList(page);
    const longRow = page.getByTestId(`banks-table__row-${longBank.id}`);
    const shortRow = page.getByTestId(`banks-table__row-${shortBank.id}`);
    await expect(longRow).toBeVisible();
    await expect(shortRow).toBeVisible();

    const longBox = await longRow.boundingBox();
    const shortBox = await shortRow.boundingBox();
    expect(longBox?.height).toBeDefined();
    expect(Math.abs((longBox?.height ?? 0) - (shortBox?.height ?? 0))).toBeLessThanOrEqual(1);
  });

  test("hovering the truncated name reveals the full-value tooltip", async ({ page }) => {
    await gotoFilteredList(page);
    const nameCell = page
      .getByTestId(`banks-table__row-${longBank.id}`)
      .getByText(longName(), { exact: true });
    await nameCell.hover();
    const tooltip = page.locator('[data-slot="tooltip-content"]');
    await expect(tooltip).toBeVisible();
    await expect(tooltip).toHaveText(longName());
  });

  test("focusing the row opens the tooltip; Esc closes it first and clears selection second", async ({
    page,
  }) => {
    await gotoFilteredList(page);
    const longRow = page.getByTestId(`banks-table__row-${longBank.id}`);

    // Select the row, then focus it — the truncated name tooltip opens.
    await longRow.locator("input[type=checkbox]").check();
    await expect(page.getByTestId("banks-list__bulk-count")).toHaveText("1 selected");
    await longRow.focus();
    const tooltip = page.locator('[data-slot="tooltip-content"]');
    await expect(tooltip).toBeVisible();

    // First Esc: dismisses the tooltip ONLY — the selection survives.
    await page.keyboard.press("Escape");
    await expect(tooltip).toBeHidden();
    await expect(page.getByTestId("banks-list__bulk-count")).toHaveText("1 selected");

    // Second Esc: no transient layer left — now it clears the selection.
    await page.keyboard.press("Escape");
    await expect(page.getByTestId("banks-list__bulk-bar")).toBeHidden();
  });

  test("header checkbox exposes the mixed state on partial selection", async ({ page }) => {
    await gotoFilteredList(page);
    await page
      .getByTestId(`banks-table__row-${longBank.id}`)
      .locator("input[type=checkbox]")
      .check();
    const selectAll = page.getByLabel("Select all rows");
    await expect(selectAll).toHaveJSProperty("indeterminate", true);
  });

  test("cards keep equal heights and the title clamps with the full value in the DOM", async ({
    page,
  }) => {
    await gotoFilteredList(page);
    await page.getByTestId("banks-list__view-toggle-cards").click();

    const longCard = page.getByTestId(`banks-cards__item-${longBank.id}`);
    const shortCard = page.getByTestId(`banks-cards__item-${shortBank.id}`);
    await expect(longCard).toBeVisible();
    const longBox = await longCard.boundingBox();
    const shortBox = await shortCard.boundingBox();
    expect(Math.abs((longBox?.height ?? 0) - (shortBox?.height ?? 0))).toBeLessThanOrEqual(1);

    // CSS-only clamp: the full 255-char value stays in the DOM.
    await expect(page.getByTestId(`banks-cards__name-${longBank.id}`)).toHaveText(longName());
  });

  test("detail H1 shows the entire 255-char name without visual truncation", async ({ page }) => {
    await page.goto(`/backoffice/banks/${longBank.id}`);
    const heading = page.getByTestId("banks-detail__name");
    await expect(heading).toHaveText(longName());
    // No clamp: rendered height fits the full content (canonical home).
    const truncated = await heading.evaluate((el) => el.scrollHeight > el.clientHeight + 1);
    expect(truncated).toBe(false);
  });

  test("create form: the name field grows with the value, shows the n/255 counter, and the toast clamps", async ({
    page,
  }) => {
    const createdName = nameOfLength(`${runPrefix} Created`, MAX_NAME_LENGTH);
    await page.goto("/backoffice/banks/new");

    // Wait for the wired form — interacting pre-hydration races the client
    // render (and a native submit would leak values into the URL).
    await expect(page.getByTestId("bank-form")).toHaveAttribute("data-hydrated", "true");

    const nameField = page.getByTestId("bank-form__name");
    await nameField.fill(createdName);
    await expect(page.getByTestId("bank-form__name-counter")).toHaveText("255/255");
    // Auto-grow: the textarea is tall enough to show everything it holds
    // (scrollHeight excludes the 1px borders that clientHeight pairs with).
    const overflowing = await nameField.evaluate((el) => el.scrollHeight > el.clientHeight + 4);
    expect(overflowing).toBe(false);

    await page.getByTestId("bank-form__short-name").fill(`${runPrefix}-NEW`.slice(0, 50));
    await page.getByTestId("bank-form__submit").click();

    await page.waitForURL(/\/backoffice\/banks\/[0-9a-f-]+$/);
    const createdId = page.url().split("/").pop();
    if (createdId) trackedIds.push(createdId);

    // Toast description carries the 2-line clamp — long names never blow it up.
    const toastDescription = page.locator("[data-sonner-toast] [data-description]").first();
    await expect(toastDescription).toBeVisible();
    await expect(toastDescription).toHaveClass(/line-clamp-2/);
  });

  test("toolbar search, '/' shortcut and sticky bulk bar", async ({ page }) => {
    // Navigate directly (not via gotoFilteredList) so the panel starts closed.
    await page.goto("/backoffice/banks");
    await expect(page.getByTestId("banks-list")).toHaveAttribute("data-state", "ready");

    // Search is reachable without opening the filters panel.
    await expect(page.getByTestId("banks-filters__name")).toBeVisible();
    await expect(page.getByTestId("banks-filters__panel")).toBeHidden();

    // '/' focuses the search from the page body.
    await page.locator("body").press("/");
    await expect(page.getByTestId("banks-filters__name")).toBeFocused();
    // '/' typed while the search has focus must NOT be swallowed (guard works):
    await page.keyboard.type("/");
    await expect(page.getByTestId("banks-filters__name")).toHaveValue("/");
    await page.getByTestId("banks-filters__name").clear();

    // Narrow to the seeded rows so the row locator is deterministic.
    await page.getByTestId("banks-filters__name").fill(runPrefix);
    // Wait for the 300ms debounce to flush before asserting on rows.
    await page.waitForTimeout(400);

    // Selecting a row shows the bulk bar pinned within the content column.
    const firstRow = page.locator('[data-testid^="banks-table__row-"]').first();
    await firstRow.locator("input[type=checkbox]").check();
    const bar = page.getByTestId("banks-list__bulk-bar");
    await expect(bar).toBeVisible();

    // The bar centers on the content column, not the viewport: its horizontal
    // center must match the banks-list container's center (±2px), which differs
    // from the viewport center whenever the sidebar is open.
    const barBox = await bar.boundingBox();
    const listBox = await page.getByTestId("banks-list").boundingBox();
    expect(barBox).not.toBeNull();
    expect(listBox).not.toBeNull();
    if (barBox && listBox) {
      const barCenter = barBox.x + barBox.width / 2;
      const listCenter = listBox.x + listBox.width / 2;
      expect(Math.abs(barCenter - listCenter)).toBeLessThanOrEqual(2);
    }

    // Pagination stays reachable (bar settles after it at scroll end).
    await expect(page.getByTestId("banks-pagination__page-size")).toBeVisible();

    // Esc clears the selection and the bar unmounts.
    // Focus is on the checkbox (inside banks-list) from the .check() call above,
    // so the keydown event bubbles to the list container's Esc handler.
    await page.keyboard.press("Escape");
    await expect(bar).toBeHidden();
  });
});

test.describe("BackOffice - Banks stacked mobile rows (real API)", () => {
  test.use({ viewport: VIEWPORT_MOBILE });

  const runPrefix = uniqueRunPrefix("BankStacked");
  const trackedIds: string[] = [];
  let api: APIRequestContext;
  let longBank: ApiBank;

  test.beforeAll(async () => {
    api = await createApiContext();
    longBank = await createBank(
      api,
      nameOfLength(runPrefix, MAX_NAME_LENGTH),
      `${runPrefix}-LONG`.slice(0, 50),
    );
    trackedIds.push(longBank.id);
  });

  test.afterAll(async () => {
    await deleteBanksSafely(api, trackedIds);
    await api.dispose();
  });

  test("below md the table becomes stacked rows with zero horizontal scroll", async ({ page }) => {
    await page.goto("/backoffice/banks");
    await page.getByTestId("banks-filters__toggle").click();
    await filterByName(page, runPrefix);

    await expect(page.getByTestId(`banks-stacked__row-${longBank.id}`)).toBeVisible();
    await expect(page.getByTestId("banks-table__inner")).toBeHidden();

    const hasHorizontalScroll = await page.evaluate(
      () => document.documentElement.scrollWidth > window.innerWidth,
    );
    expect(hasHorizontalScroll).toBe(false);

    // Actions and checkbox are always visible on touch surfaces.
    await expect(page.getByTestId(`banks-stacked__select-${longBank.id}`)).toBeVisible();
    await expect(page.getByTestId(`banks-stacked__actions-${longBank.id}`)).toBeVisible();
  });
});
