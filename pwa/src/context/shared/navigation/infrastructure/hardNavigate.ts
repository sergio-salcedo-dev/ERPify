import { safeInternalPath } from "../domain/safeInternalPath";

/** Sentinel `safeInternalPath` returns for a destination this module will not follow. */
const REFUSED_DESTINATION = "\u0000refused";

/** How long a caller waits for the document to go away before assuming it never will. */
export const NAVIGATION_COMMIT_BUDGET_MS = 10_000;

/**
 * Why a hard navigation left the caller still running.
 *  - `refused`  — the destination was refused here, or `replace()` raised. Either way
 *    nothing was ever scheduled.
 *  - `superseded` — another hard navigation already owned the document when this one was
 *    attempted. Distinct from `refused`: this caller did nothing wrong, it lost a race, and a
 *    caller that maps `refused` to a user-visible "try again" would say something false here.
 *  - `not-committed` — nothing raised and the document is still here past its budget.
 */
export type HardNavigationFailure = "refused" | "superseded" | "not-committed";

// The document leaves for at most one destination, ever — a second `replace()` before the
// first commits does not queue, it SUPERSEDES, so racing two callers used to make the winner
// arbitrary (whichever executed last). Held for the life of the claim, not a plain boolean:
// `disarm()` below releases it by identity, so a stale callback from an already-superseded
// call can never release a later, unrelated claim.
let claimedBy: symbol | undefined;

/**
 * Leave the current document for `url`, and tell the caller when the document stayed.
 *
 * A full-document navigation has three outcomes and only ONE of them is observable by
 * catching: `replace()` can be refused (it throws), it can commit (the document is
 * discarded and nothing in it runs again), or it can be **ignored** — a sandboxed
 * navigable drops the navigation silently, raising nothing. Every caller that latches
 * state across the call ("a sign-out is in flight", "the expiry bounce has started") is
 * correct on the first two outcomes and wedged on the third: the latch outlives every
 * reason to hold it, for the life of the document, with no signal at all.
 *
 * `pagehide` is the signal the `catch` cannot be, read the only way a leaving document
 * can read it — by elimination. A navigation that commits never gets to observe its own
 * budget elapsing, so a budget that DOES elapse means the document stayed. The reading is
 * one-sided on purpose: a real-but-slow navigation is reported as `not-committed` too,
 * which is why the budget is generous and why every caller here treats the report as
 * "assume nothing left; make the affordance usable again" rather than as proof of refusal.
 * That is the safe action under both readings — the alternative, believing a navigation
 * that never happened, is the wedge this exists to remove.
 *
 * A destination that is not a root-relative in-app path is REFUSED, not rewritten and not
 * trusted. This is the single sanctioned hard-navigation sink in `src/`, so it is also the
 * one line the navigation linter will never look at again; leaving it unguarded on the
 * strength of "callers pass a constant" is a review rule, and the review rule it leaned on
 * had already failed once (`safeInternalPath` passed `/<TAB>/evil.com`). Refusing is
 * reported through the ordinary `refused` channel rather than thrown, so a caller that
 * latched state still releases it instead of unwinding through an adapter that never
 * promised to throw.
 *
 * `replace()` rather than `assign()`, always: the page being left is authenticated, and an
 * `assign()` leaves it one Back press away, where a bfcache restore puts the previous user's
 * data back on a shared machine.
 *
 * Nothing is handed back to cancel with. The budget is a single self-clearing timer per
 * navigation, and a document performs at most one; a caller that goes away before it fires
 * observes a state update into an unmounted tree, which React discards. Adding a canceller
 * for that would be an API with no caller.
 */
export function hardNavigate(
  url: string,
  onFailure: (failure: HardNavigationFailure) => void,
  budgetMs: number = NAVIGATION_COMMIT_BUDGET_MS,
): void {
  if (safeInternalPath(url, REFUSED_DESTINATION) === REFUSED_DESTINATION) {
    onFailure("refused");
    return;
  }

  if (claimedBy !== undefined) {
    onFailure("superseded");
    return;
  }

  const ownClaim = Symbol();

  try {
    // Leaving an authenticated area / running where no React context reaches a router: this
    // module is the single place either one is spelled, so the disable is stated once rather
    // than copied to each caller.
    // eslint-disable-next-line no-restricted-syntax
    globalThis.location.replace(url);
  } catch {
    onFailure("refused");
    return;
  }

  claimedBy = ownClaim;

  let timer: ReturnType<typeof setTimeout> | undefined;
  let remainingMs = budgetMs;
  let runningSince = 0;

  const disarm = (): void => {
    if (claimedBy === ownClaim) claimedBy = undefined;
    if (timer !== undefined) {
      clearTimeout(timer);
      timer = undefined;
    }
    globalThis.removeEventListener("pagehide", onPageHide);
    document.removeEventListener("visibilitychange", onVisibilityChange);
  };

  const fire = (): void => {
    disarm();
    onFailure("not-committed");
  };

  const arm = (ms: number): void => {
    runningSince = performance.now();
    timer = setTimeout(fire, ms);
  };

  // `persisted` is the half that makes pagehide usable as a commit oracle at all. It fires
  // for two different facts: the document is being DISCARDED (`persisted: false` — this
  // navigation committed), or it is going into the back/forward cache or being frozen
  // (`persisted: true` — iOS Safari does this whenever the app is backgrounded), from which
  // it can come back alive. Treating both as "committed" disarmed the only bound a caller
  // has, permanently, on an ordinary phone interaction: background the tab mid-bounce and
  // the claim it releases is never released, leaving the application blanked with no route
  // away — the exact outcome the curtain's safety argument rests on being impossible.
  const onPageHide = (event: PageTransitionEvent): void => {
    if (event.persisted !== false) return;
    disarm();
  };

  // A backgrounded tab is not evidence either way — some browsers throttle or suspend
  // timers while hidden, which would report a real, still-pending navigation as
  // `not-committed` purely because the tab was in the background for the wait. Pausing the
  // budget while hidden and resuming it with whatever was left, rather than a fresh one,
  // keeps the report about the navigation instead of about how long the tab sat backgrounded.
  const onVisibilityChange = (): void => {
    if (document.hidden) {
      if (timer === undefined) return;
      clearTimeout(timer);
      timer = undefined;
      remainingMs = Math.max(0, remainingMs - (performance.now() - runningSince));
    } else if (timer === undefined) {
      arm(remainingMs);
    }
  };

  // A tab that is ALREADY hidden when the navigation starts (e.g. a background-tab realtime
  // reconnect that then 401s) must not spend any of the budget while unobserved either — arming
  // now and only pausing on the next transition would let the whole budget elapse hidden, which
  // is the exact throttling problem this exists to avoid. `onVisibilityChange`'s visible branch
  // arms it once the tab is actually watched.
  if (!document.hidden) arm(budgetMs);
  globalThis.addEventListener("pagehide", onPageHide);
  document.addEventListener("visibilitychange", onVisibilityChange);
}
