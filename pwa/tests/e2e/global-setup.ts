import { request } from "@playwright/test";

import { E2E_USER_EMAIL, E2E_USER_PASSWORD } from "./constants";
import { apiBaseURL } from "./fixtures/api";
import { ensureLiveRecoverySecret } from "./fixtures/recovery-secret-api";

/**
 * Leaves the E2E administrator holding a LIVE recovery secret before any spec runs, and fails the
 * suite if it cannot.
 *
 * The back office refuses an administrator holding no secret at all: an enrolment step replaces the
 * page on every route until one is minted. The E2E user is an installation administrator, so without
 * this every spec that visits a back-office route would land on that step instead of its page. The one
 * spec about the wall (`recovery-readiness-real-api`) builds its own "none" state, runs alone in its
 * own Playwright project after everything else, and ends live again. This is the only administrator
 * global setup signs in; the redemption identity of `recovery-redeemed-notice-real-api` mints its own
 * secret inside that spec. How the state is reached without spending the shared password-proof budget
 * needlessly is {@link ensureLiveRecoverySecret}'s.
 *
 * It writes no `storageState`: every worker still mints its own session (`authenticatedTest`), and
 * this one is revoked on the way out.
 */
export default async function globalSetup(): Promise<void> {
  const api = await request.newContext({ baseURL: apiBaseURL(), ignoreHTTPSErrors: true });
  try {
    const login = await api.post("/api/v1/backoffice/login", {
      data: { email: E2E_USER_EMAIL, password: E2E_USER_PASSWORD },
      headers: { "Content-Type": "application/json", Origin: apiBaseURL() },
    });
    if (login.status() !== 204) {
      throw new Error(
        `global setup login for ${E2E_USER_EMAIL} failed (${login.status()}) — is the E2E user seeded?`,
      );
    }

    await ensureLiveRecoverySecret(api);
  } finally {
    await api
      .post("/api/v1/sessions/revoke-current", { headers: { Origin: apiBaseURL() } })
      .catch(() => undefined);
    await api.dispose();
  }
}
