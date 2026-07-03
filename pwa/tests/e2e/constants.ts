/** Timeouts for async UI (API health checks, etc.). */
export const HEALTH_CHECK_TIMEOUT_MS = 30_000;

/**
 * E2E session. The whole `/api` is default-deny, so both the PWA's in-browser fetches and the direct
 * seed/cleanup request contexts need a session cookie. There is no login UI yet, so the `setup` project mints
 * the session by POSTing the public `json_login` endpoint with these credentials, and persists it to
 * `E2E_STORAGE_STATE` for the browser project and the real-API contexts to reuse. Keep these in sync with the
 * `E2E_USER_*` seed in `make/pwa.mk` (which creates the user in the stack before the run).
 */
export const E2E_USER_EMAIL = "e2e@erpify.test";
export const E2E_USER_PASSWORD = "e2ePassword123";
export const E2E_STORAGE_STATE = "tests/e2e/.auth/state.json";

export const VIEWPORT_DESKTOP = { width: 1280, height: 720 } as const;
export const VIEWPORT_MOBILE = { width: 390, height: 844 } as const;
