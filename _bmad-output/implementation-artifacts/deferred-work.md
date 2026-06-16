# Deferred work

- Revive the commented-out `api/features/backoffice/bank/create_with_logo.feature` and `create_with_stored_object.feature`. Needs **new** Behat PHP steps (multipart upload, remember-JSON-field + `{placeholder}` substitution, async-messenger processing, transport-empty, notification-email assertion, GET-stored-URL + `Content-Type`/`ETag`/`304`). Own spec/PR — out of scope of the bank Behat test-hardening work.

## Deferred from: code review of 2026-06-15-behat-event-observability-contexts-design (2026-06-16)

Latent test-harness hardening surfaced reviewing PR #316 (Behat event-observability contexts). Neither bites a current scenario; documented, not blocking.

- **Missing-path null/elements steps throw instead of asserting.** The new `The outbox event property … should be null` / `should have :count elements` and the `Mercure update property …` equivalents delegate to `JsonToolTrait`, whose inspector throws (`THROW_ON_INVALID_PROPERTY_PATH`) on an absent dot-path — an uncaught error rather than a clean assertion. `should not exist` correctly try/catches it. Pre-existing trait semantic; only bites if a feature asserts `should be null` on a key the payload omits. `api/tests/Behat/Context/{OutboxContext,MercureContext}.php`.
- **Non-queue-qualified outbox step indexing spans queues.** `:number outbox event was created` and `I got the event number :number from the outbox` (the variants without `on queue …`) concatenate `async`+`failed` under one global 1-based index, so anything left on `failed` shifts the numbering. Safe today — `failed` is empty in the happy path and the bank features use the queue-qualified variants — so it is documented rather than restructured. `api/tests/Behat/Context/OutboxContext.php`.
