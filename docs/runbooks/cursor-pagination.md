# Runbook — cursor-only keyset pagination

Operational guide for the cursor-only search pagination (keyset engine on the wire). Covers the wire
contract, the observability events, how to diagnose `invalid-cursor`, and how to roll back.

- **Contract / design**: [`docs/adr/keyset-pagination.md`](../adr/keyset-pagination.md)
- **Error contract**: [`docs/api-error-contract.md`](../api-error-contract.md) (`invalid-cursor` row)
- **API architecture**: [`docs/architecture-api.md`](../architecture-api.md)
- **Wire composer**: [`api/src/Shared/Infrastructure/Http/Responder/SearchResponder.php`](../../api/src/Shared/Infrastructure/Http/Responder/SearchResponder.php)
- **Observability listener**: [`api/src/Shared/Infrastructure/Http/EventListener/SearchObservabilityListener.php`](../../api/src/Shared/Infrastructure/Http/EventListener/SearchObservabilityListener.php)

> **No metrics backend.** There is no Prometheus / StatsD / OpenTelemetry / Grafana in this stack. The
> "metrics" and "dashboards" below are **structured JSON log lines** on a dedicated Monolog channel,
> queried by parsing. `invalid-cursor` is a 4xx client error, so it is **dropped before Sentry**
> (`before_send` discards `ClientError` — see [`api/config/packages/sentry.yaml`](../../api/config/packages/sentry.yaml)); logs are the only sink.

---

## 1. The wire contract (what "healthy" looks like)

`GET /api/v1/backoffice/banks` (and every `*_search` endpoint) returns:

```json
{
  "data": [ /* … resources … */ ],
  "pagination": { "hasNext": true, "hasPrev": false, "count": null,
                  "links": { "next": "/api/v1/backoffice/banks?limit=25&paginationMode=light&after=<opaque>", "prev": null } }
}
```

- **Envelope shape is constant (W1)** — `hasNext`, `hasPrev`, `count`, `links.next`, `links.prev` are
  **always present**; `null` when the affordance does not apply. There are **no** page numbers
  (`currentPage`/`pageCount`/`hasMorePages` are gone) and **no** exposed cursor scalar — the opaque
  cursor lives *inside* `links.next`/`links.prev`.
- **`count`** is `null` in `paginationMode=light` (default) and the **total** row count in
  `paginationMode=detailed` (an extra COUNT query).
- **`limit`** defaults to **25**, ceiling **100**. `limit ∉ [1, 100]` → `422 validation-failed`.
- **Cursors are opaque** `base64url(json{v,dir,values,fp}).hmac128` tokens. Never log, parse, or
  reconstruct them — neither server-side observability nor the client (W11). They can grow or change
  format across releases (`v` bump, FR15).

### The invariants operators rely on

| Invariant | Meaning on the wire | Operational consequence |
|-----------|---------------------|-------------------------|
| **W2 — single param serialization** | The navigation query string is built in **one** place: the server, inside `links`. The client never rebuilds it. | If two pages disagree on how `filters[]`/`sort`/`limit` serialize, suspect a second composer (client reconstruction) — a W2/W11 violation. |
| **W9 — single envelope composer** | The keyset engine + `Page` are link-agnostic (opaque cursors). `SearchResponder` is the **only** place a cursor becomes a relative URL. | A malformed/absolute/`/api/v1/api/v1/…` link means someone composed links outside `SearchResponder`. |
| **W10 — linkability** | `hasNext ⇒ links.next != null` and `hasPrev ⇒ links.prev != null` (not the converse). For an empty page the engine mints a **recovery cursor** so a true flag always carries a usable link. | A `{hasNext:true, links.next:null}` response is a contract bug — W10 forbids it by construction. |
| **W11 — no client reconstruction** | The PWA navigates by re-sending `links.next`/`links.prev` **verbatim** (after a same-origin/relative `safeHref` check). It never decodes or fabricates cursors. | If the client builds `after`/`before` itself in normal next/prev flow, it is a silent second composer. `buildSearchParams` is first-page/seam only. |

---

## 2. Empty pages are expected, not errors (W7)

Navigating `before`/`after` into a **logical gap** (rows deleted under the cursor) or off the end of the
dataset returns **`200` with `data: []`** — never an error. The flags are direction-coherent:

| Case | `hasNext` | `hasPrev` | `links.next` | `links.prev` |
|------|-----------|-----------|--------------|--------------|
| **empty `before` page** (gap behind you) | `true` | `false` | present (recovery cursor) | `null` |
| **empty `after` page** (end of set) | `false` | `true` | `null` | present |

An empty `before` page is **forward-recoverable only**: `hasNext=true, hasPrev=false`. This is the
sealed behavior (1.3 Option A) — the earlier "`before` empty ⇒ `hasPrev=true`" prose in the execution
contract / story AC was a **mislabel**, corrected to match `BankSearchCursorFunctionalTest` and the
Behat scenario *"Navigating before into a deleted gap returns an empty, forward-recoverable page"*.

---

## 3. Observability — the structured-log events

One JSON line per event on the dedicated **`observability` Monolog channel**
([`api/config/packages/monolog.yaml`](../../api/config/packages/monolog.yaml)). The channel has an
**always-on** handler (stderr in prod) — deliberately **off** the `fingers_crossed` `app` handler,
which buffers until a 5xx and would otherwise discard these non-error lines. Emitted by
`SearchObservabilityListener`; cursors are never included (NFR1).

### Event `keyset_search` — one per successful search response

```json
{ "event": "keyset_search", "route": "backoffice_bank_search", "limit": 25,
  "direction": "first|next|prev", "pagination_mode": "light|detailed",
  "count_mode": "LIGHT|DETAILED", "has_next": true, "has_prev": false,
  "correlation_id": "<uuidv7>" }
```

- `direction` — derived from the wire param: `after` → `next`, `before` → `prev`, neither → `first` page.
  Feeds the `next_navigation_count` / `prev_navigation_count` metrics (count by `direction`).
- `count_mode` — `DETAILED` means a COUNT query ran; watch its share for cost.
- `correlation_id` — join key to the full request trail (same UUIDv7 as the error log line and the
  `X-Correlation-Id` header).

### Event `invalid_cursor` — one per rejected cursor

```json
{ "event": "invalid_cursor", "cursor_cause": "signature|version|payload|fingerprint",
  "route": "backoffice_bank_search", "correlation_id": "<uuidv7>" }
```

The wire response is **indistinguishable** across causes (all `422 invalid-cursor`, identical title,
empty `context`) — only this log line carries the cause.

### Querying (the "dashboards")

No Grafana — query the JSON stream. Examples (`jq` over the stderr capture / log store):

```bash
# invalid_cursor_count{cause}
jq -r 'select(.context.event=="invalid_cursor") | .context.cursor_cause' | sort | uniq -c
# next vs prev navigation
jq -r 'select(.context.event=="keyset_search") | .context.direction' | sort | uniq -c
# DETAILED (COUNT-query) share
jq -r 'select(.context.event=="keyset_search") | .context.count_mode' | sort | uniq -c
```

If a real dashboard surface is ever wanted, it is a separate integration (ship these lines to a log
store with parsed fields); the schema above is the stable contract such a dashboard would key off —
the `event` discriminator lets it aggregate without re-instrumenting.

---

## 4. Diagnosing `invalid_cursor`

The four causes map to the cursor-validation DAG (signature → version → payload → fingerprint):

| `cursor_cause` | What it means | Likely trigger |
|----------------|---------------|----------------|
| `signature`  | HMAC signature mismatch | Tampered/truncated cursor; wrong `APP_SECRET`; a hand-crafted `after`/`before`. |
| `version`    | `v` (schema version) not the current one | **A release bumped the cursor format (FR15)** — in-flight cursors from before the deploy. |
| `payload`    | Decoded payload malformed / wrong arity | Garbage cursor; a cursor replayed against a different sort/filter shape. |
| `fingerprint`| `fp` does not match the current query trace | Cursor replayed under changed `filters[]`/`sort`/`direction`, or a query-semantics change that altered the `QueryExecutionTrace` (AR24). |

### ⚠️ Post-deploy spike of `cause=version` or `cause=fingerprint` — DO NOT rotate secrets

A spike right after a release is **expected** when the deploy bumped the cursor `v` (FR15) or changed
query semantics (a new sort/visibility dimension entering the trace, AR24). Old in-flight cursors
become `422 invalid-cursor`; clients recover by re-fetching the first page (the PWA discards cursors on
any query change, W8). **Verify the bump first** (changelog / `v` constant), do **not** assume a leaked
or rotated `APP_SECRET`. A signature-secret problem shows as `cause=signature`, not `version`.

A spike of `cause=signature` with **no** deploy is the real alarm: check `APP_SECRET` consistency
across instances and look for tampering.

---

## 5. Rollback

Cursor-only is now the **only** pagination contract: the legacy page-based stack and its transition
valve have been removed, so there is no runtime fallback and no single-PR revert back to page numbers.
Prefer a roll-forward fix. A full rollback to page-based pagination means reverting the legacy-removal
and the cursor-flip commits **together** (the keyset kernel + off-wire engine underneath them stay
intact); never force-push `main`. No migration rollback is involved — the composite indexes +
`COLLATE "C"` are independent of the wire contract and stay. The observability channel and listener
are additive and revert cleanly.

When undoing the wire flip, the API and the PWA consumer must move together: a page-based PWA against a
cursor-only API (or vice versa) is broken.

---

## 6. FR14 — guarantees and non-guarantees

**Guaranteed** (by the keyset engine + the property/Behat suites):

- **No duplicates and no skips caused by pagination itself** — every row is visited at most once across
  a forward (or backward) walk, including under mass ties on the sort key (id tiebreak).
- **Id-uniqueness within each page**.
- **Symmetric navigation** — `next×N` then `prev×N` retraces the exact reverse path (pinned in Behat).
- **Stable total ordering** — `(sort, id)` with `COLLATE "C"` on text columns; correct-by-result
  (AR4), independent of plan stability.

**NOT guaranteed** (documented limitations — set expectations with consumers):

- **No snapshot consistency between pages** — pagination navigates over the *current* state of the set.
  Rows inserted/deleted between page fetches can appear/disappear; the only promise is no
  pagination-induced duplicate or skip, not a frozen snapshot.
- **No random access / "jump to page N"** — there are no page numbers; navigation is strictly
  next/prev via server links. (A server-side "go to date" seam is deferred, not shipped.)
- **No cursor stability across releases** — a `v` bump (FR15) or a query-semantics change invalidates
  in-flight cursors (`422 invalid-cursor`); this is an explicit, observable per-release decision.
- **`count` only in `detailed` mode** — `light` (default) never runs a COUNT and returns `count: null`.
