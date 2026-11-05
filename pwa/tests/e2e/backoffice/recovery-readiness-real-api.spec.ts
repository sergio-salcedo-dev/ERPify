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

function step(page: Page) {
  return page.getByTestId("recovery-enrolment");
}

function banner(page: Page) {
  return page.getByRole("complementary", { name: "Account recovery" });
}

/**
 * The recovery enrolment wall end to end, against the real API: an administrator with no recovery secret
 * cannot use the back office on any route until they mint one, mints it in the step that replaces the page
 * (the profile's mint form, re-proving the password), reads it once, and the wall is gone from the mint's own
 * answer — no navigation, no reload.
 *
 * The E2E administrator starts live (`global-setup.ts`) and ends live. The "none" state is built here by
 * revoking through the API, and the test mints through the UI.
 *
 * **Proof budget of the shared administrator.** Mint, revoke, the role and status writes and an ADMIN invitation
 * all spend one shared per-identity limit (10 per 15 minutes), not relaxed for E2E. A healthy run spends: global
 * setup 1 on a fresh stack and up to 2 when it must first revoke a lapsed secret, role change 1, status change 1,
 * ADMIN invitation 1, and this spec 2 (revoke, mint) — 6 on a fresh stack, 7 at worst. With CI's two retries every
 * spender except this spec may run three times, and a proof is spent even by an attempt that then fails, so the
 * theoretical worst is 2 + 3 + 3 + 3 + 2 + 2 (this spec's restore) = 15. The spenders are skipped on
 * `--repeat-each` repetitions, and this spec on retries, so repetition adds nothing.
 *
 * A 429 between this spec's revoke and its restore leaves the shared administrator without a secret until the
 * 15-minute window slides; it heals itself and costs time, not data.
 */
test.describe("BackOffice - Recovery enrolment wall (real API)", () => {
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

  test("an administrator with no secret is walled on every route until they mint one", async ({
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
    await expect(step(page)).toBeVisible({ timeout: COLD_ROUTE_TIMEOUT_MS });
    await expect(step(page)).toContainText("Your account has no recovery secret.");
    await expect(page.getByTestId("recovery-enrolment__title")).toBeFocused();
    // Instead of the page, not beside it: the dashboard heading is not there, and the standing banner
    // (which would repeat the same sentence) is not either.
    await expect(page.getByRole("heading", { level: 1, name: "Dashboard" })).toHaveCount(0);
    await expect(banner(page)).toHaveCount(0);
    // A way out, and no way to skip.
    await expect(page.getByTestId("recovery-enrolment__signout")).toBeVisible();
    await expect(step(page).getByRole("link")).toHaveCount(0);

    // Every route: a direct load of the profile page, which hosts the same mint form, is walled too.
    await page.goto("/backoffice/profile");
    await expect(step(page)).toBeVisible({ timeout: COLD_ROUTE_TIMEOUT_MS });
    await expect(page.getByTestId("recovery-secret")).toHaveCount(0);

    // A marker on the live document: a reload or a full navigation would discard it.
    await page.evaluate(() => {
      (globalThis as unknown as { __rr1Document?: boolean }).__rr1Document = true;
    });
    const urlBeforeMint = page.url();
    await page.getByTestId("mint-recovery-secret__current-password").fill(E2E_USER_PASSWORD);
    await page.getByTestId("mint-recovery-secret__submit").click();

    // The secret is shown once, in place of the page, before the back office opens.
    await expect(page.getByTestId("recovery-secret__minted")).toBeVisible();
    await expect(page.getByTestId("recovery-secret__value")).not.toBeEmpty();
    expect((await readRecoverySecret(api)).live).toBe(true);
    await page.getByTestId("recovery-secret__acknowledge").click();

    // Gone from the mint's own answer: same document, same URL, no reload, and no banner either.
    await expect(step(page)).toHaveCount(0);
    await expect(page.getByTestId("recovery-secret")).toBeVisible();
    await expect(banner(page)).toHaveCount(0);
    expect(page.url()).toBe(urlBeforeMint);
    expect(
      await page.evaluate(
        () => (globalThis as unknown as { __rr1Document?: boolean }).__rr1Document === true,
      ),
    ).toBe(true);
  });
});
