import { request } from "@playwright/test";

import { E2E_USER_EMAIL, E2E_USER_PASSWORD } from "./constants";
import { apiBaseURL } from "./fixtures/api";
import { ensureLiveRecoverySecret } from "./fixtures/recovery-secret-api";

/**
 * Leaves the E2E administrator holding a LIVE recovery secret before any spec runs, and fails the
 * suite if it cannot.
 *
 * The back office warns every administrator without one on every page, and the E2E user is the
 * installation's administrator: without this, that warning stands first in `<main>` on every route
 * every spec visits. The one spec about the warning (`recovery-readiness-real-api`) builds its own
 * "none" state and ends live again. How the state is reached without spending the shared
 * password-proof budget needlessly is {@link ensureLiveRecoverySecret}'s.
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
