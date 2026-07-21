import { request } from "@playwright/test";
import { test, expect, type APIRequestContext } from "../fixtures/authenticatedTest";
import { VIEWPORT_DESKTOP } from "../constants";
import { apiBaseURL } from "../fixtures/api";

/**
 * Write-side real-API E2E for the GDPR erasure: an ADMIN opens a member's detail, retypes their email to arm the
 * type-to-confirm gate, erases them, and lands back on the register with the detail now a 404. This is the one
 * layer that exercises the wired PWA→API round trip — the gated `<UserEraseControl>`, the type-to-confirm gate,
 * the real `DELETE /users/{id}`, the redirect, and the now-absent detail. The 409/403/401/400/404 matrix is owned
 * by the Behat suite; this is a single happy-path smoke.
 *
 * The erase is destructive, so its target is a DISPOSABLE identity minted per run (never a shared seeded user):
 * each test invites a fresh INVITED member with a unique email, then erases it. The erase control is gated on
 * `users.erase` alone (not on status), so an INVITED target is a valid subject.
 */
const COLD_ROUTE_TIMEOUT_MS = 30_000;

interface UserRow {
  id: string;
  email: string;
}

test.describe("BackOffice - Erase a user (real API)", () => {
  test.use({ viewport: VIEWPORT_DESKTOP });

  let api: APIRequestContext;

  test.beforeAll(async ({ workerStorageState }) => {
    api = await request.newContext({
      baseURL: apiBaseURL(),
      ignoreHTTPSErrors: true,
      storageState: workerStorageState,
    });
  });

  test.afterAll(async () => {
    await api.dispose();
  });

  test("an admin erases a member and the detail becomes a 404", async ({ page }, testInfo) => {
    const email = `erase-e2e-${testInfo.parallelIndex}-${Date.now()}@erpify.test`;

    // Mint a disposable INVITED identity to erase, then resolve its id off the read endpoint (filter by the
    // unique email — the dev DB accumulates identities, so never an exact count).
    const invite = await api.post("/api/v1/backoffice/invitations", {
      data: { email, roles: ["VIEWER"] },
    });
    expect(invite.ok()).toBe(true);

    const list = await api.get(
      "/api/v1/backoffice/users" +
        `?filters[0][field]=email&filters[0][operator]=contains&filters[0][value]=${encodeURIComponent(
          email,
        )}&sort=email&limit=25`,
    );
    expect(list.ok()).toBe(true);
    const body = (await list.json()) as { data: UserRow[] };
    const target = body.data.find((row) => row.email === email);
    if (!target) {
      throw new Error(`Disposable e2e identity ${email} not found in the register after invite.`);
    }

    await page.goto(`/backoffice/users/${target.id}`);
    await expect(page.getByTestId("users-detail")).toHaveAttribute("data-state", "ready", {
      timeout: COLD_ROUTE_TIMEOUT_MS,
    });

    // The control is gated on users.erase (only ADMIN reaches it), independent of the identity's status.
    await page.getByTestId("user-erase__trigger").click();
    await expect(page.getByTestId("user-erase__dialog")).toBeVisible();

    // Type-to-confirm: the confirm stays disabled until the typed text matches the subject's email.
    const confirm = page.getByTestId("user-erase__confirm");
    await expect(confirm).toBeDisabled();
    await page.getByTestId("user-erase__confirm-input").fill(email);
    await expect(confirm).toBeEnabled();
    await confirm.click();

    // Success redirects to the register…
    await expect(page).toHaveURL(/\/backoffice\/users$/);

    // …and the detail is now a 404 (the identity is gone).
    await page.goto(`/backoffice/users/${target.id}`);
    await expect(page.getByTestId("users-detail__not-found")).toBeVisible({
      timeout: COLD_ROUTE_TIMEOUT_MS,
    });
  });
});
