import { request } from "@playwright/test";
import { test, expect, type APIRequestContext, type Page } from "../fixtures/authenticatedTest";
import { E2E_USER_PASSWORD, VIEWPORT_DESKTOP } from "../constants";
import { apiBaseURL } from "../fixtures/api";
import {
  ensureLiveRecoverySecret,
  readRecoverySecret,
  revokeRecoverySecret,
} from "../fixtures/recovery-secret-api";

// The dev-mode stack compiles each route on its first hit, so the first assertion on a freshly-visited route
// absorbs that cold compile before the default per-assertion budget applies.
const COLD_ROUTE_TIMEOUT_MS = 30_000;

function notice(page: Page) {
  return page.getByRole("complementary", { name: "Account recovery" });
}

/** The skip link moves the focus to `<main>`, and the warning has to be the first thing there. */
async function expectNoticeFirstWhereFocusLands(page: Page): Promise<void> {
  await page.getByRole("link", { name: "Skip to main content" }).focus();
  await page.keyboard.press("Enter");
  const main = page.locator("main#main-content");
  await expect(main).toBeFocused();
  await expect(main.locator(":scope > aside").first()).toHaveAccessibleName("Account recovery");
}

/**
 * The readiness warning end to end, against the real API: an administrator with no recovery
 * secret is told so on every back-office route, mints one on the profile page, and the warning
 * leaves the shell at once — from the mint's own answer, with no navigation and no reload.
 *
 * The E2E administrator starts live (`global-setup.ts`) and ends live. The "none" state is built
 * here by revoking through the API, and the test mints through the UI. Mint and revoke share the
 * per-identity password-proof limit (10 per 15 minutes), which is not relaxed for E2E. The worst
 * case for one suite run is 6 proofs: global setup ≤2 (revoke a lapsed secret, mint), this test 2
 * (revoke, mint) and the restore ≤2 if the test fails between them. Any further run of the test
 * would add up to 4 more, so it runs only on the first repetition (`--repeat-each`; the weekly
 * burn-in runs ten) and never on a retry (CI retries twice).
 */
test.describe("BackOffice - Recovery readiness warning (real API)", () => {
  test.use({ viewport: VIEWPORT_DESKTOP });

  let api: APIRequestContext;
  // Only the worker that revoked restores. A skipped repetition restoring too would read the
  // other worker's deliberate "none" mid-test and mint over it.
  let revoked = false;

  test.beforeAll(async ({ workerStorageState }) => {
    api = await request.newContext({
      baseURL: apiBaseURL(),
      ignoreHTTPSErrors: true,
      storageState: workerStorageState,
    });
  });

  test.afterAll(async () => {
    // Whatever happened above, every other spec expects the administrator to be live.
    if (revoked) await ensureLiveRecoverySecret(api);
    await api.dispose();
  });

  test("an administrator with no secret is warned on every route until they mint one", async ({
    page,
  }, testInfo) => {
    testInfo.skip(
      testInfo.repeatEachIndex > 0 || testInfo.retry > 0,
      "revoke + mint (+ restore) spend up to 4 of the 10 password-proof attempts per 15 minutes; repeating or retrying would lock out the run",
    );

    revoked = true;
    await revokeRecoverySecret(api);
    expect((await readRecoverySecret(api)).exists).toBe(false);

    await page.goto("/backoffice");
    await expect(notice(page)).toBeVisible({ timeout: COLD_ROUTE_TIMEOUT_MS });
    await expect(notice(page)).toContainText("Your account has no recovery secret.");
    await expectNoticeFirstWhereFocusLands(page);

    // A client navigation through the warning's own link: the shell is not remounted, and the
    // warning is still there on the second route. Activated without focusing it (the focus is left
    // on <body>, as a trigger that goes away leaves it), so the shell's route-focus correction is
    // what moves the focus — to <main>, whose first child is the warning.
    await page.evaluate(() => (globalThis.document.activeElement as HTMLElement | null)?.blur());
    await notice(page)
      .getByRole("link", { name: "Create one in Profile → Recovery secret" })
      .evaluate((link: HTMLElement) => link.click());
    await expect(page).toHaveURL(/\/backoffice\/profile$/);
    await expect(page.getByTestId("recovery-secret__empty")).toBeVisible({
      timeout: COLD_ROUTE_TIMEOUT_MS,
    });
    await expect(page.locator("main#main-content")).toBeFocused();
    await expect(notice(page)).toBeVisible();
    await expectNoticeFirstWhereFocusLands(page);

    // A marker on the live document: a reload or a full navigation would discard it.
    await page.evaluate(() => {
      (globalThis as unknown as { __rr1Document?: boolean }).__rr1Document = true;
    });
    const urlBeforeMint = page.url();
    await page.getByTestId("mint-recovery-secret__current-password").fill(E2E_USER_PASSWORD);
    await page.getByTestId("mint-recovery-secret__submit").click();
    await expect(page.getByTestId("recovery-secret__minted")).toBeVisible();

    // Gone from the mint's own answer: same document, same URL, no reload.
    await expect(notice(page)).toHaveCount(0);
    expect(page.url()).toBe(urlBeforeMint);
    expect(
      await page.evaluate(
        () => (globalThis as unknown as { __rr1Document?: boolean }).__rr1Document === true,
      ),
    ).toBe(true);
    await page.getByTestId("recovery-secret__acknowledge").click();

    expect((await readRecoverySecret(api)).live).toBe(true);
  });
});
