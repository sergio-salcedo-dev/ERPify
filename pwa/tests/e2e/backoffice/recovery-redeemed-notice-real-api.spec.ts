import { request, test, expect, type Page, type TestInfo } from "@playwright/test";

import {
  E2E_RECOVERY_USER_EMAIL,
  E2E_RECOVERY_USER_PASSWORD,
  VIEWPORT_DESKTOP,
} from "../constants";
import { apiBaseURL } from "../fixtures/api";
import { mintFreshRecoverySecret } from "../fixtures/recovery-secret-api";

// The dev-mode stack compiles each route on its first hit, so the first assertion on a freshly-visited route
// absorbs that cold compile before the default per-assertion budget applies.
const COLD_ROUTE_TIMEOUT_MS = 30_000;

const REDEEMED_TOAST = "Signed in with your recovery secret. We've signed out your other sessions.";

function nextStep(page: Page) {
  return page.getByTestId("dashboard__recovery-next-step");
}

function dashboardHeading(page: Page) {
  return page.getByRole("heading", { level: 1, name: "Dashboard" });
}

function skipRepetitions(testInfo: TestInfo): void {
  test.skip(
    testInfo.repeatEachIndex > 0,
    "each redemption spends a password proof of the 10 per 15 minutes, and a repetition's redemption would evict a sibling repetition's session",
  );
}

/**
 * Types the secret into the redeem field without handing it to Playwright as an action argument:
 * `fill()` titles its step `Fill "<value>"`, which the HTML and blob reports print for every run.
 * The native value setter plus an `input` event is what React's `onChange` listens for, so the
 * form reads it exactly as it reads a paste.
 */
async function enterSecret(page: Page, secret: string): Promise<void> {
  await page.getByTestId("recovery-redeem-form__secret").evaluate((field, value) => {
    const setValue = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, "value")?.set;
    setValue?.call(field, value);
    field.dispatchEvent(new Event("input", { bubbles: true }));
  }, secret);
}

/**
 * Empties the redeem field on screen once the submit has read it: the error context Playwright
 * writes next to a failed assertion carries an ARIA snapshot of the page, field values included,
 * taken when the assertion fails. Only the DOM is cleared, with no `input` event — the form copied
 * its values when the submit started, so the redemption in flight still carries the secret, and a
 * form that read the field again would fail the redemption rather than pass silently.
 */
async function scrubSecret(page: Page): Promise<void> {
  await page
    .getByTestId("recovery-redeem-form__secret")
    .evaluate(
      (field) => {
        (field as HTMLInputElement).value = "";
      },
      undefined,
      { timeout: 1_000 },
    )
    .catch(() => undefined);
}

/**
 * Signs the dedicated identity in over the API and mints it a fresh recovery secret, returning the
 * plaintext — readable only in the mint's 201. The API session is closed on the way out; the
 * redemption would evict it anyway, since it signs out every other session of the account.
 */
async function mintSecretForRecoveryUser(): Promise<string> {
  const api = await request.newContext({ baseURL: apiBaseURL(), ignoreHTTPSErrors: true });
  try {
    const login = await api.post("/api/v1/backoffice/login", {
      data: { email: E2E_RECOVERY_USER_EMAIL, password: E2E_RECOVERY_USER_PASSWORD },
      headers: { "Content-Type": "application/json", Origin: apiBaseURL() },
    });
    if (login.status() !== 204) {
      throw new Error(
        `login for ${E2E_RECOVERY_USER_EMAIL} failed (${login.status()}) — is it seeded? \`make pwa.test.e2e\` seeds it before Playwright starts`,
      );
    }
    return await mintFreshRecoverySecret(api, E2E_RECOVERY_USER_PASSWORD);
  } finally {
    await api
      .post("/api/v1/sessions/revoke-current", { headers: { Origin: apiBaseURL() } })
      .catch(() => undefined);
    await api.dispose();
  }
}

// No shared-administrator session, and no trace: a trace records every action's arguments, and one
// of them is the plaintext secret. Top-level because a trace option forces its own worker.
test.use({
  viewport: VIEWPORT_DESKTOP,
  storageState: { cookies: [], origins: [] },
  trace: "off",
});

/**
 * The one-shot notice after a recovery-secret redemption, end to end against the real API. It
 * travels in module memory across the client navigation `/recovery` → `/backoffice`, so only a
 * browser can prove the handover: a unit test marks and reads the flag in one module graph, and
 * would stay green over a hard navigation or a second copy of the module.
 *
 * Runs as its own identity, never the shared administrator: a redemption signs out every other
 * session of the account, which would revoke the per-worker session every sibling spec runs on.
 * For the same reason the two tests run in order in one worker — a redemption under a concurrent
 * sign-in of the same identity would evict it mid-test.
 *
 * Minting re-proves the password against the per-identity limit (10 per 15 minutes, shared by mint
 * and revoke), which is not relaxed for E2E. A healthy run spends one proof (the redemption deletes
 * the row, so nothing is left to revoke), a run after a failed one two; with CI's two retries the
 * worst case is six. Ten repetitions would not fit, so the suite runs only on the first one
 * (`--repeat-each`; the weekly burn-in runs ten).
 */
test.describe("BackOffice - Recovery redemption next-step notice (real API)", () => {
  test.describe.configure({ mode: "default" });

  test("a redeemed secret lands on the dashboard with the next step, once", async ({
    page,
  }, testInfo) => {
    skipRepetitions(testInfo);
    const secret = await mintSecretForRecoveryUser();

    await page.goto("/recovery");
    // The submit button stays disabled until the form hydrates, so a native GET can never carry
    // the secret into the URL.
    await expect(page.getByTestId("recovery-redeem-form")).toHaveAttribute(
      "data-hydrated",
      "true",
      { timeout: COLD_ROUTE_TIMEOUT_MS },
    );

    // A marker on the live document: a reload or a full navigation would discard it, and with it
    // the module memory the notice travels in.
    await page.evaluate(() => {
      (globalThis as unknown as { __rr3Document?: boolean }).__rr3Document = true;
    });
    try {
      await enterSecret(page, secret);
      await page.getByTestId("recovery-redeem-form__submit").click();
      await scrubSecret(page);

      await expect(page.getByText(REDEEMED_TOAST)).toBeVisible({
        timeout: COLD_ROUTE_TIMEOUT_MS,
      });
      await expect(page).toHaveURL(/\/backoffice$/, { timeout: COLD_ROUTE_TIMEOUT_MS });
    } catch (error) {
      await scrubSecret(page);
      throw error;
    }
    await expect(nextStep(page)).toBeVisible({ timeout: COLD_ROUTE_TIMEOUT_MS });
    await expect(nextStep(page)).toContainText("Next: set a new password");
    expect(
      await page.evaluate(
        () => (globalThis as unknown as { __rr3Document?: boolean }).__rr3Document === true,
      ),
    ).toBe(true);

    // Once within the document: leaving the dashboard by a client navigation and coming back keeps
    // the module memory, so only the notice having cleared its own flag keeps it away.
    await page.getByTestId("dashboard__recovery-next-step-link").click();
    await expect(page).toHaveURL(/\/backoffice\/profile$/, { timeout: COLD_ROUTE_TIMEOUT_MS });
    await page.goBack();
    await expect(page).toHaveURL(/\/backoffice$/);
    await expect(dashboardHeading(page)).toBeVisible();
    await expect(nextStep(page)).toHaveCount(0);
    expect(
      await page.evaluate(
        () => (globalThis as unknown as { __rr3Document?: boolean }).__rr3Document === true,
      ),
    ).toBe(true);

    // And across a reload, which empties the module memory.
    await page.reload();
    await expect(dashboardHeading(page)).toBeVisible({ timeout: COLD_ROUTE_TIMEOUT_MS });
    await expect(nextStep(page)).toHaveCount(0);
  });

  test("a password sign-in of the same identity shows no next step", async ({ page }, testInfo) => {
    skipRepetitions(testInfo);
    await page.goto("/login");
    await page.getByTestId("login-form__email").fill(E2E_RECOVERY_USER_EMAIL);
    await page.getByTestId("login-form__password").fill(E2E_RECOVERY_USER_PASSWORD);
    await page.getByTestId("login-form__submit").click();

    await expect(page).toHaveURL(/\/backoffice$/, { timeout: COLD_ROUTE_TIMEOUT_MS });
    await expect(dashboardHeading(page)).toBeVisible({ timeout: COLD_ROUTE_TIMEOUT_MS });
    await expect(nextStep(page)).toHaveCount(0);
  });
});
