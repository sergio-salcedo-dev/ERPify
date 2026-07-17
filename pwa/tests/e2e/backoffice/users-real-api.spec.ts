import { request } from "@playwright/test";
import { test, expect, type APIRequestContext } from "../fixtures/authenticatedTest";
import { E2E_USER_EMAIL, VIEWPORT_DESKTOP } from "../constants";
import { apiBaseURL } from "../fixtures/api";

/**
 * Read-only real-API E2E for the identity register. Unlike the mocked users specs,
 * this drives the actual Symfony read-side so the wire contract (envelope v2 +
 * per-view resource DTO) is exercised end-to-end. The register is invitation-based
 * with no create endpoint, so the suite reads the already-seeded admin (the E2E
 * user) instead of seeding its own rows — and never asserts exact counts, since the
 * dev DB is shared and accumulates identities across runs.
 */
test.describe.configure({ mode: "serial" });

interface UserRow {
  id: string;
  email: string;
  status: string;
  roles: string[];
}

test.describe("BackOffice - Users read-side (real API)", () => {
  test.use({ viewport: VIEWPORT_DESKTOP });

  let api: APIRequestContext;
  let seededUser: UserRow;

  test.beforeAll(async ({ workerStorageState }) => {
    api = await request.newContext({
      baseURL: apiBaseURL(),
      ignoreHTTPSErrors: true,
      storageState: workerStorageState,
    });

    // Resolve the seeded admin's id straight off the read endpoint, and prove the
    // list ships the cursor-only envelope v2 (data[] + pagination.links).
    const response = await api.get(
      "/api/v1/backoffice/users" +
        `?filters[0][field]=email&filters[0][operator]=contains&filters[0][value]=${encodeURIComponent(
          E2E_USER_EMAIL,
        )}&sort=email&limit=25`,
    );
    expect(response.ok()).toBe(true);

    const body = (await response.json()) as {
      data: UserRow[];
      pagination: { hasNext: boolean; hasPrev: boolean; links: { next: unknown; prev: unknown } };
    };
    expect(Array.isArray(body.data)).toBe(true);
    expect(body.pagination).toMatchObject({ hasNext: expect.any(Boolean), links: {} });
    expect(body.pagination.links).toHaveProperty("next");
    expect(body.pagination.links).toHaveProperty("prev");

    const found = body.data.find((row) => row.email === E2E_USER_EMAIL);
    if (!found) throw new Error(`E2E user ${E2E_USER_EMAIL} not found in the register read-side`);
    // The read-side row never carries a credential column.
    expect(found).not.toHaveProperty("password_hash");
    expect(found).not.toHaveProperty("permissions");
    seededUser = found;
  });

  test.afterAll(async () => {
    await api.dispose();
  });

  test("renders the register list from the live API and narrows by an email filter", async ({
    page,
  }) => {
    await page.goto("/backoffice/users");

    const list = page.getByTestId("users-list");
    await expect(list).toHaveAttribute("data-state", "ready");
    await expect(page.getByTestId("users-table")).toBeVisible();

    // Narrow the server-side filter to the known seeded admin so unrelated dev-DB
    // rows don't leak into the assertion (no exact counts either way).
    await page.getByTestId("users-filters__email").fill(E2E_USER_EMAIL);
    await expect(page.getByTestId(`users-table__row-${seededUser.id}`)).toBeVisible();
    await expect(page.getByRole("cell", { name: E2E_USER_EMAIL, exact: true })).toBeVisible();
  });

  test("opens a user detail with roles/status and no permissions section", async ({ page }) => {
    await page.goto(`/backoffice/users/${seededUser.id}`);

    await expect(page.getByTestId("users-detail")).toHaveAttribute("data-state", "ready");
    await expect(page.getByTestId("users-detail__email")).toHaveText(E2E_USER_EMAIL);
    await expect(page.getByTestId("users-detail__field-roles")).toBeVisible();
    await expect(page.getByTestId("users-detail__field-status")).toBeVisible();
    await expect(page.getByTestId("users-detail__id")).toHaveText(seededUser.id);

    // The detail view deliberately carries no permissions section — the roles ARE
    // the authorization map, and the real API returns no `permissions`.
    await expect(page.getByTestId("users-detail__field-permissions")).toHaveCount(0);
  });

  /**
   * The console is gated on `users.read`, which no role holds by tier — only an explicit grant reaches it.
   * Rendering the list therefore proves the whole chain end-to-end: the API derived a real permission set
   * from the session's roles, the client kept it, and `<Can>` opened on it. Before the set existed the gate
   * denied unconditionally, so this is the case that would have caught it.
   */
  test("crosses the users.read client gate with the permissions the live API derived", async ({
    page,
  }) => {
    const me = await api.get("/api/v1/me");
    expect(me.ok()).toBe(true);

    const identity = (await me.json()) as { data: { permissions: string[] } };
    expect(identity.data.permissions).toContain("users.read");

    await page.goto("/backoffice/users");

    // Settle first: asserting the gate's fallback is absent would pass vacuously against a page that has
    // simply not hydrated yet, which is exactly the state a broken gate leaves behind.
    await expect(page.getByTestId("users-list")).toHaveAttribute("data-state", "ready");
    await expect(page.getByText("Access denied")).toHaveCount(0);
  });
});
