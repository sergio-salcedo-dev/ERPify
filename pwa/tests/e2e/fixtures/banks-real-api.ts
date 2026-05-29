import { request, type APIRequestContext } from "@playwright/test";

/**
 * Real-API helpers for the Banks E2E suite that exercises the live Symfony
 * backend instead of `page.route` interception.
 *
 * The PWA fetches `/api/v1/backoffice/*` from the same origin that serves it
 * (`https://localhost` in CI / Docker, `http://127.0.0.1:3000` proxies to the
 * stack locally via Next dev). We hit that origin directly with Playwright's
 * `request` fixture so seeds and cleanups run against the same DB rows the UI
 * will read.
 */

export interface ApiBank {
  id: string;
  name: string;
  shortName: string;
  createdAt: string;
  updatedAt: string;
}

const BANKS_PATH = "/api/v1/backoffice/banks";

/** Resolve the API origin from the same env Playwright resolves the PWA at. */
export function apiBaseURL(): string {
  return (
    process.env.PLAYWRIGHT_API_BASE_URL?.trim() ||
    process.env.PLAYWRIGHT_BASE_URL?.trim() ||
    "https://localhost"
  );
}

/**
 * Build a Playwright `APIRequestContext` rooted at the API origin. The
 * caller is responsible for `dispose()`-ing it (typically in `afterAll`).
 */
export async function createApiContext(): Promise<APIRequestContext> {
  return request.newContext({
    baseURL: apiBaseURL(),
    ignoreHTTPSErrors: true,
    extraHTTPHeaders: { Accept: "application/json" },
  });
}

/**
 * Run-scoped prefix used for every bank created by an E2E test. The list
 * filter is client-side and the API page size is large (1000), so seeded
 * rows always come back in the first response and a substring filter on
 * this prefix narrows the UI to test-owned data without touching whatever
 * banks the dev DB happens to hold.
 */
export function uniqueRunPrefix(label: string): string {
  const random = Math.random().toString(36).slice(2, 8);
  // E2E-<label>-<epoch>-<rand> — readable + collision-resistant across
  // parallel CI shards.
  return `E2E-${label}-${Date.now().toString(36)}-${random}`;
}

export async function createBank(
  api: APIRequestContext,
  name: string,
  shortName: string,
): Promise<ApiBank> {
  const response = await api.post(BANKS_PATH, {
    data: { name, shortName },
    headers: { "Content-Type": "application/json" },
  });
  if (!response.ok()) {
    throw new Error(`POST ${BANKS_PATH} failed (${response.status()}): ${await response.text()}`);
  }
  const body = (await response.json()) as { data: ApiBank };
  return body.data;
}

export async function deleteBank(api: APIRequestContext, id: string): Promise<void> {
  const response = await api.delete(`${BANKS_PATH}/${encodeURIComponent(id)}`);
  // 404 is fine — the UI may have already deleted it as part of the test.
  if (!response.ok() && response.status() !== 404) {
    throw new Error(
      `DELETE ${BANKS_PATH}/${id} failed (${response.status()}): ${await response.text()}`,
    );
  }
}

/**
 * Seed `count` banks named `<prefix> 001`, `<prefix> 002`, … so the default
 * "name ascending" sort produces a deterministic order in the UI. Short
 * names use a 50-char-safe truncation of the prefix + numeric suffix.
 */
export async function seedBanks(
  api: APIRequestContext,
  prefix: string,
  count: number,
): Promise<ApiBank[]> {
  const created: ApiBank[] = [];
  for (let i = 1; i <= count; i++) {
    const padded = i.toString().padStart(3, "0");
    const name = `${prefix} ${padded}`;
    // Short name has a 50-char limit on the API. Take a stable tail of the
    // prefix so it stays unique within the run.
    const shortPrefix = prefix.slice(-Math.min(prefix.length, 40));
    const shortName = `${shortPrefix}-${padded}`.slice(0, 50);
    created.push(await createBank(api, name, shortName));
  }
  return created;
}

/**
 * Best-effort cleanup. Deletes every bank in `ids`, swallowing per-row
 * failures so a single 5xx doesn't mask the test outcome. Logs to stderr
 * for triage — never throws.
 */
export async function deleteBanksSafely(
  api: APIRequestContext,
  ids: readonly string[],
): Promise<void> {
  for (const id of ids) {
    try {
      await deleteBank(api, id);
    } catch (err) {
      console.warn(`[banks-real-api] cleanup failed for ${id}:`, err);
    }
  }
}
