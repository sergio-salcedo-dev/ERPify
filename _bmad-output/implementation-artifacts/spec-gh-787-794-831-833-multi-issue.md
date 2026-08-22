---
title: "Four issues in one PR: hardNavigate residuals, the hard-navigation rule, the gate home rename, the log-sink gates"
type: "multi-issue"
created: "2026-08-22"
status: "in-review"
context: ["#831", "#787", "#794", "#833"]
baseline_commit: "868c29aef0f2b8e6b95bbc0e4d3a54fb4b2b52e5"
---

## Intent

One branch, four issues the user selected, three commits:

- **#831** — the three residuals `#830` accepted rather than fixed at the `hardNavigate()`
  single-flight boundary.
- **#787** — the hard-navigation gate becomes a real ESLint rule with a scope manager, replacing
  two `no-restricted-syntax` selectors keyed on an enumerated receiver name.
- **#794** — `api/tests/Unit/Shared/Architecture/` → `api/tests/Unit/Gate/`, in its own commit
  with nothing else riding along, as the issue requires.
- **#833** — the two unenumerated override shapes in the log-sink gates (D1, D2).

## Verification

- **PWA — executed.** Full Vitest suite `245 files / 1523 tests` green, plus `eslint .`,
  `prettier --check .`, `tsc --noEmit`, `depcruise src` (507 modules, 1974 dependencies, no
  violations). `hardNavigate.test.ts` is at 26 tests; `hardNavigationGate.test.ts` at 51.
- **API — NOT executed, and this is the branch's largest gap.** The session container has no
  Docker daemon, and the agent proxy answers `403` to the GitHub tarball routes Composer needs
  (`api.github.com/repos/**/zipball`, `codeload.github.com`), so all 185 vendor packages are
  empty and neither PHPUnit nor PHPStan can run. What was done instead: `php -l` over every
  changed and renamed file; a structural script asserting PSR-4 namespace↔path agreement across
  all 861 test files, that all 70 `.artifact-gate-placement` subjects exist, and that class name
  matches file name for every gate; and an exhaustive `git grep` proving zero surviving
  references to the old path or namespace. **`make php.quality` and `make php.unit` have not been
  run and must be, locally, before merge.**

## Adversarial pass

Performed 2026-08-22, after the three commits were on the branch and **before the PR was opened**
— re-reading the diff hostilely rather than re-reading the intent. Two findings, one of them a
defect introduced by this branch and fixed here.

**A1 — GRAVE, fixed. Preemption ran foreign code with the sink already claimed, before the claim
was armed.** In `hardNavigate.ts`, `held?.abandon()` sat immediately after `claim = ownClaim` and
*before* `arm(budgetMs)` and the two `addEventListener` calls. `abandon()` invokes the preempted
caller's own `onFailure` — the only foreign code this function executes — so a callback that
threw would propagate out of `hardNavigate()` leaving the new claim installed with no timer and
no listeners: the sink held for the life of the document with nothing left to release it. That is
precisely the wedge the module exists to remove, reintroduced through the new preemption path.
Unreachable with today's two callers (both swallow their own failures), structural regardless.
Fixed by moving `held?.abandon()` to the last statement of the function, after the budget is
armed and both listeners are registered; the `claim = ownClaim` ordering it also depends on is
unchanged and now stated at the call. Pinned by `hardNavigate.test.ts` → "arms the new claim
before running the preempted caller's callback, so a throw cannot wedge the sink", and the pin is
live rather than decorative: measured by mutation, restoring the old order reds exactly that row
(`1 failed | 25 passed`) and the fix returns `26 passed`.

**A2 — accepted, recorded. A throwing own-caller callback still strands the losers.** `fire()`
calls `onFailure("not-committed")` for the claim's own caller and only then drains
`superseded`. A throw in the first leaves the queued losers with no report at all — they wait for
a callback that never comes. New in kind, because before this branch there were no losers to
strand. Not fixed: the module has no error-handling contract for caller callbacks today, and
inventing one (a `try`/`catch` around foreign code, swallowing errors this module cannot
interpret) is a design decision larger than the residual, which needs a caller whose recovery
throws — neither of the two has one.

**A3 — accepted, recorded. `preemptible` is sticky for the life of the claim.** Once the document
has been hidden while a navigation is pending, that claim stays preemptible even after the tab is
visible again and its budget resumes. This is deliberate — the issue's own scenario is precisely
"the user comes back and clicks Sign out", so clearing the flag on re-show would leave #831's
item 2 open — but the cost is real and belongs in the record: for the remainder of that claim's
life, an ordinary concurrent race resolves last-wins rather than first-wins, which is a partial
give-back of the determinism #830 bought.

**A4 — accepted, recorded. A deferred `superseded` now counts against the expiry-bounce cap.**
`FetchHttpClient`'s callback ignores the failure kind and increments `bouncesGivenUpOn` on any
report, so a bounce that lost the sink and never called `replace()` at all now spends one of the
two `MAX_EXPIRY_BOUNCES`. Left unchanged: the deferred report arrives only when the document is
demonstrably still here, which is the condition the counter is about, and the caller's own
budget logic was out of scope for this branch.

**Where this pass is weak, stated rather than implied.** It was performed by the authoring
session, so it does **not** meet the bar CLAUDE.md sets — "a hostile read by someone other than
the author (a fresh context, a different model, or a human)". It is recorded as what it is. The
API half compounds it: no PHP gate was executed, so the #833 and #794 commits carry no runtime
evidence at all, only structural evidence. A fresh-context or human pass over the API commits is
the outstanding obligation.

## Design Notes

**#831 is one contract change plus one preemption rule, not three fixes.** Residuals 1 and 3 are
the same defect seen from two callers: `superseded` was reported when the race was *joined*, so
the loser had to release its latch while the winner's `replace()` was still committing. Reporting
it when the race is *decided* closes both — and it is not a latch, because the report arrives on
the winner's own budget whichever way that goes, and never at all when the winner commits (the
document is going away and no callback is owed). Residual 2 is the separate one: a claim the
document has been hidden for becomes preemptible.

**#831 item 3 reopens a decision #830 converged on.** #830 established "navigation ownership wins
over navigation-specific presentation" and accepted that a superseded expiry bounce would not
paint its curtain. This branch inverts that: `SessionExpiryCurtain` now paints. The argument is
that the component's own doc comment and `FetchHttpClient`'s both state the suppression as
unconditional, and a contract that holds except when another caller happens to hold the sink is a
coincidence rather than a contract. The test that pinned the old behaviour
(`"never paints when superseded — the claim begins and ends in the same synchronous tick"`) was
rewritten rather than deleted, so the reversal is visible in the diff. **This is a user-owned
design call and is flagged as such in the PR body.**

**#787 closes the blind spots and the false positives with one fact.** A selector matches syntax
and has no scope manager, so its receiver could only be an enumerated *name* — ambiguous in both
directions at once. It missed `const l = location; l.assign(u)` and it reported
`const { location } = warehouse; location.replace(/ /g, "-")`. Four such reports were recorded in
the gate test as an accepted *cost* of the enumeration; a scope lookup makes them ordinary
negatives. The disable at the one sanctioned call site also stops being rule-wide.

**#833's D2 is wider than the issue described, and the issue's mechanism was wrong.** The issue
said `_defaults: { autoconfigure: false }` would drop the tag. It would not on its own: the tag is
*explicit* in `config/services.yaml`. What reaches this service is a namespace prefix
(`Erpify\: { resource: '../src/' }`), because a re-registration replaces the root definition and
takes its `tags:` with it — after which enrolment depends on that scope's `autoconfigure`, which
defaults to **false** when `_defaults` omits it. The check asserts the positive declaration on any
scope whose prefix covers the class. Verified against the real files: the processor does implement
`Monolog\Processor\ProcessorInterface`, all three override scopes declare `autoconfigure: true`,
and none registers a covering prefix.

**A `failOnRisky` trap, caught before commit.** The first version of D2's new test executed zero
assertions on today's tree (no override registers a covering prefix), and
`api/tools/phpunit/phpunit.dist.xml` sets `failOnRisky="true"` — it would have red the build for
the wrong reason. Two guards now run ahead of the sweep and double as the anti-vacuity pin.

## Suggested Review Order

1. `pwa/src/context/shared/navigation/infrastructure/hardNavigate.ts` — the contract change, the
   preemption rule, and A1's ordering.
2. `pwa/tests/context/shared/navigation/infrastructure/hardNavigate.test.ts` — the four rewritten
   rows and the seven new ones.
3. `pwa/src/context/shared/access/infrastructure/ui/SessionExpiryCurtain.tsx`'s test — the
   reversal of #830's converged decision.
4. `pwa/eslint-rules/hardNavigation.mjs` + `pwa/tests/eslint/hardNavigationGate.test.ts`.
5. The rename commit alone (`git show 4516ac3`), which is mechanical.
6. `api/tests/Unit/Gate/ConsoleCommandCarrierGateTest.php` — the half with no runtime evidence.
