/**
 * Symfony HTTP API paths (see api/config/routes.yaml).
 * Use these instead of string literals when calling the API from the PWA.
 */
export const API_PREFIX_V1 = "/api/v1" as const;

export const ApiRoutes = {
  v1: {
    frontoffice: {
      health: `${API_PREFIX_V1}/health`,
    },
    backoffice: {
      health: `${API_PREFIX_V1}/backoffice/health`,
    },
  },
} as const;
