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

export const API_ENDPOINTS = {
  BACKOFFICE: {
    HEALTH: `${BACKOFFICE_PREFIX}/health`,
    HEALTH_DATABASE: `${BACKOFFICE_PREFIX}/health/database`,
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
      CREATE: `${BACKOFFICE_PREFIX}/bank-accounts`,
      DETAILS: bankAccountPath,
      UPDATE: bankAccountPath,
      DELETE: bankAccountPath,
      CHANGE_STATUS: (id: string): string => `${bankAccountPath(id)}/status`,
      REALTIME_AUTHORIZE: `${BACKOFFICE_PREFIX}/bank-accounts/realtime/authorize`,
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
} as const;

export type ApiEndpoints = typeof API_ENDPOINTS;
