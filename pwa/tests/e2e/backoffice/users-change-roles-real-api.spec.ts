import { request } from "@playwright/test";
import { test, expect, type APIRequestContext } from "../fixtures/authenticatedTest";
import { E2E_USER_PASSWORD, VIEWPORT_DESKTOP } from "../constants";
import { apiBaseURL } from "../fixtures/api";

/**
 * Write-side real-API E2E for the identity role re-grant: an ADMIN opens a member's detail, replaces their role
 * set, and the detail badges reflect it. This is the one layer that exercises the wired PWA→API round trip end
 * to end — the gated `<UserRolesControl>` rendering for an ADMIN, the real `PATCH /users/{id}/roles` carrying the
 * complete set, and the badges after the reload. The 409/422/403/401/404 matrix is owned by the Behat suite;
 * this is a single happy-path smoke.
 *
 * Its target is a dedicated non-admin (`e2e-suspendable@erpify.test`, shared with the change-status smoke) — NOT
 * the signed-in admin, whose own roles the API refuses to change (409 `self-role-change-forbidden`) and whose
 * detail offers no form. The `make pwa.test.e2e` seed resets its roles to VIEWER before every run, so the
 * re-grant is repeatable. Roles are editable in any lifecycle status, so this spec is order-independent from the suspend smoke that may already have suspended the same member.
 *
 * Saving asks for the signed-in administrator's own password, in a confirmation dialog, and the write spends a
 * unit of the per-identity password-proof budget (10 per 15 minutes, shared with mint, revoke and the other
 * authority writes, and not relaxed for E2E). So it runs on the first repetition only (`--repeat-each`).
 */
const TARGET_EMAIL = "e2e-suspendable@erpify.test";

// The dev-mode stack compiles each route on its first hit, so the first assertion on a freshly-visited route
// absorbs that cold compile before the default per-assertion budget applies.
const COLD_ROUTE_TIMEOUT_MS = 30_000;

interface UserRow {
  id: string;
  email: string;
  roles: string[];
}

test.describe("BackOffice - Change user roles (real API)", () => {
  test.use({ viewport: VIEWPORT_DESKTOP });

  let api: APIRequestContext;
  let target: UserRow;

  test.beforeAll(async ({ workerStorageState }) => {
    api = await request.newContext({
      baseURL: apiBaseURL(),
      ignoreHTTPSErrors: true,
      storageState: workerStorageState,
    });

    // Resolve the seeded member off the read endpoint (filter by its unique email — the dev DB accumulates
    // identities, so never an exact count).
    const response = await api.get(
      "/api/v1/backoffice/users" +
        `?filters[0][field]=email&filters[0][operator]=contains&filters[0][value]=${encodeURIComponent(
          TARGET_EMAIL,
        )}&sort=email&limit=25`,
    );
    expect(response.ok()).toBe(true);

    const body = (await response.json()) as { data: UserRow[] };
    const found = body.data.find((row) => row.email === TARGET_EMAIL);
    if (!found) {
      throw new Error(
        `Role-editable e2e user ${TARGET_EMAIL} not found in the register — is the make/pwa.mk seed running?`,
      );
    }
    target = found;
  });

  test.afterAll(async () => {
    await api.dispose();
  });

  test("an admin replaces a member's role set and the detail reflects it", async ({
    page,
  }, testInfo) => {
    testInfo.skip(
      testInfo.repeatEachIndex > 0,
      "each save spends a password proof of the 10 per 15 minutes shared with mint and revoke; repeating would lock out the run",
    );
    // Derive the swap from whatever the member currently holds rather than from the seeded value, so a
    // Playwright retry — which re-reads a target this spec has already re-granted — still performs a real
    // change instead of asserting a set that is no longer there.
    const swap = target.roles.includes("VIEWER")
      ? { from: "VIEWER", to: "MANAGER", toLabel: "Manager", fromLabel: "Viewer" }
      : { from: "MANAGER", to: "VIEWER", toLabel: "Viewer", fromLabel: "Manager" };

    await page.goto(`/backoffice/users/${target.id}`);
    await expect(page.getByTestId("users-detail")).toHaveAttribute("data-state", "ready", {
      timeout: COLD_ROUTE_TIMEOUT_MS,
    });

    // The control is gated on users.changeRoles (only ADMIN reaches it) and renders in any lifecycle status.
    const control = page.getByTestId("user-roles");
    await expect(control).toBeVisible();
    await expect(control.getByTestId(`user-roles__role-${swap.from}`)).toBeChecked();

    // The checkboxes carry the complete resulting set, not a delta.
    await control.getByTestId(`user-roles__role-${swap.to}`).check();
    await control.getByTestId(`user-roles__role-${swap.from}`).uncheck();
    await control.getByTestId("user-roles__save").click();

    // The save only opens the confirmation; the administrator's own password is what lets it leave.
    await page.getByTestId("user-roles__password").fill(E2E_USER_PASSWORD);
    await page.getByTestId("user-roles__confirm").click();

    // reload() re-fetches after the mutation, so the detail badges show the re-granted set.
    await expect(page.getByTestId("users-detail__field-roles")).toContainText(swap.toLabel);
    await expect(page.getByTestId("users-detail__field-roles")).not.toContainText(swap.fromLabel);
    await expect(control.getByTestId(`user-roles__role-${swap.to}`)).toBeChecked();
  });
});
