---
title: 'Harden backoffice bank Behat features with DB-state and domain-event assertions'
type: 'test'
created: '2026-06-14'
status: 'in-review'
context: []
baseline_commit: 'ddd9956df747341246750a203510745420b97129'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** The `api/features/backoffice/bank` Behat scenarios assert almost entirely on the HTTP response. They never verify that the write actually persisted to the database, nor that the matching domain event landed in the `domain_event` table (the audit/outbox written by `PersistDomainEventMiddleware`). `create.feature` and `delete.feature` are already partially reworked (uncommitted) as the style template; the rest lag behind.

**Approach:** Extend each *mutating* bank feature with post-request assertions against the database using existing `EntityManagerContext` steps — `lastInsertedEntityShouldMatch` / `lastUpdatedEntityShouldMatch` / `anEntityFindByShouldMatch` on the `Bank` aggregate, plus a Doctrine-count step on `StoredDomainEvent` to prove the domain event was recorded. Add **one** reusable JSON step — `the JSON node :node should be a UUID v7`, validated with `symfony/uid` — and apply it **suite-wide** to collapse every repeated UUID-v7 regex on `instance` / `correlation-id` across `api/features` (9 files, 37 lines). No `api/src` changes.

## Boundaries & Constraints

**Always:**
- Use existing Behat steps only; reference entities by **FQCN** (no `entityNamespace` is configured).
- Place every new `EntityManagerContext` / DB step **after** any `… requests got executed …` budget assertion in the scenario — those steps run SELECTs on the tracked `default` connection and would otherwise inflate the budget.
- Verify domain events via the **count** step (`there should have N "…StoredDomainEvent" entity found by "…"`). `StoredDomainEvent` exposes `name()`-style accessors (no `get` prefix), so the property-access *match* step cannot read it.
- Filter `domain_event` by the **known seed `aggregateId`** in update/delete; in create (server-generated id, no remember-id step exists) filter by **event name** — safe because the table is restored empty on feature entry and only the one success scenario emits a `created` event.
- Match `Bank` only on persisted fields (`name`, `shortName`). `accountCount` is derived at read time (entity value is `0`) — never match it on the entity.
- Keep existing query-budget numbers; re-run Behat and adjust only to a freshly observed value.
- Remove every `print last JSON response` debug step from the edited scenarios; add no new ones.
- The UUID-v7 step must assert **version 7** specifically (via `symfony/uid`), matching the strength of the regex it replaces; run `make php.stan` on `JsonNodeContext.php` and `make php.quality` before done.

**Ask First:**
- If a `data should have N elements` count cannot be satisfied by adjusting N (the write-path projection genuinely differs from create's), surface it instead of dropping the assertion.

**Never:**
- No `api/src` edits. The only new PHP is one step method in `JsonNodeContext` (the UUID-v7 step) — no other new step definitions or context changes.
- Do not revive the commented multipart features (`create_with_logo`, `create_with_stored_object`) — deferred to a separate spec/PR (recorded in `deferred-work.md`); they need new PHP steps (multipart, remember-field, async-messenger, email).
- Do not add `behat/*` to `api/composer.json`.

## I/O & Edge-Case Matrix

| Feature / scenario | DB-state assertion added | Domain-event assertion added |
|--------------------|--------------------------|------------------------------|
| `create.feature` — success | `lastInserted` `Bank` matches `name`/`shortName` | count 1 `created` by event name |
| `update.feature` — success | enrich JSON nodes; `lastUpdated` `Bank` matches | count 1 `updated` by seed `aggregateId` |
| `delete.feature` — success | `Bank` found-by id **does not exist** | count 1 `deleted` by seed `aggregateId` |
| `get.feature` — get one | `anEntityFindBy` `Bank` (id) matches `name`/`shortName` | none (read-only) |
| `search.feature` — account-count row | `anEntityFindBy` `Bank` (`shortName=JPM`) matches `name` | none (read-only) |

</frozen-after-approval>

## Code Map

- `api/features/backoffice/bank/create.feature` — success scenario; replace the dead commented step block with working `lastInserted` + event-count assertions.
- `api/features/backoffice/bank/update.feature` — success scenario; weak `response should contain` → JSON nodes + `lastUpdated` + event count.
- `api/features/backoffice/bank/delete.feature` — success scenario; add `does not exist` + event count.
- `api/features/backoffice/bank/get.feature` — "Get a single bank by id"; add `anEntityFindBy` match.
- `api/features/backoffice/bank/search.feature` — "The list carries the associated-account count per bank"; add one read-only `anEntityFindBy` cross-check.
- `api/tests/Behat/Context/Json/JsonNodeContext.php` — **edit**: add `the JSON node :node should be a UUID v7`; node value via `$this->getJsonInspector()->evaluate($this->getJson(), $node)` (mirror `theJsonNodeShouldMatch`); validate with `Symfony\Component\Uid\UuidV7`.
- `api/tests/Behat/Context/EntityManagerContext.php` — read-only: step regex/signatures (`The last inserted/updated "<FQCN>" entity should match:`, `A "<FQCN>" entity found by "<qs>" should match:`, `there should have N "<FQCN>" entity found by "<qs>"`, `The "<FQCN>" entity found by "<qs>" does not exist`).
- `api/src/Shared/Infrastructure/Persistence/Entity/StoredDomainEvent.php` — read-only: table `domain_event`, fields `name`, `aggregateId`.
- Event names: `erpify.backoffice.bank.{created,updated,deleted}`. Seed ids: create=server-generated `Test Bank`/`TB`; update=`ed17ed00-0000-7000-8000-000000000001`; delete=`de1e7e00-0000-7000-8000-000000000001`; get=`11111111-1111-7000-8000-000000000001` (`JPMorgan Chase`/`JPM`).

## Tasks & Acceptance

**Execution:**
- [x] `api/tests/Behat/Context/Json/JsonNodeContext.php` -- add `#[Then('the JSON node :node should be a UUID v7')]`: evaluate the node, assert it is a string, assert `UuidV7::isValid($value)` (fall back to `Uuid::fromString($value) instanceof UuidV7` if the typed `isValid` does not version-check). Fail message names the node + value.
- [x] `api/features/**/*.feature` (suite-wide) -- replace every `the JSON node "instance"/"correlation-id" should match "/^[0-9a-f]{8}-…-7…$/"` with `the JSON node "…" should be a UUID v7`. 9 files / 37 lines: bank `create`(2)/`delete`(4)/`get`(4)/`update`(2); `shared/error_contract/`symfony_bridges(12)/validation_violations(4)/correlation_id_response_header(4)/instance_uuidv7(3); `shared/rate_limiting/anonymous_api`(2). All are v7-pinned — pure mechanical swap; re-grep after to confirm 0 left.
- [x] `api/features/backoffice/bank/create.feature` -- in the success scenario, after the budget line replace the commented `#` block with `lastInserted "…\Bank"` (name/shortName) + `there should have 1 "…\StoredDomainEvent" entity found by "name=erpify.backoffice.bank.created"`.
- [x] `api/features/backoffice/bank/update.feature` -- replace `response should contain` with JSON-node assertions (id, name, shortName, updatedAt not null, accountCount not exist, `data` element count), then `lastUpdated "…\Bank"` + event count by `aggregateId=ed17ed00…&name=…updated` (after the budget line).
- [x] `api/features/backoffice/bank/delete.feature` -- after the budget line add `Bank` found-by-id `does not exist` + event count by `aggregateId=de1e7e00…&name=…deleted`.
- [x] `api/features/backoffice/bank/get.feature` -- in "Get a single bank by id", after the budget line add `anEntityFindBy "…\Bank" found by "id=11111111…001"` matching name/shortName.
- [x] `api/features/backoffice/bank/search.feature` -- in "The list carries the associated-account count per bank", after the budget line add `anEntityFindBy "…\Bank" found by "shortName=JPM"` matching `name=JPMorgan Chase`.
- [x] All edited features -- delete every `print last JSON response` debug step.

**Acceptance Criteria:**
- Given the bank suite, when `make php.behat` runs the five edited features, then all scenarios pass.
- Given a create/update/delete success scenario, when it completes, then exactly one matching row exists in `domain_event`.
- Given the new DB steps run after the budget assertion, then no `… requests got executed …` assertion changes value.

## Spec Change Log

- **Flaky `lastInserted`/`lastUpdated` → scoped `foundBy` variant.** Fixtures stamp `created_at`/`updated_at` at load (`Bank::create`) and the column is `TIMESTAMP(0)` (second precision); a ~3s run can land the new bank in the same tick as fixtures, so the *global* `last inserted/updated … should match:` ordered by a tied column and returned a fixture ("Sociedad Anónima"). Switched create→`last inserted … found by "shortName=TB"` and update→`last updated … found by "id=ed17ed00…"` (same step family, scoped to one row → deterministic). Avoids a timing-dependent flake.
- **Second new step required: `the header :name should be a UUID v7`.** The suite-wide sweep of the `correlation-id` regex also hit response-header assertions (`the header "X-Correlation-Id" should match "/…/"`), which the JSON-node step does not cover (would leave undefined steps). Added the sibling step to `HttpResponseContext`. The frozen "Never: only one new step" is superseded by the (also-frozen) suite-wide-sweep Approach, which necessarily spans `correlation-id` as both JSON node and header.
- **Corrected `title` assertion.** The empty-payload scenario asserted `title` = `"validation failed"`; the API returns `"Validation failed."` — pinned to the real value.

## Design Notes

Golden example (create.feature success tail):

```gherkin
    And 8 requests got executed only for doctrine connection "default"
    And the last inserted "Erpify\Backoffice\Bank\Domain\Entity\Bank" entity should match:
      | name      | Test Bank |
      | shortName | TB        |
    And there should have 1 "Erpify\Shared\Infrastructure\Persistence\Entity\StoredDomainEvent" entity found by "name=erpify.backoffice.bank.created"
```

Why count, not match, for events: `StoredDomainEvent` has `name()`/`aggregateId()` (no `get`), unreadable by Symfony PropertyAccess; the count step filters via Doctrine criteria, so a count of 1 already proves a row with that exact name + aggregateId exists. `data` element counts (e.g. 7) are pinned by running Behat, not guessed.

UUID-v7 step (replaces two long regex lines per error scenario):

```gherkin
    And the JSON node "instance" should be a UUID v7
    And the JSON node "correlation-id" should be a UUID v7
```

```php
#[Then('the JSON node :node should be a UUID v7')]
public function theJsonNodeShouldBeAUuidV7(string $node): void
{
    $value = $this->getJsonInspector()->evaluate($this->getJson(), $node);
    self::assertIsString($value, \sprintf('JSON node "%s" is not a string.', $node));
    self::assertTrue(UuidV7::isValid($value), \sprintf('JSON node "%s" value "%s" is not a UUID v7.', $node, $value));
}
```

The typed `UuidV7::isValid()` also enforces the RFC-4122 variant — strictly stronger than the old regex, harmless for minted v7 ids.

## Verification

**Commands:**
- `make php.behat.install` -- expected: behat tooling present (isolated tree).
- `make php.stan PHP_SERVICE=messenger_worker` -- expected: 0 errors on the changed `JsonNodeContext.php` (worker sibling avoids the web-worker segfault).
- `make php.quality` -- expected: green (cs-fixer / rector / phpmd clean on the new step).
- `make php.behat c='features/backoffice/bank/create.feature features/backoffice/bank/update.feature features/backoffice/bank/delete.feature features/backoffice/bank/get.feature features/backoffice/bank/search.feature'` -- expected: all scenarios green; if a `data should have N elements` or budget count mismatches, correct it to the observed value and re-run.
- `make php.behat` -- expected: full suite still green (no cross-feature regression).
