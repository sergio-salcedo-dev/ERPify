/** Timeouts for async UI (API health checks, etc.). */
export const HEALTH_CHECK_TIMEOUT_MS = 30_000;

/**
 * E2E user. The whole `/api` is default-deny, so both the PWA's in-browser fetches and the direct
 * seed/cleanup request contexts need a session cookie. The `authenticatedTest` fixture mints one session per
 * Playwright worker by POSTing these credentials to the public `json_login` endpoint; the `logout.spec.ts`
 * sign-out tests log in through the UI with them on their own throwaway session. Keep these in sync with the
 * `E2E_USER_*` seed in `make/pwa.mk` (which creates the user in the stack before the run).
 */
export const E2E_USER_EMAIL = "e2e@erpify.test";
export const E2E_USER_PASSWORD = "e2ePassword123";

export const VIEWPORT_DESKTOP = { width: 1280, height: 720 } as const;
export const VIEWPORT_MOBILE = { width: 390, height: 844 } as const;
