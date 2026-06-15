# Deferred work

- Revive the commented-out `api/features/backoffice/bank/create_with_logo.feature` and `create_with_stored_object.feature`. Needs **new** Behat PHP steps (multipart upload, remember-JSON-field + `{placeholder}` substitution, async-messenger processing, transport-empty, notification-email assertion, GET-stored-URL + `Content-Type`/`ETag`/`304`). Own spec/PR — out of scope of the bank Behat test-hardening work.

## Deferred from: code review (2026-06-16)

Latent edge cases in the Behat node-modifier / type-hint helpers, surfaced reviewing the `assert()`-removal work (PR #314). All require degenerate input (an `expected` that the Gherkin table cannot produce, or an `actual` of an impossible type for the field), and no current feature exercises these modifiers — so they are documented, not blocking.

- **D1 — `DateNodeModifier` `null === null` false positive.** `| field::date | null |` against a non-null, non-date actual (e.g. an int epoch) passes wrongly, because both sides process to `null`. A naive guard would break the legitimate "assert the date is null" case, so a real fix must distinguish "absent" from "unparseable". `api/tests/Behat/NodeModifier/Date/DateNodeModifier.php`.
- **D2 — graceful `null` masks a malformed `expected` in `JsonPathContext` numeric/negative assertions.** `getProcessedValue($expected)` is consumed directly by `assertGreaterThan` / between / `assertNotEquals` / `assertNotContains` (`api/tests/Behat/Context/Json/JsonPathContext.php:70,121,192,251-257`); a non-numeric `<amount>` expected now yields `null` (compared as 0) instead of the previous loud abort. Only bites test-author error.
- **D3 — terminal type-hint resolvers pass a non-scalar value through.** `DateValueResolver` / `EnumValueResolver` (`api/tests/Behat/Support/Tool/TypeHint/`) are last in the chain (`TypeHintValueResolver` has no fallback), so a non-scalar value flows on and surfaces as a downstream `TypeError` far from the cause rather than at the resolver.
- **D4 — `HttpResponse::getStreamedResponse` silently drops scalar lines.** A streamed body whose lines all decode to scalars (not JSON objects) now yields a misleadingly-empty `200` instead of the previous abort. `api/tests/Behat/Support/Transport/HttpResponse.php`.
