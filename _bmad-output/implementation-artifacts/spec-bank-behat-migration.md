---
title: 'Migrate bank Behat features off deleted Mink steps'
type: 'chore'
created: '2026-04-23'
status: 'draft'
context:
  - '{project-root}/api/CLAUDE.md'
  - '{project-root}/api/tools/behat/UPGRADE.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** `api/features/backoffice/bank/*.feature` (6 files, covering create/get/update/delete/logo/stored-object) were written against the old Mink-based contexts that got deleted during the Behat refactor. They are currently tagged `@wip` and skipped by the default Behat run. Every bank behaviour is therefore unverified at the scenario level until its steps get ported to the new `HttpRequestContext` + `JsonContext` stack.

**Approach:** Port each `@wip` bank feature to the new step vocabulary one feature at a time, re-implement the handful of still-missing steps on the new contexts (domain-event assertion, Messenger queue drain, Mailpit inbox check, remembered scenario values, multipart upload), and drop the `@wip` tag as each feature goes green. No production code changes — behaviour is already covered by functional tests today; this is pure spec-level re-coverage.

## Boundaries & Constraints

**Always:**
- Keep the `HttpRequestContext` + `JsonContext` split — do not re-introduce `MinkContext` or external-HTTP transports.
- New step definitions live on the existing contexts where they fit, otherwise on a new DI-resolved context under `tests/Behat/Context/` (e.g. `DomainEventContext`, `MessengerContext`, `MailerContext`, `ScenarioMemoryContext`).
- Scenario memory (`remember … as {alias}` + `{alias}` interpolation in later steps) must be re-implemented on top of the new stack — the previous `ScenarioRememberedValues` helper is gone.
- All re-added contexts get autowired via `services_test.yaml` with `_instanceof` tags where appropriate; no manual Behat context constructors.
- Response-envelope awareness: bank controllers already return the `{"data": …}` shape — assertions must target `data.*` node paths (consistent with health features).

**Ask First:**
- Whether to re-prefix bank routes to `/api/v1/backoffice/banks` on the controllers (matching the existing `routes.yaml` prefix) vs. having features prepend `/backoffice/banks` and relying on `HttpRequestContext.$baseUrl=/api/v1`. Current health features use the latter — confirm bank follows suit.
- Whether to keep the `I go to …` Mink alias or replace every use with `I send a "GET" request to …` in the feature files (feature rewrite scope).
- Whether email-assertion content (Mailpit body scraping) should stay at the scenario level or move to a unit test on the mailer itself.

**Never:**
- Re-introduce `behat/mink`, `behat/mink-browserkit-driver`, or `friends-of-behat/mink-extension` dependencies.
- Change any production `src/Backoffice/Bank/**` behaviour. Purely a test-infra port.
- Delete the existing `tests/Functional/Backoffice/Bank*FunctionalTest.php` — those are the safety net while the port is in flight.

</frozen-after-approval>

## Code Map

- `api/features/backoffice/bank/create.feature` -- 2 scenarios; needs remembered values, domain-event assert, Messenger drain, Mailpit assert.
- `api/features/backoffice/bank/get.feature` -- 3 scenarios; needs remembered values + `I go to` replacement.
- `api/features/backoffice/bank/update.feature` -- needs remembered values + PATCH/PUT body step.
- `api/features/backoffice/bank/delete.feature` -- DELETE + 404 assertion, remembered values.
- `api/features/backoffice/bank/create_with_logo.feature` -- multipart upload step (missing on new contexts).
- `api/features/backoffice/bank/create_with_stored_object.feature` -- multipart + Flysystem assertion.
- `api/tests/Behat/Context/HttpRequestContext.php` -- add `I remember the JSON field :field as :alias` + `{alias}` interpolation + multipart POST helper.
- `api/tests/Behat/Context/JsonContext.php` -- add `I remember the JSON field …` if it belongs here instead.
- `api/tests/Behat/Context/DomainEventContext.php` (NEW) -- PDO-backed assertion against `domain_event` table.
- `api/tests/Behat/Context/MessengerContext.php` (NEW) -- drain async transport, assert failed transport empty.
- `api/tests/Behat/Context/MailerContext.php` (NEW) -- Mailpit HTTP API client for inbox assertions.
- `api/tests/Behat/Context/ScenarioMemory.php` (NEW, or inside HttpRequestContext) -- per-scenario map with `@BeforeScenario` reset.
- `api/config/services_test.yaml` -- register new contexts; wire `MAILPIT_URL` / `DATABASE_URL` env parameters.
- `api/tools/behat/behat.yml.dist` -- add new contexts to the `default` suite.

## Tasks & Acceptance

**Execution:**
- [ ] `api/tests/Behat/Context/HttpRequestContext.php` -- add `I remember the JSON field :field as :alias` + `{alias}` URL interpolation + multipart POST helper -- scenario memory is load-bearing for every bank scenario after `create`.
- [ ] `api/tests/Behat/Context/DomainEventContext.php` -- new context asserting a `domain_event` row exists for a name + aggregate id -- re-covers `a domain event named X should be recorded for aggregate {alias}`.
- [ ] `api/tests/Behat/Context/MessengerContext.php` -- new context for `I process pending async messenger messages` + async/failed transport emptiness -- uses `MessageBusInterface` + `TransportInterface` from DI.
- [ ] `api/tests/Behat/Context/MailerContext.php` -- new context that queries Mailpit and asserts last-email body contains a phrase -- reuses `MAILPIT_URL` from `.env`.
- [ ] `api/config/services_test.yaml` -- register new contexts as public services; add `@BeforeScenario` hook for scenario memory reset + Mailpit inbox purge + Messenger queue purge.
- [ ] `api/tools/behat/behat.yml.dist` -- append the four new contexts to the suite `contexts` list.
- [ ] `api/features/backoffice/bank/*.feature` -- remove `@wip`, rewrite every `I go to …` to `I send a "GET" request to …`, switch absolute URLs to the `/backoffice/banks…` form.
- [ ] `api/config/bundles.php` + `services_test.yaml` -- ensure Mailpit/Messenger test transports are wired for the `test` environment if not already.

**Acceptance Criteria:**
- Given the default `make php.behat` run, when it completes, then all 6 bank features execute and pass (no `@wip` tag remains, no undefined steps, no skipped scenarios).
- Given a failing assertion inside any bank scenario, when Behat reports it, then the error message is human-readable (no PHPUnit Registry crash).
- Given a scenario that creates + reads + mutates a bank, when it runs, then scenario memory correctly propagates `{bankId}` across steps.
- Given the pre-existing `tests/Functional/Backoffice/BankLogoMultipartFunctionalTest.php`, when it runs, then it still passes (no regression).

## Design Notes

Scenario memory was previously on a static `ScenarioRememberedValues` class. In the SymfonyExtension world, a stateful context service reset via `@BeforeScenario` is cleaner — no globals, no race risk, easier to test. Prefer a dedicated `ScenarioMemory` service injected into `HttpRequestContext` so URL interpolation (`locatePath`-style) happens once in one place.

Mailpit exposes a JSON HTTP API at `$MAILPIT_URL/api/v1/messages`; fetch the most recent one and string-search the body. Avoid pulling a full SMTP client into tests.

Multipart: `KernelBrowser::request('POST', $url, $params, $files, $server, $content)` handles multipart natively — no new dependency needed. The new helper just needs to map `TableNode` rows with `file:path/to/fixture` markers into `UploadedFile` instances.

## Verification

**Commands:**
- `make php.behat` -- expected: all scenarios pass, zero `@wip`-skipped.
- `make php.behat c='--tags=@bank'` -- expected: only the bank features execute and pass (run after adding `@bank` tag during the port).
- `make php.unit c='--testsuite=functional'` -- expected: existing `Bank*FunctionalTest` classes still pass.
