import { test, expect } from "../fixtures/authenticatedTest";
import { E2E_USER_PASSWORD, VIEWPORT_DESKTOP } from "../constants";

/**
 * Write-side real-API E2E: an ADMIN invites a new member from the console and the INVITED row appears in the
 * register. It drives the real alta end to end — the gated "Invite user" trigger, the invitation form, the
 * `POST /backoffice/invitations` (an INVITED identity + the emailed accept link), and the refreshed register.
 * The invitee email is unique per run and the row is found by filtering on it — never an exact count, since the
 * dev DB accumulates identities across runs.
 *
 * Granting ADMIN asks for the inviter's own password and spends a unit of the per-identity password-proof budget
 * (10 per 15 minutes, shared with mint, revoke and the other authority writes), so that case runs on the first
 * repetition only; every other role needs no password and the field is absent.
 */
// The dev-mode stack compiles each route on its first hit, so the first assertion on a freshly-visited route
// absorbs that cold compile before the default per-assertion budget applies.
const COLD_ROUTE_TIMEOUT_MS = 30_000;

test.describe("BackOffice - Invite a user (real API)", () => {
  test.use({ viewport: VIEWPORT_DESKTOP });

  test("invites a new member through the console and the INVITED row appears", async ({
    page,
  }, testInfo) => {
    const email = `invite-e2e-${testInfo.parallelIndex}-${Date.now()}@erpify.test`;

    // The gated trigger: an ADMIN holds `users.invite`, so the header button is rendered by <Can>.
    await page.goto("/backoffice/users");
    await expect(page.getByTestId("users-list")).toBeVisible({ timeout: COLD_ROUTE_TIMEOUT_MS });
    await expect(page.getByTestId("users-list")).toHaveAttribute("data-state", "ready");
    await page.getByTestId("users-list__invite-button").click();
    await expect(page).toHaveURL(/\/backoffice\/users\/invite$/);

    const form = page.getByTestId("invite-user-form");
    await expect(form).toHaveAttribute("data-hydrated", "true", { timeout: COLD_ROUTE_TIMEOUT_MS });

    await page.getByTestId("invite-user-form__email").fill(email);
    await page.getByTestId("invite-user-form__role-VIEWER").check();
    // No ADMIN, no proof: the field does not exist for this invitation.
    await expect(page.getByTestId("invite-user-form__current-password")).toHaveCount(0);
    await page.getByTestId("invite-user-form__submit").click();

    // Success navigates back to the register.
    await expect(page).toHaveURL(/\/backoffice\/users$/);
    await expect(page.getByTestId("users-list")).toHaveAttribute("data-state", "ready");

    // Narrow the server-side filter to the unique invitee, then assert its own row is INVITED — scoped to the
    // row that holds this unique email so other accumulated invited identities never make it ambiguous (no
    // exact counts either way, the dev DB accumulates identities).
    await page.getByTestId("users-filters__email").fill(email);
    const invitedRow = page.getByRole("row").filter({ hasText: email });
    await expect(invitedRow).toBeVisible();
    await expect(invitedRow.getByText("Invited")).toBeVisible();
  });

  test("inviting an administrator asks for the inviter's own password", async ({
    page,
  }, testInfo) => {
    testInfo.skip(
      testInfo.repeatEachIndex > 0,
      "each ADMIN invitation spends a password proof of the 10 per 15 minutes shared with mint and revoke; repeating would lock out the run",
    );
    const email = `invite-admin-e2e-${testInfo.parallelIndex}-${Date.now()}@erpify.test`;

    await page.goto("/backoffice/users/invite");
    await expect(page.getByTestId("invite-user-form")).toHaveAttribute("data-hydrated", "true", {
      timeout: COLD_ROUTE_TIMEOUT_MS,
    });

    await page.getByTestId("invite-user-form__email").fill(email);
    const password = page.getByTestId("invite-user-form__current-password");
    await expect(password).toHaveCount(0);
    await page.getByTestId("invite-user-form__role-ADMIN").check();
    await password.fill(E2E_USER_PASSWORD);
    await page.getByTestId("invite-user-form__submit").click();

    await expect(page).toHaveURL(/\/backoffice\/users$/);
    await page.getByTestId("users-filters__email").fill(email);
    const invitedRow = page.getByRole("row").filter({ hasText: email });
    await expect(invitedRow).toBeVisible();
    await expect(invitedRow.getByText("Invited")).toBeVisible();
  });
});
