/**
 * The navigation links of a cursor-only page envelope, mirroring the API's
 * constant `links` shape (FR6). Both keys are present on every response —
 * `null` when the affordance does not apply. `string | null` is deliberately
 * NOT optional: the shape never varies, so the consumer always reads both keys.
 *
 * Each link is a server-composed relative URL that the client navigates to
 * VERBATIM (W9/W11 of the PR3 execution contract): the client never decodes the
 * cursor, never reconstructs the URL, and never re-appends `limit`/`sort`/
 * `direction`/`filters[]`. `SearchResponder` (API) is the single composer of
 * these links; the client is a pure consumer.
 */
export interface PaginationLinks {
  next: string | null;
  prev: string | null;
}
