---
title: 'Close PR #819 deferred findings: sign-out race, toast dismissal, 401-vs-timeout, backgrounded timer'
type: 'bugfix'
created: '2026-08-21'
status: 'done'
review_loop_iteration: 0
context: []
baseline_commit: '8a4548bf8b9c26eee293cefedd4183b62e00c8cb'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** PR #819's adversarial review deferred 5 findings on the sign-out / session-expiry cycle: `RequireAuth`'s own redirect races the sign-out navigation (the current fix — hoisting the status `<output>` to a sibling — only masks it); the toast viewport unmounts with `SessionExpiryCurtain`, so a stale toast can render on top of it if not cleared; `FetchHttpClient` mis-reports an already-observed 401 as a generic timeout when the body read then aborts; and `hardNavigate`'s 10s commit-budget keeps ticking while the tab is backgrounded.

**Approach:** Implement the 5 already-converged decisions (D1–D5) from the handoff, all in one PR: an `isSigningOut` boolean on `AuthContextValue` gates `RequireAuth`'s redirect effect; the status `<output>` moves back to being a child of `RequireAuth` and a failure is announced via a new `toastNotifier.error(...)` call instead; `<SonnerToaster/>` becomes a true sibling of `<AuthProvider>` and `SessionExpiryCurtain` calls a new `dismissAll()` port method on engage; `FetchHttpClient.request()` preserves an already-observed 401 through a body-read abort instead of throwing a timeout; `hardNavigate` pauses/resumes its commit timer on `visibilitychange`.

## Boundaries & Constraints

**Always:**
- `isSigningOut` stays a plain boolean (+ setter) on `AuthContextValue` — no auth-status enum rewrite.
- `SessionExpiryCurtain`'s remount-on-`expiring` behavior is unchanged (D2 is closed: no-op).
- `FetchHttpClient`'s 401-preservation triggers only when `res.status === HttpStatus.UNAUTHORIZED` was observed BEFORE the abort during that same request's body read — never generalized to any abort with no prior known status.
- `hardNavigate` keeps `pagehide`/`persisted:false` as the sole "committed" signal; only the timer gains pause/resume — no state machine, no change to `not-committed` semantics.
- `toastNotifier` stays typed as the `ToastNotifier` port everywhere it's consumed (never the concrete `SonnerToastNotifier`).

**Never:**
- Don't touch `SessionExpiryCurtain`'s remount logic.
- Don't build a custom assertive-live-region workaround for Sonner's polite-only `aria-live` (verified via Sonner docs: the toaster's root region is always `aria-live="polite"`, no per-toast override) — note it as a known limitation in the PR body.
- Don't add a state machine or cancellation token to `hardNavigate` — a pausable timer only.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| Sign-out, fast success | `isSigningOut=true`, `logout()` clears session before hardNavigate's outcome is known | `RequireAuth`'s redirect effect does not fire while `isSigningOut` is true; document unloads via `hardNavigate` | N/A |
| Sign-out, refused/stalled | `hardNavigate` reports `refused`/`stalled` | `toastNotifier.error(...)` fires, `isSigningOut` clears to `false`, `RequireAuth`'s effect re-runs and redirects to `/login` | N/A |
| Session-expiry engages with a toast up | `SessionExpiryCurtain`'s `expiring` flips true while a toast is visible | `toastNotifier.dismissAll()` fires; `<SonnerToaster/>` stays mounted (sibling of `AuthProvider`), toast is cleared not hidden-by-unmount | N/A |
| 401 observed, then body read aborts | `res.status === 401` read, then `res.text()` aborts on the request budget | Rejects as the 401 `HttpError` (empty body falls back to synthetic `ProblemDetails`), matching the redirect side effect already fired | Never a `REQUEST_TIMEOUT` when a 401 was already observed |
| Abort with no prior status | `fetch()` itself aborts before headers land | Rejects as `REQUEST_TIMEOUT`, unchanged | N/A |
| Tab backgrounded mid-navigation | `hardNavigate` armed, tab hidden then visible again before budget's original 10s | Timer pauses on hidden (no fire), resumes on visible with the REMAINING budget, not a fresh 10s | N/A |

</frozen-after-approval>

## Code Map

- `pwa/src/context/shared/access/infrastructure/ui/AuthProvider.tsx` -- add `isSigningOut` state + `setIsSigningOut` to `AuthContextValue`.
- `pwa/src/context/shared/access/infrastructure/ui/RequireAuth.tsx` -- suppress the redirect effect while `isSigningOut`.
- `pwa/src/app/backoffice/BackOfficeLayoutClient.tsx` -- set/clear `isSigningOut` around the sign-out call; fire `toastNotifier.error(...)` on refused/stalled; move `<output>` back inside `<RequireAuth>`.
- `pwa/src/context/shared/notification/domain/Toast/ToastNotifier.ts` -- add `dismissAll(): void` to the port.
- `pwa/src/context/shared/notification/infrastructure/Toast/SonnerToastNotifier.ts` -- implement `dismissAll()` via `toast.dismiss()`.
- `pwa/src/context/shared/access/infrastructure/ui/SessionExpiryCurtain.tsx` -- call `toastNotifier.dismissAll()` in the existing engage effect; remount logic untouched.
- `pwa/src/app/layout.tsx` -- move `<SonnerToaster/>` to a sibling of `<AuthProvider>`.
- `pwa/src/context/shared/http-client/infrastructure/FetchHttpClient.ts` -- `request()`: preserve an observed 401 through a body-read abort.
- `pwa/src/context/shared/navigation/infrastructure/hardNavigate.ts` -- pausable commit-budget timer via `visibilitychange`.
- Tests (see Tasks): `backOfficeLayoutClient.test.tsx`, `SessionExpiryCurtain.test.tsx`, `SonnerToastNotifier.test.ts`, `FetchHttpClient.test.ts`, `hardNavigate.test.ts`.

## Tasks & Acceptance

**Execution:**
- [x] `AuthProvider.tsx` -- add `isSigningOut: boolean` + `setIsSigningOut: (v: boolean) => void` to `AuthContextValue`, backed by `useState(false)` -- new cross-cutting flag `RequireAuth` reads.
- [x] `RequireAuth.tsx` -- destructure `isSigningOut` from `useSession()`; the redirect `useEffect` returns early (added to guard + deps) while it's `true`.
- [x] `BackOfficeLayoutClient.tsx` -- `setIsSigningOut(true)` at the start of the sign-out branch; in `hardNavigate`'s failure callback, keep `setSignOut(...)`, add `toastNotifier.error(SIGN_OUT_MESSAGE[outcome])` and `setIsSigningOut(false)`; move the `<output data-testid="bo-layout__leaving-status">` JSX to be the first child inside `<RequireAuth>` (drop the now-stale sibling-hoist comment, write a comment stating the current invariant only).
- [x] `ToastNotifier.ts` -- add `dismissAll(): void` to the interface, with a one-line doc comment (dismisses every visible toast).
- [x] `SonnerToastNotifier.ts` -- implement `dismissAll()` as `toast.dismiss()` (no id = dismiss all, per Sonner's API).
- [x] `SessionExpiryCurtain.tsx` -- in the existing `useEffect` (`if (!expiring) return; ...`), call `toastNotifier.dismissAll()` alongside the focus call; import `toastNotifier` from `@/context/shared/notification/infrastructure/Toast`.
- [x] `layout.tsx` -- restructure to `<AuthProvider>{children}</AuthProvider><SonnerToaster/>` as siblings inside `<ThemeProvider>`; update the doc comment above (it currently explains the now-superseded nested placement).
- [x] `FetchHttpClient.ts` -- in `request()`, hoist `res` to an outer-scoped `let res: Response | undefined`; in the `catch` block, when `controller.signal.aborted && res?.status === HttpStatus.UNAUTHORIZED`, return `{ res, raw: "" }` instead of throwing `timeoutError` (skip the timeout telemetry call for this path).
- [x] `hardNavigate.ts` -- track `remainingMs` (starts at `budgetMs`) and the timer's start instant (`performance.now()`); add a `document.visibilitychange` listener: on hidden, clear the timer and subtract elapsed time from `remainingMs`; on visible, re-arm with `remainingMs`. Remove the listener in `disarm()` alongside the existing `pagehide` listener.
- [x] `backOfficeLayoutClient.test.tsx` -- add `isSigningOut`/`setIsSigningOut` to the hoisted `auth` mock (mutable field + a `vi.fn` that mutates it); rewrite "keeps announcing the leaving state even when the guard tears the guarded subtree down" and the two recovery tests ("recovers the menu when the navigation is refused/ignored") to assert the new mechanic (toast fires, `isSigningOut` clears, `<output>` is a child of `RequireAuth`); add a new test that `RequireAuth` does not redirect while `isSigningOut` is true even though `session`/`status` are already unauthenticated.
- [x] `SessionExpiryCurtain.test.tsx` -- mock `@/context/shared/notification/infrastructure/Toast` and assert `dismissAll` is called when `beginSessionExpiry()` engages the curtain.
- [x] `SonnerToastNotifier.test.ts` -- add a case forwarding `dismissAll()` to `toast.dismiss()` with no arguments; update the "exposes the full ToastNotifier surface" assertion to include `dismissAll`.
- [x] `FetchHttpClient.test.ts` -- in the "request budget" describe block, add a case mirroring "gives up on a response whose headers land and whose body never does" but with a 401 status: asserts the rejection is the 401 `HttpError` (not `REQUEST_TIMEOUT`), and that the redirect side effect (`location.replace` to `/login?...`) still fired.
- [x] `hardNavigate.test.ts` -- add cases: timer pauses on `visibilitychange` (hidden) and does not fire during that window even past the original budget; resumes on visible with the remaining budget, not a fresh one.
- [x] Fallout of moving `<output>` inside `<RequireAuth>` (D1.e), not itemized above but required for a green suite: `backOfficeLayoutGroupSignOut.test.tsx` and `backOfficeLayoutSignOutIntent.test.tsx` share the same hoisted `useSession` mock shape and needed `setIsSigningOut: vi.fn()` added (neither exercises the sign-out branch, so this only prevents a future `setIsSigningOut is not a function` trap). The hydrating-state test in `backOfficeLayoutClient.test.tsx` ("renders only the (empty, sr-only) status region...") asserted the `<output>` survives `RequireAuth`'s render gate while hydrating -- no longer true now that it's a child; renamed to "renders nothing at all while the session is still hydrating" and rewritten to assert absence.

**Acceptance Criteria:**
- Given a sign-out whose revoke settles fast, when `RequireAuth` observes `status` flip to unauthenticated while `isSigningOut` is still `true`, then its redirect effect does not fire (no double navigation racing `hardNavigate`'s `HOME` navigation).
- Given a sign-out that is refused or stalls past its commit budget, when `hardNavigate`'s failure callback runs, then a toast fires, `isSigningOut` clears, and `RequireAuth` then redirects to `/login`.
- Given the session-expiry curtain engages while a toast is visible, when its effect runs, then the toast is dismissed and `<SonnerToaster/>` itself is never unmounted.
- Given a request whose 401 status was already read when the body-read then aborts on the timeout budget, when the promise rejects, then it rejects as the 401 error, not `REQUEST_TIMEOUT`.
- Given `hardNavigate` is armed and the tab is backgrounded then foregrounded before the original budget would have elapsed, when the tab returns to the foreground, then the callback only fires after the REMAINING budget, not a fresh one.

## Design Notes

- **D1 ordering:** `isSigningOut` only gates the *redirect effect*, not `RequireAuth`'s render gate (`if (status !== AUTHENTICATED) return null` is untouched) — so the guarded subtree (and the `<output>`) can still unmount before an outcome is known; that's fine because the outcome is now toasted, not announced through that region.
- **D3 call site:** `dismissAll()` is called from `SessionExpiryCurtain`'s existing engage effect (not from `sessionExpiry.ts`'s `beginSessionExpiry()`), per the handoff's Code Map assignment.
- **D4 known Sonner limitation:** confirmed via Sonner's own docs that the toaster's live region is always `aria-live="polite"` (`aria-relevant="additions text"`), with no per-toast override to `assertive` — note this in the PR body rather than working around it. The existing `<output aria-live="assertive">` (kept, relocated) remains the only true assertive channel for the rare case where it's still mounted when a failure is known.
- **D5 clock source:** use `performance.now()` for elapsed-time bookkeeping (monotonic), not `Date.now()` (wall clock, can jump).

## Adversarial Pass (Blind Hunter + Edge Case Hunter)

Run after implementation, against the diff since `baseline_commit`, per root `CLAUDE.md`'s Process section. Both reviewers ran independently, without prior conversation context. 13 raw findings, deduplicated to 3 real, story-caused issues plus minor notes:

- **Patched directly (no design decision needed):** `hardNavigate` never paused when the tab was ALREADY hidden at the moment it started (only a `visibilitychange` transition paused it) — fixed by skipping the initial `arm()` when `document.hidden`, letting the visible-transition branch arm it later. A shared test double (`toastNotifierMock()` in `banks/_mocks.ts`) plus 11 more hand-rolled `toastNotifier` mocks were missing `dismissAll` — added to all 12 (none broke today, but `tsc` can't catch a missing method on an untyped `vi.mock` factory). A misleading comment in `FetchHttpClient.ts` overstated that "the redirect already fired" for the 401-preservation shortcut, which isn't true for the 5 auth-handshake endpoints (`revoke-current` included) — reworded. Added a test asserting the toast still fires reliably when the status region has ALREADY unmounted (the realistic production timing — session usually clears within `SIGN_OUT_BUDGET_MS`, well before a `refused`/`stalled` outcome up to `NAVIGATION_COMMIT_BUDGET_MS` later — that the existing tests, which held `logout()` pending forever, didn't exercise). Documented the `performance.now()`/fake-timer coupling `hardNavigate.test.ts` relies on.
- **Deferred (pre-existing, not caused by this story):** two independent `hardNavigate()` call sites — sign-out's own and the session-expiry bounce's — can race each other; both already existed before D1–D5, which only closed the narrower RequireAuth-vs-sign-out race. Recorded in `deferred-work.md`.
- **Fixed, user-confirmed (real, caused by D3):** a toast raised AFTER `SessionExpiryCurtain` already engaged (not just one already visible at that instant) rendered on top of it, since `dismissAll()` only fires once, on the engage transition. Structurally impossible before this PR (the toaster used to unmount with the curtain). Closed with a stacking guarantee rather than a timing one: the curtain is now `fixed inset-0 z-[2147483647]` — above Sonner's own `--z-index: 999999999` default — so no toast, present or future, can paint over it regardless of when it fires. `dismissAll()` is kept as the proactive clear so nothing lingers once the curtain lifts.
- **Raised, user confirmed no change:** the "Signing out…" wait state can go silent for up to `NAVIGATION_COMMIT_BUDGET_MS` once the status region unmounts (session clears) but before an outcome is known — worse than the pre-D1 sibling placement, which stayed mounted throughout. This is D1.e's converged mechanism from the handoff; kept as specified, documented here as a known limitation for the PR body rather than re-litigated.

## Verification

**Commands:**
- `make pwa.quality` -- expected: exit 0.
- `make pwa.test.unit` -- expected: exit 0, full suite green (no `c=` filter).

**Manual checks (if no CLI):**
- None beyond the above; no UI behavior here needs a live-browser check (session/timer/DOM-structure changes, fully covered by the unit suite).

## Suggested Review Order

**D1 — sign-out no longer races `RequireAuth`'s own redirect**

- The fix in one line: don't redirect while a sign-out already owns the outcome.
  [`RequireAuth.tsx:27`](../../pwa/src/context/shared/access/infrastructure/ui/RequireAuth.tsx#L27)

- The flag itself — a plain boolean + setter on the auth context, no enum rewrite.
  [`AuthProvider.tsx:60`](../../pwa/src/context/shared/access/infrastructure/ui/AuthProvider.tsx#L60)

- Set before the race starts, cleared (+ toast) once `hardNavigate`'s outcome is known.
  [`BackOfficeLayoutClient.tsx:130`](../../pwa/src/app/backoffice/BackOfficeLayoutClient.tsx#L130) · [`:172-175`](../../pwa/src/app/backoffice/BackOfficeLayoutClient.tsx#L172)

- The status `<output>` moves back to a child of `RequireAuth` — it only owes the wait state now.
  [`BackOfficeLayoutClient.tsx:231`](../../pwa/src/app/backoffice/BackOfficeLayoutClient.tsx#L231)

**D3 — toast viewport survives the session-expiry curtain, and can't paint over it**

- `<SonnerToaster/>` becomes a true sibling of `<AuthProvider>` — global infra, never unmounts.
  [`layout.tsx:74-75`](../../pwa/src/app/layout.tsx#L74)

- Two independent defenses: an explicit clear on engage, and a stacking guarantee for anything after.
  [`SessionExpiryCurtain.tsx:50`](../../pwa/src/context/shared/access/infrastructure/ui/SessionExpiryCurtain.tsx#L50) · [`:65`](../../pwa/src/context/shared/access/infrastructure/ui/SessionExpiryCurtain.tsx#L65)

- The new port method the above two call through.
  [`ToastNotifier.ts:25`](../../pwa/src/context/shared/notification/domain/Toast/ToastNotifier.ts#L25) · [`SonnerToastNotifier.ts:29`](../../pwa/src/context/shared/notification/infrastructure/Toast/SonnerToastNotifier.ts#L29)

**D4 — an already-observed 401 survives a body-read abort**

- Preserve the 401 the status line already reported instead of a generic timeout.
  [`FetchHttpClient.ts:191-198`](../../pwa/src/context/shared/http-client/infrastructure/FetchHttpClient.ts#L191)

**D5 — `hardNavigate`'s commit budget pauses while the tab is backgrounded**

- Guards against burning the whole budget hidden if the tab was already backgrounded at start.
  [`hardNavigate.ts:133`](../../pwa/src/context/shared/navigation/infrastructure/hardNavigate.ts#L133)

- Pause/resume core: clear + record elapsed on hidden, re-arm with what's left on visible.
  [`hardNavigate.ts:117-124`](../../pwa/src/context/shared/navigation/infrastructure/hardNavigate.ts#L117)

**Tests and fixture fallout**

- Sign-out race + toast + hydrating-state coverage, rewritten for the new mechanic.
  [`backOfficeLayoutClient.test.tsx`](../../pwa/tests/app/backoffice/backOfficeLayoutClient.test.tsx)

- Curtain z-index/dismissAll and pause/resume/already-hidden timer coverage.
  [`SessionExpiryCurtain.test.tsx`](../../pwa/tests/context/shared/access/SessionExpiryCurtain.test.tsx) · [`hardNavigate.test.ts`](../../pwa/tests/context/shared/navigation/infrastructure/hardNavigate.test.ts)

- 401-vs-timeout preservation test, alongside the existing headers-land-body-never-does case.
  [`FetchHttpClient.test.ts`](../../pwa/tests/context/shared/http-client/infrastructure/FetchHttpClient.test.ts)

- 12 hand-rolled `toastNotifier` test doubles across `banks`/`users` specs, patched with the new method.
  [`banks/_mocks.ts`](../../pwa/tests/app/backoffice/banks/_mocks.ts)
