/**
 * Centralised registry of every Symfony HTTP API path consumed by the PWA.
 *
 * The Symfony backend mounts attribute routes under `/api/v1` (see
 * `api/config/routes.yaml` and the `#[Route]` attributes in
 * `api/src/<Context>/<Bounded-context>/Infrastructure/Controller/`). The
 * paths defined here MUST match the backend exactly — keep this file in
 * lock-step with `routes.yaml` and the controller attributes.
 *
 * Always go through `API_ENDPOINTS.*` from services / hooks / repositories
 * that perform `fetch` / HTTP calls. Never hand-write
 * `"/api/v1/backoffice/banks"` (or similar) in a feature module — typos
 * become 404s only at runtime.
 *
 * Adding a new module (e.g. Projects, Inventory, Auth) — add a new branch
 * under the matching prefix; the `as const` assertion keeps every path
 * literal-typed at the call site.
 */

const API_PREFIX_V1 = "/api/v1" as const;

const BACKOFFICE_PREFIX = `${API_PREFIX_V1}/backoffice` as const;
const FRONTOFFICE_PREFIX = API_PREFIX_V1;

function bankPath(id: string): string {
  return `${BACKOFFICE_PREFIX}/banks/${encodeURIComponent(id)}`;
}

function bankAccountsPath(bankId: string): string {
  return `${BACKOFFICE_PREFIX}/banks/${encodeURIComponent(bankId)}/accounts`;
}

function bankAccountPath(id: string): string {
  return `${BACKOFFICE_PREFIX}/bank-accounts/${encodeURIComponent(id)}`;
}

function userPath(id: string): string {
  return `${BACKOFFICE_PREFIX}/users/${encodeURIComponent(id)}`;
}

export const API_ENDPOINTS = {
  BACKOFFICE: {
    HEALTH: `${BACKOFFICE_PREFIX}/health`,
    HEALTH_DATABASE: `${BACKOFFICE_PREFIX}/health/database`,
    // Session sign-in — Symfony route name `identity_login` (204 on success).
    LOGIN: `${BACKOFFICE_PREFIX}/login`,
    // Request a password-reset link — uniform 202 for every case (existing,
    // unknown, any account state), so the response never reveals whether the
    // address matches an account. Same-origin only; no CSRF token.
    FORGOT_PASSWORD: `${BACKOFFICE_PREFIX}/forgot-password`,
    // Reset the password with the emailed token (204 on success, httpOnly session
    // cookie). Dead token → 400 `invalid-token`; suspended/forbidden → 403.
    RESET_PASSWORD: `${BACKOFFICE_PREFIX}/reset-password`,
    // Redeem a recovery secret the user types in (204 on success, httpOnly session cookie).
    // Every way the presentation can die — malformed, unknown, expired, already spent, budget
    // exhausted — answers the one opaque 400 `invalid-token`, so the client has nothing finer
    // to branch on; a non-active account answers 403. Needs an `X-CSRF-Token` header, like the
    // other two unauthenticated credential endpoints.
    RECOVERY_REDEEM: `${BACKOFFICE_PREFIX}/recovery/redeem`,
    INVITATIONS: {
      // Accept an invitation: set the credential, activate the account (204 on
      // success, httpOnly session cookie). Dead token → 400 `invalid-token`.
      ACCEPT: `${BACKOFFICE_PREFIX}/invitations/accept`,
      // Create an invitation (the alta): ADMIN-only (`users.invite`). 201 with an
      // empty body on success — the console refreshes the register to show the new
      // INVITED row; the accept token never leaves the email.
      CREATE: `${BACKOFFICE_PREFIX}/invitations`,
    },
    BANKS: {
      LIST: `${BACKOFFICE_PREFIX}/banks`,
      COUNT: `${BACKOFFICE_PREFIX}/banks/count`,
      CREATE: `${BACKOFFICE_PREFIX}/banks`,
      DETAILS: bankPath,
      UPDATE: bankPath,
      DELETE: bankPath,
      ACCOUNTS: bankAccountsPath,
      REALTIME_AUTHORIZE: `${BACKOFFICE_PREFIX}/banks/realtime/authorize`,
    },
    BANK_ACCOUNTS: {
      LIST: `${BACKOFFICE_PREFIX}/bank-accounts`,
      CREATE: `${BACKOFFICE_PREFIX}/bank-accounts`,
      DETAILS: bankAccountPath,
      UPDATE: bankAccountPath,
      DELETE: bankAccountPath,
      CHANGE_STATUS: (id: string): string => `${bankAccountPath(id)}/status`,
      REALTIME_AUTHORIZE: `${BACKOFFICE_PREFIX}/bank-accounts/realtime/authorize`,
      // Exact-match lookup by IBAN — POST body, never a query-string parameter, so the
      // value (financial PII) is never written to an access log. See issue #426.
      IBAN_LOOKUP: `${BACKOFFICE_PREFIX}/bank-accounts/iban-lookup`,
    },
    USERS: {
      LIST: `${BACKOFFICE_PREFIX}/users`,
      DETAILS: userPath,
      CHANGE_STATUS: (id: string): string => `${userPath(id)}/status`,
      CHANGE_ROLES: (id: string): string => `${userPath(id)}/roles`,
      // Revoke the pending invitation(s) of an identity — a DELETE keyed by user id (204, no body). Keyed by
      // the user because the console never learns an invitation id: the register publishes identities, and the
      // API resolves every still-pending invitation of that user, withdrawing the identity to REVOKED in the
      // same transaction. Nothing revocable → 404.
      REVOKE_INVITATION: (id: string): string => `${userPath(id)}/invitation`,
      // GDPR erasure — a DELETE on the detail URL (204, no body). Same target as DETAILS, different verb.
      ERASE: userPath,
    },
    AUDIT: {
      TIMELINE: `${BACKOFFICE_PREFIX}/audit/timeline`,
      EVENT_DETAIL: (id: string): string =>
        `${BACKOFFICE_PREFIX}/audit/events/${encodeURIComponent(id)}`,
    },
  },
  FRONTOFFICE: {
    HEALTH: `${FRONTOFFICE_PREFIX}/health`,
    DEV: {
      FRANKENPHP_HOT_RELOAD: `${FRONTOFFICE_PREFIX}/dev/frankenphp-hot-reload`,
    },
  },
  // Identity / session subsystem — mounted at the v1 root (not under a portal
  // prefix): the signed-in user reads their own identity and session registry.
  IDENTITY: {
    ME: `${API_PREFIX_V1}/me`,
    // Self-service credential change (204 on success). Verifies the current password,
    // so it needs no CSRF token beyond the same-origin session cookie. A wrong current
    // password answers 403 `invalid-current-password` — never 401, which the transport
    // would read as an expired session and bounce to the login page.
    CHANGE_PASSWORD: `${API_PREFIX_V1}/me/password`,
    // The account's standby recovery credential, on one path: read it (200), mint it (201 —
    // the plaintext is in that body and in no later one), revoke it (204). Minting proves
    // ownership with the current password, so like the change above it needs no CSRF token
    // beyond the same-origin session cookie, and answers 403 `invalid-current-password`
    // rather than a 401 the transport would read as an expired session.
    RECOVERY_SECRET: `${API_PREFIX_V1}/me/recovery-secret`,
    SESSIONS: `${API_PREFIX_V1}/sessions`,
    SESSIONS_REVOKE_OTHERS: `${API_PREFIX_V1}/sessions/revoke-others`,
    SESSIONS_REVOKE_CURRENT: `${API_PREFIX_V1}/sessions/revoke-current`,
  },
} as const;

export type ApiEndpoints = typeof API_ENDPOINTS;
