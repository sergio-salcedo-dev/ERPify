# Deferred Work

## Deferred from: code review of feat/api-behat-entity-manager-context (2026-05-07)

- **DQL field/attribute interpolation lacks whitelist (potential injection)** — `EntityManagerContext::applyCriteriaToQueryBuilder`, `getLastEntity`/`getLastEntityFoundBy` `$attribute`, and `RelationQueryHelper` build DQL from string concatenation. Reason: inputs originate from Gherkin feature files (committed by developers); not external attacker input. Defense-in-depth, not a runtime CVE. Add a whitelist if these helpers are ever exposed beyond test code.
- **`new $type($value)` instantiates arbitrary classes from query strings** — `EntityManagerContext::handleQueryStringTypeHinting`. Faithful port of Chiliz; same trust model as above. Replace with an explicit whitelist of allowed coercion targets if ever reused outside test code.
- **`parse_str` rewrites dots in keys to underscores in `parseFindByQueryString`** — non-relation queries with dots in keys silently lose them. Faithful port; documented Chiliz behavior. Relation queries already handled by `RelationQueryHelper::parseQueryStringPreservingDots`.
- **`#[BeforeStep]` clear() detaches entities held in scenario-side variables** — documented design from upstream (FoB dual-container). Could be revisited if a scenario pattern needs to hold entity references across steps.
- **`AmountNodeModifier::compare` treats both sides' nulls as `'0'`** — null≡0 asymmetry hides genuine "missing vs zero" mismatches. Faithful port of Chiliz semantic choice.
- **`entityNamespace` constructor arg has no autowire binding** — shorthand entity-name lookup (`"User"` → `Erpify\…\User`) never works without manual FoB binding. Out of scope for this PR; needs a `services_test.yaml` binding decision aligned with how the team will use the shorthand.
- **`applyCriteriaToQueryBuilder` uses `=` for array values instead of `IN`** — `parse_str("ids[]=1&ids[]=2")` produces an array; current code emits `e.field = ?` which Doctrine rejects. Edge case unlikely to surface from `parseFindByQueryString` for scalar fields. Add `IN ()` handling when the first feature requires it.
- **`theSQLResultAsJSONShouldBe` consumes the forward-only DBAL Result** — second call returns empty rows. Faithful port; not encountered in practice. Cache via `fetchAllAssociative()` once if reuse is needed.

## Deferred from: code review re-run of feat/api-behat-entity-manager-context (2026-05-07)

- **Two relations to the same target with different fields produce duplicate joins** — `pool.id=A&pool.name=B` adds two `INNER JOIN e.pool` with different aliases, inflating result rows and (without `DISTINCT`) the count. Pre-existing in upstream Chiliz; revisit by deduplicating join aliases per relation name in `RelationQueryHelper`.
- **`autoDetectType` ignores `ReflectionUnionType` / `ReflectionIntersectionType`** — properties typed `DateTimeImmutable|null` resolve to a `ReflectionNamedType('DateTimeImmutable')` (nullability folded), but truly disjoint unions (`DateTimeImmutable|string`) yield a `ReflectionUnionType` and bypass coercion. Iterate `getTypes()` if/when the first feature requires it.
- **Empty key from `=value` in `parseQueryStringPreservingDots`** — produces `'' => 'value'` which then emits `e. = :p_0` DQL (parse error rather than a clear diagnostic). Reject empty keys upfront when malformed input becomes a real test pattern.
