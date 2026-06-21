/**
 * The wire page-size ceiling, mirroring the API's
 * `WirePaginationPolicy::MAX_LIMIT` (100). Single source of truth for the
 * client (D-Cap): the page-size selector derives its options from it and the
 * search adapter clamps `limit` against it, so the UI can never emit
 * `limit > WIRE_MAX_LIMIT` — hard client enforcement complementary to the
 * backend 422, never a substitute for it.
 */
export const WIRE_MAX_LIMIT = 100;
