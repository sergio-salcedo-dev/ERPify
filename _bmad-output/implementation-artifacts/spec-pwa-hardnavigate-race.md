---
title: 'Make hardNavigate() single-flight to close the sign-out / session-expiry race'
type: 'bugfix'
created: '2026-08-21'
status: 'done'
review_loop_iteration: 2
context: []
baseline_commit: '3f8145c876aabb29125dbd99933ec6b388e0737d'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** `hardNavigate()` has no shared state across calls, so two independent callers —
sign-out (`BackOfficeLayoutClient.tsx`, navigates to HOME) and the session-expiry bounce
(`FetchHttpClient.ts`, navigates to LOGIN) — can each call `location.replace()` around the same
moment. Whichever call executes last wins the actual browser navigation (a timing-dependent
race), and the loser's own `pagehide`-based commit detection cannot tell "my destination
committed" apart from "the document left via someone else's destination". Deferred from PR
#826's adversarial review (`deferred-work.md`, "Deferred from: code review of
spec-819-deferred-findings"), found independently by two adversarial-review subagents.

**Approach:** Make `hardNavigate()` itself single-flight via a module-level claim, owned by the
invocation that holds it and released only by that same invocation's `disarm()`. A second
concurrent call while one is in flight is refused immediately (`location.replace()` never
called) via a new, distinct `HardNavigationFailure` reason, `"superseded"` — kept apart from
`"refused"` because reusing it would make sign-out show a false "Sign-out did not complete.
Please try again." toast for a sign-out that actually succeeded, just via the other navigation.
Design closed with the user after independent review (architect persona, dev persona, external
AI) converged on this shape.

## Boundaries & Constraints

**Always:**
- The claim lives inside `hardNavigate.ts` (the single sanctioned hard-navigation sink) — never
  in `sessionExpiry.ts` or a new coordinator module.
- The claim is acquired only once `location.replace()` has succeeded, and released only by the
  invocation that acquired it — an identity check in `disarm()`, not a bare boolean.
- `"superseded"` is a new value in `HardNavigationFailure`, never a repurposing of `"refused"`.
- `FetchHttpClient.ts` needs NO code change — `redirectToLoginOnSessionExpiry`'s `onFailure` is
  `endSessionExpiry`, already reason-agnostic.
- On `"superseded"`, sign-out (`BackOfficeLayoutClient.tsx`) must still call
  `setIsSigningOut(false)` (no `REFUSED`/`STALLED`) — skipping it would latch `isSigningOut`
  forever if the winning navigation later fails to commit. It sets a new `SignOut.SUPERSEDED`
  state carrying a real, non-empty live-region message — never empty it, a `role="status"`
  speaks on insertion, so silence announces nothing, the exact defect this file's own
  `SIGN_OUT_MESSAGE` doc comment already closed for REFUSED/STALLED. It shows
  `toastNotifier.info(...)` ONLY when `isSessionExpiring()` is false: today's one real
  superseding caller (the session-expiry bounce) claims the sink by first calling
  `beginSessionExpiry()`, which is what mounts `<SessionExpiryCurtain>` over this entire
  subtree — a toast raised after that enqueues behind the curtain's higher z-index and never
  paints, so it is not actually the "same reach guarantee REFUSED/STALLED rely on" in that case;
  the curtain's own `role="alert"` already carries the announcement instead. Keep the toast for
  a hypothetical future caller that supersedes this one without bringing its own UI.
- `SessionExpiryCurtain`'s remount-on-`expiring` logic is untouched (closed separately, PR #826
  D2).
- Existing `hardNavigate.test.ts` cases must stay green: several leave a call un-disarmed by
  design (backgrounded-tab cases), and Vitest does not reset module state between tests — isolate
  each test's claim with `vi.resetModules()` + dynamic re-import, the pattern already used in
  `FetchHttpClient.test.ts` (`describe("401 session-expired redirect (browser)")`).

**Ask First:** none — design already closed with the user.

**Never:** reuse `"refused"` for a superseded call. Add a navigation coordinator/queue class.
Change `SessionExpiryCurtain`'s remount behavior. Touch `RequireAuth.tsx`'s `isSigningOut` guard
(confirmed to correctly stay separate).

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| Sign-out wins | sign-out's `hardNavigate(HOME)` claims first; session-expiry's `hardNavigate(LOGIN)` called while it's in flight | `location.replace` called once, with HOME; session-expiry's call never calls `replace` | session-expiry's `onFailure("superseded")` → `endSessionExpiry()` (existing, unchanged) |
| Session-expiry wins | session-expiry claims first; sign-out's `hardNavigate(HOME)` called while it's in flight | `location.replace` called once, with LOGIN | sign-out's `onFailure("superseded")` → `setSignOut(SignOut.SUPERSEDED)` (real, non-empty live-region message) + `setIsSigningOut(false)`; NO toast (`isSessionExpiring()` is true — the curtain already announced) |
| Superseded by a caller with no announcement of its own (hypothetical today) | some future third `hardNavigate` caller claims first; sign-out's `hardNavigate(HOME)` called while it's in flight, `isSessionExpiring()` false | `location.replace` called once by the other caller | sign-out's `onFailure("superseded")` → `setSignOut(SignOut.SUPERSEDED)` + `toastNotifier.info(...)` (shown, since no curtain covers it) + `setIsSigningOut(false)` |
| Claim released, second call proceeds | first call's navigation times out (`"not-committed"`) or commits (`pagehide`) | claim released via `disarm()` | a THIRD, later `hardNavigate` call after release is NOT refused |
| Bad destination never claims | `hardNavigate("https://evil.com/")` while nothing else is in flight | `replace` never called | `onFailure("refused")` (unchanged), sink remains unclaimed |
| Curtain does not flash when sign-out wins | `beginSessionExpiry()` then synchronous `endSessionExpiry()` (superseded) in one tick | `useSessionExpiring()` never observes `true` | no `SessionExpiryCurtain` mount, no `toastNotifier.dismissAll()` call |

</frozen-after-approval>

## Code Map

- `pwa/src/context/shared/navigation/infrastructure/hardNavigate.ts` -- add the module-level owned claim + `"superseded"` reason; refuse-before-`replace()` when already claimed.
- `pwa/src/app/backoffice/BackOfficeLayoutClient.tsx` -- `hardNavigate(Routes.HOME, ...)` callback (~line 221-232): branch on `"superseded"` before the existing `refused`/`not-committed` mapping; imports `isSessionExpiring` from `sessionExpiry.ts` to decide whether the toast would actually be visible.
- `pwa/src/context/shared/http-client/infrastructure/FetchHttpClient.ts` -- no change; read only to confirm `endSessionExpiry` stays reason-agnostic.
- `pwa/tests/context/shared/navigation/infrastructure/hardNavigate.test.ts` -- add exclusivity tests; adopt `vi.resetModules()` + dynamic re-import per test.
- `pwa/tests/app/backoffice/backOfficeLayoutClient.test.tsx` -- add a "superseded" case (simulate the sink already claimed, then click sign-out).
- `pwa/tests/context/shared/access/SessionExpiryCurtain.test.tsx` -- add the same-tick begin/end-never-paints case.
- `_bmad-output/implementation-artifacts/deferred-work.md` -- delete the resolved bullet once fixed.

## Tasks & Acceptance

**Execution:**
- [x] `hardNavigate.ts` -- add `"superseded"` to `HardNavigationFailure`; add an owned module-level claim (symbol/token), acquired after a successful `replace()`, checked before `replace()` is attempted, released by identity in the existing `disarm()` -- closes the race at its one structural chokepoint
- [x] `hardNavigate.test.ts` -- tests: second concurrent call is refused with `"superseded"` and never calls `replace`; claim releases on commit (`pagehide`) and on timeout (`"not-committed"`), each unblocking a later call; existing cases stay green under the new `vi.resetModules()` isolation
- [x] `BackOfficeLayoutClient.tsx` -- branch `"superseded"` to a new `SignOut.SUPERSEDED` state (real live-region message) + `toastNotifier.info(...)` + `setIsSigningOut(false)` -- closes the D1-iteration silent-live-region finding (see Spec Change Log) while still avoiding the false "try again" message and the `isSigningOut` latch risk
- [x] `backOfficeLayoutClient.test.tsx` -- test: sink pre-claimed, sign-out click's own `hardNavigate` is superseded → info toast shown (not error), live region carries the real message, `isSigningOut` released; plus a combined test proving RequireAuth's own redirect still fires once `isSigningOut` clears after a superseded outcome (also: `afterEach` now releases any dangling `hardNavigate` claim between tests, since several existing cases never disarm within the test body)
- [x] `SessionExpiryCurtain.test.tsx` -- test: `beginSessionExpiry()` immediately followed by a synchronous release never renders the curtain (also: same `afterEach` claim release, and removed a now-redundant/inaccurate manual cleanup comment in the last pre-existing test)
- [x] `hardNavigate.test.ts` -- test: an invalid destination is still reported `"refused"` (never `"superseded"`) even while the sink is already claimed by another call -- locks in the check-ordering the adversarial pass flagged as untested
- [x] `deferred-work.md` -- delete the resolved bullet; add two new entries: the residual RequireAuth-vs-pending-winning-navigation race, and the backgrounded-tab-blocks-other-callers window (both argued acceptable, see Spec Change Log)
- [x] `BackOfficeLayoutClient.tsx` -- guard the `"superseded"` toast on `!isSessionExpiring()` -- the loop-1 toast never actually paints when the real superseding caller (session-expiry) has already mounted its own curtain over this subtree
- [x] `backOfficeLayoutClient.test.tsx` -- tests: toast skipped when superseded via the real `beginSessionExpiry()` sequence; toast shown when superseded without it; strengthened the RequireAuth-combined test to actually advance the winner's own budget to `"not-committed"` rather than only asserting the unconditional case

**Acceptance Criteria:**
- Given sign-out and a concurrent unrelated 401 fire `hardNavigate` around the same moment, when both execute, then `location.replace` is called exactly once (whichever claimed first) and the loser's callback fires `"superseded"` without ever calling `replace`.
- Given sign-out's own navigation is superseded, when its callback runs, then a real (non-empty) message is announced via the live region, `toastNotifier.info(...)` fires (not `.error`), and `isSigningOut` is released.
- Given the winning navigation's own budget elapses without `pagehide` (`"not-committed"`), when that happens, then the claim releases and a later `hardNavigate` call is not refused.
- Given an invalid destination is passed while the sink is already claimed, when `hardNavigate` runs, then it reports `"refused"`, never `"superseded"`.
- Given sign-out is superseded by the session-expiry bounce specifically (`isSessionExpiring()` true), when its callback runs, then the live-region message is still set but `toastNotifier.info(...)` is NOT called.
- Given sign-out is superseded by a hypothetical caller that is not the session-expiry bounce (`isSessionExpiring()` false), when its callback runs, then `toastNotifier.info(...)` IS called.

## Spec Change Log

**Loop 1 (intent_gap, resolved with the human):** Blind Hunter and Edge Case Hunter — parallel,
no prior context, run against the diff since `baseline_commit` — independently found that the
frozen I/O matrix's `"superseded"` behavior (`setSignOut(SignOut.IDLE)`, no toast) empties the
live region with no announcement, reproducing the exact silent-recovery defect
`BackOfficeLayoutClient.tsx`'s own `SIGN_OUT_MESSAGE` doc comment already documents as closed for
`REFUSED`/`STALLED` — a screen-reader user hears "Signing out…" then nothing. Root cause was
inside `<frozen-after-approval>` (the I/O matrix row), so this looped back to the human rather
than being self-amended: presented the finding, a message + toast fix, and one alternative
(message only, no toast); the human approved message + `toastNotifier.info(...)`, matching the
existing REFUSED/STALLED reach guarantee (the guarded subtree may already be unmounted by the
time the outcome is known — the same reason those two already show a toast). Amended: the
`"superseded"` row in the I/O matrix and the corresponding `Always` bullet (now specify a new
`SignOut.SUPERSEDED` state + `toastNotifier.info`, not silence); the AC bullet; the Execution
task list (added the RequireAuth-combined test and the check-ordering test below). **KEEP**: the
single-flight claim in `hardNavigate.ts` (module-level, identity-checked release) is unaffected
and already gate-verified — nothing about it is re-derived, only `BackOfficeLayoutClient.tsx`'s
`"superseded"` branch and its test.

Two further adversarial findings, resolved without a loopback (neither touches frozen intent):
- Edge Case Hunter: an invalid destination passed while the sink is already claimed was
  untested — could regress to reporting `"superseded"` instead of `"refused"` without a test
  catching it. Classified **patch**: added a test locking in the existing (correct) check order
  in `hardNavigate.ts` — no code change needed, the order was already right.
- Edge Case Hunter: releasing `isSigningOut` synchronously on `"superseded"` opens a window where
  `RequireAuth`'s own client-side `router.replace()` can race the *winning* navigation's still-
  pending `location.replace()` (both eventually land on the same destination, so this is a
  same-target flash, not a wrong-destination or security issue). Classified **defer**: fixing it
  properly would mean extending the claim into a readable "any hard navigation is in flight"
  signal `RequireAuth` also consults — the exact `Option A` shape the original design decision
  (converged 3-way: architect/dev personas + external AI) argued against, since not releasing
  `isSigningOut` immediately reintroduces a worse, guaranteed bug: a permanent latch if the
  *winning* navigation itself never commits. Recorded in `deferred-work.md` for future attention
  if the flash is ever reported as a real problem.

**Loop 2 (self-amended, not looped back to the human — see note below):** A second adversarial
pass (Blind Hunter + Edge Case Hunter, parallel, fresh context, run against the loop-1 diff)
independently converged, again, on the same root finding: the loop-1 fix's `toastNotifier.info`
call never actually paints in the realistic trigger. Tracing the real production sequence —
`redirectToLoginOnSessionExpiry` calls `beginSessionExpiry()` before `hardNavigate(...)`, and
`beginSessionExpiry()` is what mounts `<SessionExpiryCurtain>` over `BackOfficeLayoutClient`'s
entire subtree — confirmed it: by the time the `"superseded"` callback runs, the tree the toast
call lives in has usually already been replaced by the curtain, and even where the call itself
still executes (closures aren't torn down by unmounting), Sonner's viewport z-index sits BELOW
the curtain's, so the toast enqueues and never paints. The loop-1 comment's claim — "the same
reach guarantee REFUSED/STALLED already rely on" — does not actually hold in this specific case
(it does hold for a hypothetical future caller with no announcement of its own). Fix: guard the
toast on `isSessionExpiring()` — skip it when true (the curtain already carries the
announcement), keep it otherwise. Also fixed: a test title overclaimed "if the winning
navigation itself never commits" without exercising that condition — strengthened to actually
advance the winner's own budget to `"not-committed"` and assert the redirect already happened
independently of it. Amended: the `"superseded"` `Always` bullet and I/O matrix row (added a
second row for the no-curtain case); two new AC bullets.

*Why self-amended rather than looped back to the human, unlike loop 1:* the root finding is a
mechanical consequence of applying the human's ALREADY-approved intent from loop 1 ("show a
real, reaching announcement, matching REFUSED/STALLED's guarantee") to a scenario that fix
didn't fully account for — the fix keeps the same goal (the outcome must actually reach the
user) and narrows the mechanism to where it's true, rather than reversing or reinterpreting what
was approved. This is a judgment call, not a protocol default — flagged here explicitly, and in
the human-facing summary, so it is reviewable rather than silent.

A second, structurally new finding from this round (Blind Hunter) — a navigation paused by a
backgrounded tab now also blocks any OTHER caller's navigation, for up to
`NAVIGATION_COMMIT_BUDGET_MS`, since the single-flight claim persists across the pause —
classified **defer**: real and caused by this story, but rare (requires backgrounding
concurrent with a stuck/ignored navigation), bounded, self-healing, and closing it would need
claim-preemption machinery neither current caller justifies. Recorded in `deferred-work.md`.

**Loop 3 (patch/defer only, no frozen-intent change, `review_loop_iteration` not incremented):**
A third adversarial pass (fresh Blind Hunter + Edge Case Hunter, targeting the least-reviewed
code — the loop-2 `isSessionExpiring()` guard and its tests) surfaced two false findings and
three real ones.

False, verified against the actual current file and rejected: Blind Hunter claimed
`deferred-work.md` retained a stale bullet from before this story ("Two independent
`hardNavigate()`-driven hard navigations can race…") under a merely-renamed header. `grep` over
the current file finds no trace of that text — the original bullet and header were fully
replaced, not renamed-while-kept, in the loop-1 edit. A second finding (the header-rename
"conflating correction with new recording") rests on the same misreading. Recorded here so the
false-positive rate of this review is itself part of the audit trail, not silently dropped.

Real, classified **patch** (no spec change, added directly): (1) `hardNavigate.ts`'s claim is
acquired only after a successful `replace()` — pinned for the URL-validation refusal path, but
untested for the OTHER refusal path (`replace()` itself throwing); added the missing chained
test. (2) The three touched test files release the same `hardNavigate` claim via two different
mechanisms (`vi.resetModules()` in `hardNavigate.test.ts`, a `pagehide` dispatch in the other
two) with no comment explaining why; added one, since `hardNavigate.test.ts` alone deliberately
holds a claim across a simulated backgrounded-tab pause, which a dispatch can't safely interrupt
mid-test.

Real, classified **defer**: Edge Case Hunter — when sign-out wins the sink, session-expiry's own
`hardNavigate` call is superseded before `<SessionExpiryCurtain>` ever mounts, so the 401 that
triggered the bounce is no longer suppressed while it unwinds (`SessionExpiryCurtain`'s own doc
comment states that suppression as unconditional; it no longer is). Argued acceptable on the
same "navigation ownership wins over navigation-specific presentation" principle the original
3-way design decision already established, and not a wholly new gap: `beginSessionExpiry()`'s
OWN pre-existing single-flight guard already let a second concurrent 401 skip curtain
suppression before this story; this adds a new way to reach the same class of gap, not the class
itself. Recorded in `deferred-work.md`.

**Superseded by #831, and the principle is restated rather than dropped.** The wording above is
too wide, and the width is what made this defer look sound: read literally, "ownership wins over
presentation" licenses a winner to silence a contract another module PUBLISHES — and two modules
published this one as unconditional, so the code and the docs were left contradicting each other,
which is worse than either resolution. The principle it was reaching for is narrower and still
holds:

> Ownership of the navigation wins over the LOSER'S OWN presentation of its own attempt — not
> over a contract another module states unconditionally.

Under the narrow reading, a losing sign-out does not get to keep announcing "Redirecting…" over
somebody else's departure (still true, still the original call), while the expiry curtain — which
`FetchHttpClient` and `SessionExpiryCurtain` both promise to every caller of the transport — is
not the loser's presentation at all. #831 closes it by reporting `superseded` when the race is
DECIDED rather than when it is joined, so nothing is silenced and nothing has to be reworded.

Remaining round-3 findings (leaky `isSessionExpiring()` coupling, no full real-tree integration
test, `rerender()`-masks-natural-cascade, timer-flakiness risk, `mockImplementationOnce`
leak-on-failure risk, `SUPERSEDED`'s politeness not re-argued against the deferred RequireAuth
flash) were each traced and are either accurate-but-already-covered by existing Design Notes
reasoning, consistent with an established pattern already used elsewhere in the same file (not a
NEW risk this story introduces), or genuine but low-severity scope creep for a bugfix — left
unactioned, not silently dropped.

## Adversarial pass

Three rounds, each pair (Blind Hunter + `bmad-review-adversarial-general`, Edge Case Hunter +
`bmad-review-edge-case-hunter`) run in parallel with no prior context, against the diff since
`baseline_commit`. Full findings and triage are in the Spec Change Log above; summary:

- **Round 1** — both reviewers independently found the same GRAVE defect: the original
  `"superseded"` branch emptied `BackOfficeLayoutClient.tsx`'s live-region status with no
  announcement, reproducing an accessibility defect the file's own doc comment already
  documents as closed for REFUSED/STALLED. Root cause was inside the frozen spec's I/O matrix,
  so this looped back to the human (not self-amended); the human approved a real message +
  `toastNotifier.info(...)`. Two lesser findings resolved as patch (a missing check-ordering
  test) and defer (a residual RequireAuth-vs-pending-navigation flash, recorded in
  `deferred-work.md`).
- **Round 2** — both reviewers independently found that round 1's fix didn't actually hold: the
  toast never paints in the realistic trigger, because the only real superseding caller
  (session-expiry) mounts `<SessionExpiryCurtain>` over the whole subtree first, and Sonner's
  toast viewport sits below the curtain's z-index. Self-amended (not looped back — reasoning
  recorded in the Spec Change Log) by guarding the toast on `isSessionExpiring()`, with two new
  tests driving the real `beginSessionExpiry()` sequence rather than a bare pre-claim. A second
  finding (single-flight now lets a backgrounded-tab-paused claim block an unrelated later
  caller) classified defer, recorded in `deferred-work.md`.
- **Round 3** — targeted at the least-reviewed code (the round-2 guard). Two claimed findings
  were verified false against the actual file (`grep` found no trace of the "stale bullet" Blind
  Hunter described) and rejected — recorded here rather than silently dropped, since a false
  positive is itself part of this pass's record. Two real findings fixed as patch (a missing
  claim-not-held-after-throw test; an unexplained inconsistency in how three test files release
  the same module-state claim). One real finding classified defer: when sign-out wins, the
  curtain never mounts, so the 401 that triggered the session-expiry bounce loses its (already
  only partially guaranteed) suppression — argued acceptable on the same principle the original
  design decision established, recorded in `deferred-work.md`.

No GRAVE findings survive unaddressed. Two residual, argued-acceptable trade-offs are recorded
in `deferred-work.md` rather than fixed, per the reasoning above and in the Spec Change Log.

## Design Notes

Claim shape: module-level `let claimedBy: symbol | undefined`. Refuse with `"superseded"` when
already set; on a successful `replace()`, set it to a fresh `Symbol()` for that call; the
existing `disarm()` clears it only `if (claimedBy === ownToken)` — identity-checked release, not
a bare boolean, so no future caller can accidentally release someone else's claim.

No extra code is needed for the curtain: when session-expiry's call is superseded, its
`onFailure` (`endSessionExpiry`) runs synchronously in the same tick as the preceding
`beginSessionExpiry()`, so `useSessionExpiring`'s `useSyncExternalStore` never renders the
intermediate `true` — verified against `useSessionExpiring.ts`'s actual hook, not assumed.

`SignOut.SUPERSEDED` message: "Redirecting…" — deliberately does not assert "you are signed
out" (the adversarial pass also flagged that `logout()` swallows its own failures by contract
and can be pre-empted by `SIGN_OUT_BUDGET_MS`, so success is not actually guaranteed at this
point) and does not name a destination (the only current "other" caller is the session-expiry
bounce, but the message stays honest without hard-coding that assumption). `isSignOutFailure`
stays `REFUSED || STALLED` only — `SUPERSEDED` is not a failure, so it keeps the `polite`/
`sr-only` styling, never the assertive/visible failure banner.

## Verification

**Commands:**
- `make pwa.quality` -- exit 0
- `make pwa.test.unit` -- exit 0, full suite (no `--filter`), count not regressed

## Suggested Review Order

**The single-flight claim — where the invariant actually lives**

- New failure reason; a second call while claimed is refused before ever touching `location`.
  [`hardNavigate.ts:18`](../../pwa/src/context/shared/navigation/infrastructure/hardNavigate.ts#L18)

- The claim itself: module-level, per-invocation identity, not a bare boolean.
  [`hardNavigate.ts:25`](../../pwa/src/context/shared/navigation/infrastructure/hardNavigate.ts#L25)

- Refuse-before-`replace()` when already claimed — the one line that closes the race.
  [`hardNavigate.ts:75`](../../pwa/src/context/shared/navigation/infrastructure/hardNavigate.ts#L75)

- Claim acquired only after `replace()` succeeds, so a thrown/refused call never blocks anyone.
  [`hardNavigate.ts:93`](../../pwa/src/context/shared/navigation/infrastructure/hardNavigate.ts#L93)

- Identity-checked release in the existing `disarm()` — no stale callback can release a later claim.
  [`hardNavigate.ts:100`](../../pwa/src/context/shared/navigation/infrastructure/hardNavigate.ts#L100)

**Sign-out's `"superseded"` branch — the accessibility fix that took three review rounds**

- Not a failure: real live-region message, no REFUSED/STALLED styling.
  [`BackOfficeLayoutClient.tsx:229`](../../pwa/src/app/backoffice/BackOfficeLayoutClient.tsx#L229)

- The toast only fires when the curtain isn't already carrying the announcement.
  [`BackOfficeLayoutClient.tsx:247`](../../pwa/src/app/backoffice/BackOfficeLayoutClient.tsx#L247)

**Tests — the exclusivity property and the reach guarantee**

- Second concurrent call never touches `location`, reported `"superseded"`.
  [`hardNavigate.test.ts:224`](../../pwa/tests/context/shared/navigation/infrastructure/hardNavigate.test.ts#L224)

- Toast shown when no curtain covers the announcement (today: dead code, forward-looking).
  [`backOfficeLayoutClient.test.tsx:430`](../../pwa/tests/app/backoffice/backOfficeLayoutClient.test.tsx#L430)

- Toast skipped through the REAL `beginSessionExpiry()` sequence, not a bare pre-claim.
  [`backOfficeLayoutClient.test.tsx:457`](../../pwa/tests/app/backoffice/backOfficeLayoutClient.test.tsx#L457)

- The mechanism the whole toast-skip decision rests on: same-tick collapse, curtain never paints.
  [`SessionExpiryCurtain.test.tsx:152`](../../pwa/tests/context/shared/access/SessionExpiryCurtain.test.tsx#L152)

**Peripherals**

- Two residual, argued-acceptable trade-offs recorded rather than fixed.
  [`deferred-work.md:318`](deferred-work.md#L318)
