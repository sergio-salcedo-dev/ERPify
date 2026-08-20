/** How long a caller waits for the document to go away before assuming it never will. */
export const NAVIGATION_COMMIT_BUDGET_MS = 10_000;

/**
 * Why a hard navigation left the caller still running.
 *  - `refused`  — `replace()` raised, so nothing was ever scheduled.
 *  - `not-committed` — nothing raised and the document is still here past its budget.
 */
export type HardNavigationFailure = "refused" | "not-committed";

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
 * `url` is NOT validated here, and that is a decision rather than an omission: this is the
 * sink, so a guard would have to rewrite a destination it disliked, and a navigation that
 * silently goes somewhere else is a worse failure than the one it prevents. Callers pass a
 * `Routes.*` constant or a value already through `safeInternalPath` — both of today's do —
 * and that is a review rule, not something a green build proves.
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

  let timer: ReturnType<typeof setTimeout> | undefined;

  const cancel = (): void => {
    if (timer === undefined) return;
    clearTimeout(timer);
    timer = undefined;
    globalThis.removeEventListener("pagehide", cancel);
  };

  timer = setTimeout(() => {
    cancel();
    onFailure("not-committed");
  }, budgetMs);
  globalThis.addEventListener("pagehide", cancel);
}
