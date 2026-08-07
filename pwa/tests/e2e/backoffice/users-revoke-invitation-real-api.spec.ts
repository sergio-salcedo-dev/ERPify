import { test, expect } from "../fixtures/authenticatedTest";
import { VIEWPORT_DESKTOP } from "../constants";
import { clickUntilVisible } from "../helpers/click-until-visible";

/**
 * Write-side real-API E2E for withdrawing a pending invitation: an ADMIN invites a member and then revokes the
 * invitation straight from the register row. It drives the wired PWA→API round trip — the gated `⋯` row menu
 * on an INVITED row, the confirmation dialog, the real `DELETE /backoffice/users/{id}/invitation`, and the
 * silent re-read that follows.
 *
 * Its own invitee is minted per run (unique email) and located by filtering on it — never an exact row count,
 * since the dev DB accumulates identities across runs. The outcome asserted is the one the API leaves behind:
 * the revocation withdraws the identity in the same transaction, so the row survives at REVOKED, its badge
 * flips, and the action retires itself because it is gated on the INVITED status. The 404/403/401 matrix is
 * owned by the Behat suite.
 */
// The dev-mode stack compiles each route on its first hit, so the first assertion on a freshly-visited route
// absorbs that cold compile before the default per-assertion budget applies.
const COLD_ROUTE_TIMEOUT_MS = 30_000;

test.describe("BackOffice - Revoke a user invitation (real API)", () => {
  test.use({ viewport: VIEWPORT_DESKTOP });

  test("an admin revokes a pending invitation and the row is withdrawn", async ({
    page,
  }, testInfo) => {
    const email = `revoke-e2e-${testInfo.parallelIndex}-${Date.now()}@erpify.test`;

    // Mint the invitee through the console, so the row under test is this run's own.
    await page.goto("/backoffice/users/invite");
    const form = page.getByTestId("invite-user-form");
    await expect(form).toHaveAttribute("data-hydrated", "true", { timeout: COLD_ROUTE_TIMEOUT_MS });
    await page.getByTestId("invite-user-form__email").fill(email);
    await page.getByTestId("invite-user-form__role-VIEWER").check();
    await page.getByTestId("invite-user-form__submit").click();

    await expect(page).toHaveURL(/\/backoffice\/users$/);
    await expect(page.getByTestId("users-list")).toHaveAttribute("data-state", "ready", {
      timeout: COLD_ROUTE_TIMEOUT_MS,
    });

    // Narrow the server-side filter to the unique invitee so the row is unambiguous among the identities the
    // dev DB has accumulated. Waited for over the WIRE, not inferred from the DOM: the invitee is already on
    // the unfiltered page, so a visibility assertion passes while the filtered fetch is still in flight, and
    // that late response then re-renders the row out from under the dialog opened on it.
    const filteredSearch = page.waitForResponse(
      (response) =>
        response.request().method() === "GET" &&
        decodeURIComponent(response.url()).includes(email) &&
        response.ok(),
      { timeout: COLD_ROUTE_TIMEOUT_MS },
    );
    await page.getByTestId("users-filters__email").fill(email);
    await filteredSearch;

    // The cold-route budget rather than the default 5 s because this waits on a debounced round trip on a
    // dev-mode stack, where a rebuild can remount the tree and reset the list hook's state — the response
    // above having landed does not guarantee the repaint that follows it is the filtered one.
    await expect(page.locator('[data-testid^="users-table__row-"]')).toHaveCount(1, {
      timeout: COLD_ROUTE_TIMEOUT_MS,
    });
    const row = page.getByRole("row").filter({ hasText: email });
    await expect(row).toBeVisible();

    // The status badge is addressed by testid prefix rather than by re-deriving the identity's UUID here.
    const statusBadge = row.locator('[data-testid^="users-table__status-"]');
    await expect(statusBadge).toHaveText("Invited");

    // The row menu is gated on `users.revokeInvitation` plus the INVITED status. Both opens go through
    // `clickUntilVisible`: a just-opened popup can close again before its content mounts, so a single-shot
    // click is a race that passes locally and goes red under load.
    const actions = row.locator('[data-testid^="users-table__actions-"]');
    const revokeItem = page.locator('[data-testid^="users-table__revoke-"]');
    const confirm = page.getByTestId("user-revoke-invitation__confirm");

    await clickUntilVisible(actions, revokeItem);
    await clickUntilVisible(revokeItem, confirm);
    await confirm.click();

    // The dialog closes itself and nothing surfaces as an error.
    await expect(page.getByTestId("user-revoke-invitation__dialog")).toHaveCount(0);
    await expect(page.getByTestId("users-list__revoke-error")).toHaveCount(0);

    // The identity is withdrawn, not removed: the silent re-read keeps the row and flips its badge…
    await expect(row).toBeVisible();
    await expect(statusBadge).toHaveText("Revoked");
    // …and the action is gone with it, since the menu only exists for an INVITED row.
    await expect(row.locator('[data-testid^="users-table__actions-"]')).toHaveCount(0);
  });
});
