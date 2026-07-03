import { test as setup, expect, request } from "@playwright/test";

import { E2E_STORAGE_STATE, E2E_USER_EMAIL, E2E_USER_PASSWORD } from "./constants";
import { apiBaseURL } from "./fixtures/banks-real-api";

/**
 * Mints an authenticated session once and persists it as Playwright `storageState`, reused by the browser
 * project (its in-page `/api` fetches) and the real-API request contexts (seed/cleanup). The whole `/api` is
 * default-deny, and there is no login UI yet, so the session is established by POSTing the public `json_login`
 * endpoint with the seeded E2E user's credentials (the same user `make/pwa.mk` creates in the stack before the
 * run). A `204` proves the credential check passed and the `Set-Cookie` session landed in the context jar.
 */
setup("authenticate the e2e session", async () => {
  const context = await request.newContext({ baseURL: apiBaseURL(), ignoreHTTPSErrors: true });

  const response = await context.post("/api/v1/backoffice/login", {
    data: { email: E2E_USER_EMAIL, password: E2E_USER_PASSWORD },
    headers: { "Content-Type": "application/json", Origin: apiBaseURL() },
  });

  expect(
    response.status(),
    `login for ${E2E_USER_EMAIL} failed — is the E2E user seeded? body: ${await response.text()}`,
  ).toBe(204);

  await context.storageState({ path: E2E_STORAGE_STATE });
  await context.dispose();
});
