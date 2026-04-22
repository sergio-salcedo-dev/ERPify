---
title: 'Reorganize tests/Behat/ by purpose (Transport / Assertion / Support)'
type: 'refactor'
created: '2026-04-23'
status: 'done'
baseline_commit: '59bf467edc6aab67988a6007ba597b1311b84aa8'
context:
  - '{project-root}/api/CLAUDE.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** `api/tests/Behat/` grew organically and mixes unrelated concerns in flat folders: `Context/` holds both request-sending and JSON-asserting contexts; `HttpResponse/` and `Json/` live as peers even though the first is about transport and the second is about assertion; `NodeModifier/` is split between abstractions and implementations; `Tool/` is a generic utility bucket. New engineers can't tell at a glance what belongs where. The current layout also encourages cross-concern imports (e.g. a future assertion helper reaching into `HttpResponse/`).

**Approach:** Re-home every file under three purpose-scoped top-level directories — `Transport/` (HTTP I/O), `Assertion/` (value comparison against transported bodies), `Support/` (cross-cutting test plumbing) — updating namespaces and `services_test.yaml` in lockstep. No behaviour change; pure rename. Verified green by the existing Behat + PHPUnit suites.

## Boundaries & Constraints

**Always:**
- Preserve file history: use `git mv` for every move so blame/log survive the rename.
- Namespaces mirror the new directory tree exactly — PSR-4 stays intact.
- Update `services_test.yaml` `resource:` / `exclude:` globs and `tools/behat/behat.yml.dist` context FQCNs in the same commit as the moves.
- All existing Behat scenarios (health + any un-skipped bank scenarios) must stay green after the reorg.

**Ask First:**
- Whether the DDD relocation should happen **before or after** the bank-feature migration (`spec-bank-behat-migration.md`). Doing it before minimizes churn in the bank migration PR; doing it after lets the bank work settle the final shape of contexts. Recommend: do this one first, it's mechanical.
- Whether `Abstraction/` sub-folders should be flattened (now that DDD directories group by purpose, keeping a parallel `Abstraction/` under each is often redundant).

**Never:**
- Rename any `*.feature` file or alter a scenario step (out of scope — that's the bank-migration story).
- Change any `src/` production code.
- Introduce a new test dependency.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| Move `HttpResponse/` under `Transport/` | `tests/Behat/HttpResponse/{HttpResponse,HttpResponseContainer}.php` | Same classes at `tests/Behat/Transport/{HttpResponse,HttpResponseContainer}.php`, namespace `Erpify\Tests\Behat\Transport\*` | Autoloader still resolves; DI still wires. |
| Split `NodeModifier/` by role | Abstraction + Implementation subfolders | `Assertion/NodeModifier/Implementation/*`; interface + abstract base at `Assertion/NodeModifier/{NodeModifierInterface,AbstractNodeModifier}.php` | `_instanceof` tag in services_test.yaml still matches the moved interface. |
| Move `Tool/ArrayTools.php` to `Support/Tool/` | generic helper | Class available under `Erpify\Tests\Behat\Support\Tool\ArrayTools` | — |
| JsonContext traits move | `Context/Trait/*` | `Support/PostProcess/*` (or similar) | JsonContext `use` statements updated. |

</frozen-after-approval>

## Code Map

Target layout (final state):

```
tests/Behat/
├── Context/
│   ├── Abstraction/AbstractContext.php          # unchanged location
│   ├── HttpRequestContext.php                   # entry-point contexts stay here
│   └── JsonContext.php
├── Transport/
│   ├── HttpResponse.php                         # was HttpResponse/
│   └── HttpResponseContainer.php
├── Assertion/
│   ├── Json/
│   │   ├── Json.php                             # was Json/
│   │   ├── JsonInspector.php
│   │   └── JsonSchema.php
│   └── NodeModifier/
│       ├── NodeModifierInterface.php            # was NodeModifier/Abstraction/
│       ├── AbstractNodeModifier.php
│       ├── NodeModifierLocator.php
│       └── Implementation/*.php                 # unchanged
└── Support/
    ├── PostProcess/                             # was Context/Trait/
    │   ├── JsonToolTrait.php
    │   ├── JsonPathToolTrait.php
    │   └── PropertyPostProcessTrait.php
    └── Tool/
        └── ArrayTools.php
```

Files to touch alongside the moves:

- `api/tests/Behat/Context/JsonContext.php` -- update `use` statements for Json + NodeModifier + trait moves.
- `api/tests/Behat/Context/HttpRequestContext.php` -- update `use` for `Transport\HttpResponse*`.
- `api/config/services_test.yaml` -- update `Erpify\Tests\Behat\:` resource + exclude globs; the `_instanceof` tag still targets the interface's new FQCN.
- `api/tools/behat/behat.yml.dist` -- context FQCNs unchanged (both contexts stay in `Context/`).
- Every node modifier implementation file -- update `use Erpify\Tests\Behat\NodeModifier\Abstraction\*` → `Erpify\Tests\Behat\Assertion\NodeModifier\*`.

## Tasks & Acceptance

**Execution:**
- [x] `git mv api/tests/Behat/HttpResponse api/tests/Behat/Transport` and rename namespace in both files.
- [x] `git mv api/tests/Behat/Json api/tests/Behat/Assertion/Json` and update namespaces + importers.
- [x] `git mv api/tests/Behat/NodeModifier api/tests/Behat/Assertion/NodeModifier`; flatten `Abstraction/` contents up one level; update namespaces in all implementations.
- [x] `git mv api/tests/Behat/Tool api/tests/Behat/Support/Tool`.
- [x] `git mv api/tests/Behat/Context/Trait api/tests/Behat/Support/PostProcess`; update trait namespaces + every `use`.
- [x] `api/config/services_test.yaml` -- rewrite `resource:`/`exclude:` globs to match the new layout.
- [x] Run `make php.behat` + PHPUnit smoke to confirm zero regressions. Behat: 2/2 scenarios, 16/16 steps pass. PHPUnit: 20 tests / 49 assertions / 5 errors — same baseline as pre-change (errors pre-existing, unrelated).

**Acceptance Criteria:**
- Given `git log --follow <moved_file>`, when run, then history spans across the rename (no lost blame).
- Given `make php.behat`, when it runs, then all currently-green scenarios remain green (2 health scenarios minimum; more if executed after the bank migration).
- Given `bin/phpunit`, when it runs on the full unit tier, then all tests still pass.
- Given a fresh `grep -rn 'tests/Behat/HttpResponse\|tests/Behat/Json\b\|tests/Behat/NodeModifier/Abstraction\|tests/Behat/Tool\|tests/Behat/Context/Trait'` of the repo, when executed, then zero hits (no stale paths in configs/docs/scripts).

## Design Notes

Three top-level purposes keep the tree self-documenting:
- **Transport** = how bytes leave/arrive (request/response carriers). No assertion logic.
- **Assertion** = how we compare what arrived to what we expected. No HTTP.
- **Support** = cross-cutting test plumbing (helpers, traits, abstractions) that serves both.

`Context/` stays at the top level because those are the Behat entry-points — DI injects Transport + Assertion helpers *into* contexts. Keeping contexts out of the three buckets avoids a circular dependency and keeps the `tools/behat/behat.yml.dist` context list stable.

If `Context/Abstraction/` ends up holding only `AbstractContext.php` post-reorg, consider collapsing it into `Context/AbstractContext.php` as a follow-up micro-PR.

## Verification

**Commands:**
- `make php.behat` -- expected: same pass count as before the reorg.
- `docker compose exec -T php bin/phpunit tests/Unit` -- expected: all unit tests pass.
- `grep -rn 'Erpify\\\\Tests\\\\Behat\\\\\(HttpResponse\|Json\\\\\|NodeModifier\\\\Abstraction\|Tool\|Context\\\\Trait\)' api/` -- expected: zero matches after the move.

## Suggested Review Order

**Purpose-scoped layout**

- Entry point: the new three-bucket tree + contexts that consume it.
  [`Context/HttpRequestContext.php:15`](../../api/tests/Behat/Context/HttpRequestContext.php#L15)

- JsonContext rewires to the new Assertion/Support namespaces.
  [`Context/JsonContext.php:13`](../../api/tests/Behat/Context/JsonContext.php#L13)

**DI wiring**

- Service resource/exclude globs mirror the new tree; `HttpResponseContainer` alias moved to `Transport\`.
  [`services_test.yaml:10`](../../api/config/services_test.yaml#L10)

**Flattened NodeModifier abstractions**

- Interface + abstract now sit one level up; `AutoconfigureTag` still drives the locator.
  [`Assertion/NodeModifier/NodeModifierInterface.php:9`](../../api/tests/Behat/Assertion/NodeModifier/NodeModifierInterface.php#L9)
  [`Assertion/NodeModifier/AbstractNodeModifier.php:5`](../../api/tests/Behat/Assertion/NodeModifier/AbstractNodeModifier.php#L5)
  [`Assertion/NodeModifier/NodeModifierLocator.php:5`](../../api/tests/Behat/Assertion/NodeModifier/NodeModifierLocator.php#L5)

**Transport and Support moves**

- HTTP I/O carriers rehomed under Transport.
  [`Transport/HttpResponse.php:5`](../../api/tests/Behat/Transport/HttpResponse.php#L5)
  [`Transport/HttpResponseContainer.php:5`](../../api/tests/Behat/Transport/HttpResponseContainer.php#L5)

- Traits renamed into Support/PostProcess; generic helper into Support/Tool.
  [`Support/PostProcess/JsonToolTrait.php:5`](../../api/tests/Behat/Support/PostProcess/JsonToolTrait.php#L5)
  [`Support/Tool/ArrayTools.php:5`](../../api/tests/Behat/Support/Tool/ArrayTools.php#L5)
