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
 *    attempted, AND that one is now known not to have committed. Distinct from `refused`: this
 *    caller did nothing wrong, it lost a race, and a caller that maps `refused` to a
 *    user-visible "try again" would say something false here.
 *
 *    Reported when the race is DECIDED, not when it is joined, and that timing is the contract
 *    rather than an implementation detail. A loser told synchronously has to release its own
 *    latch immediately — the winner's `replace()` has fired but the document is still here for
 *    however long the commit takes — so every piece of state that existed to cover the
 *    departure (a "Signing out…" region, a curtain suppressing the 401 that started the bounce)
 *    collapses back during the unload window and paints exactly the intermediate the latch
 *    existed to prevent. Deferring it costs nothing when the winner commits (the document goes
 *    away and no callback is owed) and gives the loser a truthful report when it does not.
 *  - `not-committed` — nothing raised and the document is still here past its budget.
 */
export type HardNavigationFailure = "refused" | "superseded" | "not-committed";

type SupersededCaller = (failure: HardNavigationFailure) => void;

/**
 * The in-flight navigation, if there is one.
 *
 * The document leaves for at most one destination, ever — a second `replace()` before the first
 * commits does not queue, it SUPERSEDES, so racing two callers used to make the winner arbitrary
 * (whichever executed last). An object rather than a bare symbol because exclusivity alone was
 * not enough: the losers are owed a report, and a claim that cannot be taken away from a holder
 * the browser has stopped serving turns a paused navigation into a lock on everyone else's.
 */
type NavigationClaim = {
  /**
   * The document has been hidden while this navigation was pending, so it may have been dropped
   * silently — the "third outcome" this module exists to survive — and its budget is paused
   * rather than running. A later caller preempts a claim in that state instead of losing to it:
   * before the sink was exclusive, such a navigation wedged only its own caller's recovery, and
   * making it wedge every other caller for up to a full budget is a regression of exclusivity,
   * not a property of it. Only this state is preemptible; an ordinary concurrent race still
   * resolves first-wins, which is what keeps the winner deterministic.
   */
  preemptible: boolean;
  /** Callers that found the sink held, told once this claim's own outcome is known. */
  readonly superseded: SupersededCaller[];
  /** End it without committing: disarm, report to its own caller, then to its losers. */
  readonly abandon: () => void;
};

// Released by identity — a stale callback from an already-ended call can never release a later,
// unrelated claim.
let claim: NavigationClaim | undefined;

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

  const held = claim;

  // The report is owed later, not now — see `HardNavigationFailure`. Pushing rather than calling
  // is the whole of residuals 1 and 3: the loser keeps whatever it latched until the winner is
  // known to have stayed, so a superseded sign-out cannot let `RequireAuth` redirect on top of a
  // pending bounce, and a superseded bounce cannot collapse its own curtain before React renders it.
  if (held !== undefined && !held.preemptible) {
    held.superseded.push(onFailure);
    return;
  }

  try {
    // Leaving an authenticated area / running where no React context reaches a router: this
    // module is the single place either one is spelled, so the disable is stated once rather
    // than copied to each caller. Scoped to the navigation rule alone — the predecessor was a
    // rule-wide `no-restricted-syntax` disable, which also switched off the maxLength and
    // test-id contract bans on this line.
    // eslint-disable-next-line erpify/hard-navigation
    globalThis.location.replace(url);
  } catch {
    // Nothing was taken from `held`: a call that never reached the browser must not end the claim
    // it was about to preempt, and its losers stay attached to a navigation that is still pending.
    onFailure("refused");
    return;
  }

  let timer: ReturnType<typeof setTimeout> | undefined;
  let remainingMs = budgetMs;
  let runningSince = 0;

  const disarm = (): void => {
    if (claim === ownClaim) claim = undefined;
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
    // Drained rather than iterated: this claim is over, and a loser is owed exactly one report.
    for (const loser of ownClaim.superseded.splice(0)) loser("superseded");
  };

  // A claim that starts on a hidden document is preemptible from the outset — that is the
  // scenario the sink turned into a lock: a bounce that begins while the tab is backgrounded, a
  // browser that ignores it, and a user who comes back and asks to sign out.
  const ownClaim: NavigationClaim = {
    preemptible: document.hidden,
    // The preempted claim's losers move here rather than being told now. They are waiting on
    // "no hard navigation is pending", and one is — this one.
    superseded: held?.superseded.splice(0) ?? [],
    abandon: () => fire(),
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
      ownClaim.preemptible = true;
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
  claim = ownClaim;

  if (!document.hidden) arm(budgetMs);
  globalThis.addEventListener("pagehide", onPageHide);
  document.addEventListener("visibilitychange", onVisibilityChange);

  // LAST, and the ordering is the whole of it. `abandon()` runs the preempted caller's own
  // callback, which is the only foreign code this function executes — and it executes it with the
  // sink already claimed. Called before the lines above, a callback that throws would propagate
  // out of here leaving this claim installed with no timer and no listeners: the sink held for
  // the life of the document with nothing left to release it, which is the exact wedge this
  // module exists to remove. After them, the same throw leaves a fully armed claim that still
  // reports and still releases itself. It also has to come after `claim = ownClaim`, so a caller
  // that navigates again from inside that callback finds this claim live rather than an empty
  // sink it can claim twice. Its own report is `not-committed`, and it is true — the document is
  // demonstrably still here.
  held?.abandon();
}
