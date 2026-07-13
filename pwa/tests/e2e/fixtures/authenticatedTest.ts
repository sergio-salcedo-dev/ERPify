import { test as base, request } from "@playwright/test";

import { E2E_USER_EMAIL, E2E_USER_PASSWORD } from "../constants";
import { apiBaseURL } from "./banks-real-api";

/**
 * Per-worker authenticated session for the backoffice E2E suite. Each Playwright
 * worker mints its OWN server-side session once (its own `iam_session` registry
 * row) and every test that worker runs reuses it — the browser context and the
 * real-API request contexts alike — instead of the whole suite sharing one.
 *
 * Sessions are individually revocable: a sign-out revokes the registry row and the
 * fail-closed admission gate then 401s that session on its next `/api` use. With a
 * single shared session, one sign-out poisons every other spec — their pages never
 * hydrate past the gated `/me`, so each hangs to its timeout. Scoping the session
 * to the worker contains a revocation to that worker; the session-mutating specs
 * (sign-out) run on their own throwaway session (see `logout.spec.ts`) so they
 * never revoke a worker session a sibling test still needs.
 *
 * Every mint logs in as the same seeded user from one host, so they all draw on a
 * single `login_throttling` bucket (`max_attempts: 5`, keyed by client IP + email).
 * Symfony consumes a token on every attempt and resets on a successful login, so the
 * mints stay within budget at the worker counts in use — CI pins two workers and each
 * shard runs its own isolated stack. A very high local worker count could contend for
 * that shared bucket; scale it there via a distinct user or a relaxed E2E throttle.
 *
 * Specs that only touch public surfaces (login, sign-out, error pages, `/status`, the
 * frontoffice theme toggle) import from `@playwright/test` directly for a fresh,
 * unauthenticated context. Specs that reach a gated `/backoffice` surface import this
 * fixture — including `landing.spec` (its CTA lands on `/backoffice`) and `rate-limit`
 * (it opens the `/backoffice` dev-tools error gallery).
 */
type AuthWorkerFixtures = {
  workerStorageState: string;
};

// eslint-disable-next-line @typescript-eslint/no-empty-object-type
export const test = base.extend<{}, AuthWorkerFixtures>({
  storageState: ({ workerStorageState }, provide) => provide(workerStorageState),

  workerStorageState: [
    async ({}, provide) => {
      const index = test.info().parallelIndex;
      const storageStatePath = `tests/e2e/.auth/worker-${index}.json`;

      const context = await request.newContext({ baseURL: apiBaseURL(), ignoreHTTPSErrors: true });
      const response = await context.post("/api/v1/backoffice/login", {
        data: { email: E2E_USER_EMAIL, password: E2E_USER_PASSWORD },
        headers: { "Content-Type": "application/json", Origin: apiBaseURL() },
      });

      if (response.status() !== 204) {
        throw new Error(
          `worker ${index} login for ${E2E_USER_EMAIL} failed (${response.status()}) — ` +
            `is the E2E user seeded? body: ${await response.text()}`,
        );
      }

      await context.storageState({ path: storageStatePath });
      await context.dispose();

      await provide(storageStatePath);
    },
    { scope: "worker" },
  ],
});

export { expect } from "@playwright/test";
export type { APIRequestContext, Page } from "@playwright/test";
