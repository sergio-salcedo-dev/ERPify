import { test, expect, type APIRequestContext, type Page } from "@playwright/test";
import { VIEWPORT_DESKTOP } from "../constants";
import {
  createApiContext,
  createBank,
  deleteBank,
  deleteBanksSafely,
  seedBanks,
  uniqueRunPrefix,
  type ApiBank,
} from "../fixtures/banks-real-api";

/**
 * Real-API E2E coverage for the **Mercure real-time sync** feature
 * (`feat/banks-mercure-realtime`). Where `banks-real-api*.spec.ts` drive the
 * CRUD surface from a single browser, this suite proves the cross-client
 * promise: a mutation performed by *another* client appears in *this* client's
 * open list / detail view without a reload.
 *
 * Modelling the "other user"
 * - The **browser page** is the observing user (the one who must update live).
 * - The **`APIRequestContext`** plays the acting user: it POST/PUT/DELETEs
 *   against the live Symfony API, which records a domain event, the
 *   `messenger_worker` consumes it, and `BankRealtimePublisherHandler`
 *   publishes a private Mercure `Update`. This is deterministic and avoids the
 *   flakiness of coordinating two real browser contexts.
 *
 * Killing the connect-before-publish race
 * - SSE only delivers events that occur *after* the subscription is open. Each
 *   test therefore awaits the `/.well-known/mercure` response (the EventSource
 *   handshake) before mutating via the API. Without this the publish can race
 *   ahead of the subscription and the event is silently missed.
 * - Assertions absorb the request -> worker -> hub -> browser round-trip via a
 *   generous per-assertion timeout (`REALTIME_TIMEOUT_MS`).
 *
 * Running locally requires the full stack (`make docker.up.wait` /
 * `make app.dev`) and an HTTPS same-origin base URL so the private Mercure
 * cookie (SameSite=Strict) is sent with the EventSource request:
 *   PLAYWRIGHT_BASE_URL=https://localhost make pwa.test.e2e
 * (or the worktree stack's mapped 443 port, e.g. https://localhost:32773).
 */

const REALTIME_TIMEOUT_MS = 20_000;

/**
 * Resolve the EventSource handshake. Registered BEFORE the navigation/effect
 * that opens it; `waitForResponse` resolves as soon as the SSE response headers
 * arrive (the stream then stays open), which is our "subscription is live"
 * signal.
 */
function waitForMercureConnected(page: Page): Promise<unknown> {
  return page.waitForResponse((response) => response.url().includes("/.well-known/mercure"), {
    timeout: REALTIME_TIMEOUT_MS,
  });
}

/** Open the list, narrow the client-side filter to this run's seeded rows. */
async function openListFilteredByPrefix(page: Page, prefix: string): Promise<void> {
  await page.goto("/backoffice/banks");
  await expect(page.getByTestId("banks-list")).toHaveAttribute("data-state", "ready");
  await page.getByTestId("banks-filters__toggle").click();
  await page.getByTestId("banks-filters__name").fill(prefix);
}

test.describe.configure({ mode: "serial" });

test.describe("BackOffice - Banks real-time sync via Mercure (real API)", () => {
  test.use({ viewport: VIEWPORT_DESKTOP });

  const runPrefix = uniqueRunPrefix("BankRealtime");
  const trackedIds: string[] = [];
  let api: APIRequestContext;
  let seeded: ApiBank[] = [];

  test.beforeAll(async () => {
    api = await createApiContext();
    // Seed a small set so the list renders in the READY state (the filters
    // panel only mounts when READY) and provides stable rows for the
    // update/delete observers below.
    seeded = await seedBanks(api, runPrefix, 3);
    trackedIds.push(...seeded.map((bank) => bank.id));
  });

  test.afterAll(async () => {
    await deleteBanksSafely(api, trackedIds);
    await api.dispose();
  });

  test("list — a bank CREATED by another client appears live", async ({ page }) => {
    const connected = waitForMercureConnected(page);
    await openListFilteredByPrefix(page, runPrefix);
    await connected;

    // Another client creates a bank under our prefix.
    const created = await createBank(
      api,
      `${runPrefix} LIVE-CREATE`,
      `${runPrefix.slice(-40)}-LC`.slice(0, 50),
    );
    trackedIds.push(created.id);

    // It streams into the open list without a reload.
    await expect(page.getByTestId(`banks-table__row-${created.id}`)).toBeVisible({
      timeout: REALTIME_TIMEOUT_MS,
    });
    await expect(
      page.getByRole("cell", { name: `${runPrefix} LIVE-CREATE`, exact: true }),
    ).toBeVisible({ timeout: REALTIME_TIMEOUT_MS });
  });

  test("list — a bank UPDATED by another client re-renders live", async ({ page }) => {
    const target = seeded[0];
    const renamed = `${target.name} (live-renamed)`;

    const connected = waitForMercureConnected(page);
    await openListFilteredByPrefix(page, runPrefix);
    await connected;

    await expect(page.getByTestId(`banks-table__row-${target.id}`)).toBeVisible();

    // Another client renames it via PUT.
    const response = await api.put(`/api/v1/backoffice/banks/${target.id}`, {
      data: { name: renamed, shortName: target.shortName },
      headers: { "Content-Type": "application/json" },
    });
    expect(response.ok()).toBe(true);

    // The open row reflects the new name with no navigation.
    await expect(page.getByRole("cell", { name: renamed, exact: true })).toBeVisible({
      timeout: REALTIME_TIMEOUT_MS,
    });
  });

  test("list — a bank DELETED by another client drops out of the list live", async ({ page }) => {
    // Use a dedicated bank so the seeded set stays intact for other tests.
    const victim = await createBank(
      api,
      `${runPrefix} LIVE-DELETE`,
      `${runPrefix.slice(-40)}-LD`.slice(0, 50),
    );
    trackedIds.push(victim.id);

    const connected = waitForMercureConnected(page);
    await openListFilteredByPrefix(page, runPrefix);
    await connected;

    await expect(page.getByTestId(`banks-table__row-${victim.id}`)).toBeVisible();

    // Another client deletes it.
    await deleteBank(api, victim.id);

    // The row disappears from the open list without a reload.
    await expect(page.getByTestId(`banks-table__row-${victim.id}`)).toBeHidden({
      timeout: REALTIME_TIMEOUT_MS,
    });
  });

  test("detail — an UPDATE by another client re-renders the open detail view", async ({ page }) => {
    const target = seeded[1];
    const renamed = `${target.name} (detail-live-renamed)`;

    const connected = waitForMercureConnected(page);
    await page.goto(`/backoffice/banks/${target.id}`);
    await expect(page.getByTestId("banks-detail")).toHaveAttribute("data-state", "ready");
    await connected;

    await expect(page.getByTestId("banks-detail__name")).toHaveText(target.name);

    const response = await api.put(`/api/v1/backoffice/banks/${target.id}`, {
      data: { name: renamed, shortName: target.shortName },
      headers: { "Content-Type": "application/json" },
    });
    expect(response.ok()).toBe(true);

    // Both the heading and the meta field update live.
    await expect(page.getByTestId("banks-detail__name")).toHaveText(renamed, {
      timeout: REALTIME_TIMEOUT_MS,
    });
    await expect(page.getByTestId("banks-detail__field-name")).toHaveText(renamed, {
      timeout: REALTIME_TIMEOUT_MS,
    });
  });

  test("detail — a DELETE by another client redirects the open detail view to the list", async ({
    page,
  }) => {
    // Dedicated bank so the redirect-on-delete doesn't consume a shared row.
    const victim = await createBank(
      api,
      `${runPrefix} DETAIL-DELETE`,
      `${runPrefix.slice(-40)}-DD`.slice(0, 50),
    );
    trackedIds.push(victim.id);

    const connected = waitForMercureConnected(page);
    await page.goto(`/backoffice/banks/${victim.id}`);
    await expect(page.getByTestId("banks-detail")).toHaveAttribute("data-state", "ready");
    await connected;

    // Another client deletes the bank being viewed.
    await deleteBank(api, victim.id);

    // The detail view redirects back to the list (mirrors the local delete flow)
    // instead of flashing a "Bank not found" error.
    await expect(page).toHaveURL(/\/backoffice\/banks$/, { timeout: REALTIME_TIMEOUT_MS });
    await expect(page.getByTestId("banks-list")).toBeVisible({ timeout: REALTIME_TIMEOUT_MS });
  });
});
