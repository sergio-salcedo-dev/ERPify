# Deferred Work

## Deferred from: review of `spec-pwa-bank-list-filters-sort` (2026-05-08)

- **Timezone semantics: client-local vs row UTC.** `applyFilters` interprets `<input type="date">` bounds as start-of-day / end-of-day **local** time (matches the spec's Always rule), but `bank.createdAt` arrives as UTC ISO with `Z`. In a non-UTC runner the day-aligned local-bound vs UTC instant comparison can include or exclude rows near the midnight boundary unexpectedly. The current unit + E2E suite happens to run in UTC so the case never trips. Options: normalize both sides to UTC (changes the spec's "local" rule) or document the dependency and pin Playwright TZ. [`pwa/src/app/backoffice/banks/_lib/banksFilterSort.ts:31-41`]
- **Daylight-saving boundary day shifts the bound by an hour.** Same module, same root: parsing `T00:00:00` / `T23:59:59.999` as local time on a DST-transition day yields a 23h or 25h window, which can include/exclude rows at the wrong instant. [`pwa/src/app/backoffice/banks/_lib/banksFilterSort.ts:31-41`]
- **Unicode normalization (NFC vs NFD) on name / shortName.** `String.prototype.toLowerCase().includes(...)` does not normalize composed vs decomposed accents, so visually identical strings (e.g. `"Café"` precomposed vs combining-mark) fail to match each other. Add `.normalize("NFC")` on both sides if banks ever land with non-ASCII names. [`pwa/src/app/backoffice/banks/_lib/banksFilterSort.ts:50-52`]
- **Turkish dotted/dotless I.** `toLowerCase` uses default locale; in Turkish, `İ → i̇` and `I → ı`. A user typing `"istanbul"` will not match a bank stored as `"İSTANBUL"`. Use `Intl.Collator` for substring matching, or `.toLocaleLowerCase("en")` if the dataset is ASCII-only. [`pwa/src/app/backoffice/banks/_lib/banksFilterSort.ts:50-52`]
- **Legacy `<input type="date">` (Safari < 14.1, some embedded webviews) accepts free-form text.** Locale-formatted input like `01/02/2026` is fed to `startOfDay()` / `endOfDay()` and silently treated as invalid (effectively disabling the bound). Validate `^\d{4}-\d{2}-\d{2}$` before propagating, or render a date-picker polyfill. [`pwa/src/app/backoffice/banks/_components/BanksFilters.tsx:51-67`]
- **No `aria-invalid` / live error when `from > to`.** The list silently renders the no-matches panel; a screen-reader user gets no causal hint. Wire a validation message into `<FormField error>` for the createdFrom/createdTo pair when impossible. [`pwa/src/app/backoffice/banks/_components/BanksFilters.tsx:51-67`]
- **No `aria-live` region for filtered result count or "no matches" state.** Screen-reader users do not get spoken feedback as they type into filters or when the result narrows to zero. Wrap the count / panel in `role="status" aria-live="polite"`. [`pwa/src/app/backoffice/banks/page.tsx:117-149`]

## Deferred from: follow-up code review of `spec-pwa-bank-crud` (2026-05-08)

- **No `AbortSignal` on detail/edit page fetches.** The `cancelled` flag prevents state writes after unmount, but the underlying fetch keeps running and burns network/backend cycles. Adding an `AbortController` and threading the signal through the use case + repository would also abort the request itself.
- **Inversify container in client bundle.** Pages converted to `"use client"` import the container module; all DI bindings (FetchHttpClient, MockHttpClient, every adapter, every use case) are now shipped to the browser. The existing health page already does this; resolve project-wide rather than per-feature.
- **`crypto.randomUUID()` has no fallback for non-secure contexts.** Older Safari and http intranets throw on the call. Synthesized ProblemDetails (`genericProblem`) and the legacy-envelope translator both rely on it. Consider a small uuid polyfill or a `crypto.randomUUID?.() ?? Math.random().toString(36)` guard.
- **`genericProblem.status: 0`.** Some strict RFC 9457 validators reject `status` outside 100..599. A small sentinel (e.g. `status: 500`) or omitting the field would be safer.
- **`genericProblem` mints distinct UUIDs for `instance` and `correlation-id`.** They should be the same value (or `instance` should be a urn derived from `correlation-id`) so logs can be tied together.
- **`genericProblem(err.message)` swallows `Error.cause` and stack.** Non-HttpError failures (Inversify resolution, DNS, AbortError) reduce to a one-line `detail`. Either log to `console.error` or expand the synthesized PD with a `cause` extension member.
- **`Link className={buttonVariants(...)}` exposes `role="link"`, not `role="button"`.** Screen readers announce "Edit, link" rather than "Edit, button". Add `role="button"` (with care: the keyboard interaction model for links uses Enter, button uses Enter+Space) or accept the link semantics for navigation.

## Deferred from: code review of `spec-pwa-bank-crud` (2026-05-08)

- **Container singleton scope vs request isolation.** `Container.ts` binds `BackOfficeBankRepository` `inSingletonScope`, with a module-level container shared across all server requests. Once auth/session land, this leaks cookies/headers between users. Pre-existing pattern across the kernel; address as part of auth integration.
- **Non-HttpError handling in pages.** List/detail/edit pages catch `HttpError` and rethrow everything else, which surfaces as a Next.js 500 page instead of a `<ProblemDisplay>` fallback. Spec did not mandate the fallback path; consider mirroring the existing health-page synthesised-PD pattern.
- **API response shape validation.** `ApiBankRepository` and `Bank.fromPrimitives` trust the server's payload. Defensive guards (e.g. `if (!Array.isArray(response.data)) throw …`) would surface malformed responses as proper ProblemDetails instead of generic crashes.
- **Abort / unmount cleanup.** No `AbortSignal` plumbing on `FetchHttpClient` or its consumers; React 19 strict-mode and rapid navigation can call `setState` after unmount in `BankForm` / `DeleteBankButton`.
- **Delete dialog error visibility.** Closing the dialog mid-DELETE drops the error display. Either keep the dialog open until the request resolves or surface the error via a toast.
- **Long-name overflow in delete dialog.** `<DialogDescription>` renders the bank name inline without `truncate` / `break-all`; very long names break layout.
- **Date validation across the bank pages.** `new Date(updatedAt).toLocaleString()` in the list and detail renders without guards — null/missing/invalid ISO strings surface as `Invalid Date`.
- **DI string tokens vs Symbols.** Container uses string literals (`"BackOfficeBankRepository"`, etc.); a Symbol-based identifier would catch collisions at type level. Project-wide concern, not bank-specific.
- **Unsaved-changes guard on `BankForm`.** No `beforeunload` / router-event prompt when the form is dirty; closing the tab silently discards user input.

## Deferred from: review of `spec-pwa-http-client-writes` (2026-05-08)

- **`FetchHttpClient.toHttpError` discards non-JSON error bodies.** Servers/proxies sometimes emit `text/plain` or HTML on 5xx (gateway timeout, WAF block). The current `await res.json().catch(() => null)` reduces them to `null` and the synthesized ProblemDetails carries only the status code. Consider reading via `res.text()` first and stashing the raw text under `problem.detail` (truncated) when JSON parse fails, so operators retain the diagnostic.
- **No timeout / abort signal on `FetchHttpClient`.** Every method is a bare `fetch` with no `AbortSignal`, no timeout, no idempotent retry. A hung backend will hang the React tree. Adding an optional `signal` and a default timeout (e.g. 30s) is reasonable shared-infra hardening.
- **Health page should consume `err.problem` directly.** `pwa/src/app/backoffice/health/page.tsx` already has a TODO ("When the BackOffice CheckHealth adapter starts returning RFC 9457 envelopes…"). With `HttpError` now carrying real `ProblemDetails`, the page can drop its synthesized stand-in: `if (err instanceof HttpError) setProblem(err.problem)`.
- **Translator coverage gap: status mismatch on pass-through.** When `isProblemDetails(body) === true` but `body.status !== HTTP status`, the translator trusts the body. Add a test (or normalize to the wire status) once a real consumer surfaces the divergence.

## Deferred from: spec split of `spec-pwa-bank-crud` (2026-05-08)

Original intent was a single PWA Backoffice Bank CRUD frontend spec. After the spec exceeded the 1600-token soft target, the user chose to split. The shared HttpClient extension + ProblemDetails translator ships first (now `spec-pwa-http-client-writes.md`); the rest is deferred:

- **PWA Backoffice Bank context + pages + nav + E2E** — once `HttpClient.{post,put,delete}`, `HttpError`, and `legacyEnvelope.toProblemDetails` are merged, write a follow-up spec covering:
  - `pwa/src/context/backoffice/bank/{domain,application,infrastructure}/*` — `Bank`, `BankRepository` (search/find/create/update/delete), `BankNotFoundError`, 5 `@injectable` use cases, `ApiBankRepository`.
  - `pwa/src/context/shared/infrastructure/ApiRoutes.ts` — add `backoffice.banks { list, byId(id) }`.
  - `pwa/src/context/shared/infrastructure/DependencyInjection/Container.ts` — bind `BackOfficeBankRepository` + 5 use cases (`BackOffice<UseCase>` symbol pattern).
  - `pwa/src/app/backoffice/banks/{page,new/page,[id]/page,[id]/edit/page}.tsx` — dedicated routes (list / new / detail / edit).
  - `pwa/src/app/backoffice/banks/_components/{BankForm,DeleteBankDialog}.tsx` — shared form (`name` ≤255, `shortName` ≤50) and AlertDialog confirm.
  - `pwa/src/app/backoffice/BackOfficeLayoutClient.tsx` — add `Catalogs > Banks` (lucide `Building2`) above the existing `System` group.
  - `pwa/tests/e2e/backoffice/banks.spec.ts` + `pwa/tests/e2e/fixtures/banks-api.ts` — Playwright happy paths and error states (422 with legacy envelope, 404, etc.).
  - Excluded: image / Media / StoredObject upload (PWA sends JSON `{name, shortName}` only).

## Deferred from: code review of 2026-05-07-port-behat-doctrine-context (2026-05-07)

- **Empty-backtrace early return is dead code in practice** — `TestDebugDataHolder::shouldLog()` returns `true` on `[] === $backtraces`. PHP's `debug_backtrace()` always includes at least the calling frame, so this branch is unreachable. Faithful port from chiliz/test-bundle.
- **`INCLUDED_CLASSES` works by ordering luck** — `shouldLog()` checks `INCLUDED_CLASSES` before `isSkippedClass()` per iteration. The Symfony EventDispatcher / ConsumeMessagesCommand entries match the exact-class check before the `Symfony` prefix-skip would `continue`. Reordering the checks for "speed" would silently break Messenger/EventDispatcher capture. Add a clarifying comment.
- **Static state lifecycle vs parallel runners** — `TestDebugDataHolder::$data` and `$backtraces` are process-globals. Behat is single-process, so `BeforeScenario` reset is sufficient. If parallel test runners are introduced, this becomes a race.
- **`getData()` mutation + Query callable reference retention** — `getData()` replaces the `executionMS` callable with a resolved float on first call (mutation), and `$query->getDuration(...)` retains the `Query` instance for the lifetime of the static array. Long scenarios with many queries grow memory until `reset()` runs. Faithful port.
- **`requestDoesNotContainContent` is existential, not universal** — Returns success on the first non-matching connection. The step phrasing implies "for every connection at index N, the SQL does not contain X". Faithful port; behavior change risks breaking scenarios that rely on the existing semantics.
- **Misleading errors when a named connection doesn't exist** — `oneOfTheRequestsForConnectionContains` and `queriesWereExecutedOnlyOnConnection` produce generic failures when the connection isn't in `$data`. Better diagnostics on a future pass.
- **`statementTypeCountIsEqualTo` SQL-type parser is fragile** — `explode(' ', $sql)[0]` then `strtoupper`. Doesn't handle leading whitespace, `/* */` comments, or CTE prefixes (`WITH ... SELECT`). Faithful port.
- **`array_slice($backtraces, 2)` assumes fixed call depth** — Drops "this method + DebugMiddleware's invoker frame" by index. A future Symfony middleware refactor (added wrapper, different invocation path) silently misaligns the stored backtrace from its data record.
- **`profiling_collect_backtrace: true` redundant** — The subclass overrides `addQuery` and captures its own backtrace via `debug_backtrace()`; the parent class's flag is dead for our subclass. Harmless; could be dropped if a future Symfony version repurposes the flag.
- **`Symfony` namespace prefix-skip is broad** — Apps that namespace code under `Symfony\App\Controller\...` (rare but legal) have queries dropped before the suffix/namespace branches run. Faithful port.
- **`queriesWereExecutedOnlyOnConnection` requires the connection to be non-empty** — Fails when zero queries ran on the named connection AND zero queries ran anywhere. The step phrase arguably should pass when the only-active connection is X. The smoke feature's validation-reject case sidesteps this with `0 request(s)` across all instead. Faithful port.
- **Wiring test asserts alias resolution only** — `TestDebugDataHolderWiringTest` doesn't verify `DebugMiddleware` is registered in the middleware chain. The bank `query-stats.feature` covers this transitively; harden the wiring test if regressions appear.
- **Substring-match needle can match column names / literals** — `oneOfTheRequestsForConnectionContains` uses `str_contains($sql, $needle)`. A needle like `INSERT` matches a SELECT containing column `INSERT_AT`. Faithful port.
- **`requestArgumentIsEqualTo` first-match-wins across connections** — Returns on the first connection whose query[N] has a matching argument. With multiple connections, results are order-dependent on `getData()` iteration. Faithful port.
- **`ctype_digit('00')` coerces leading-zero param names to int 0** — A param named `'00'` is wrongly compared as integer 0 against indexed param 0. Faithful port edge case.
- **Closures with no `class` key drop queries silently** — `debug_backtrace()` frames for closures lack a `class` key; `shouldLog` falls through every branch and returns false. Faithful port.
- **App classes without Controller/Command/Resolver/ParamConverter suffix and without `\Controller\` namespace drop queries** — `MessageHandler`, `Repository`, domain services issuing direct queries are filtered out. Faithful port — the upstream filter list is the union of the app's expected access patterns; ERPify may diverge.
- **First INCLUDED frame short-circuits before EXCLUDED frame** — If a backtrace has both an EventDispatcher frame and a DAMA listener frame, INCLUDED wins because the iteration order processes them sequentially, returning on the first. Faithful port.
- **`query-stats.feature` validation-reject assumes 0 queries** — Auth/audit/security listeners may issue probe queries before validation rejects. Real-world signal worth confirming on first `make php.behat` run; if it fires, that's a regression in the bank validation path, not the context.
- **`requestArgumentIsEqualTo` `return` on first match across connections** — Same family as the universal-vs-existential defer. Faithful port.
