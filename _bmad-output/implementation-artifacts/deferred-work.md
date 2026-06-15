# Deferred work

- Revive the commented-out `api/features/backoffice/bank/create_with_logo.feature` and `create_with_stored_object.feature`. Needs **new** Behat PHP steps (multipart upload, remember-JSON-field + `{placeholder}` substitution, async-messenger processing, transport-empty, notification-email assertion, GET-stored-URL + `Content-Type`/`ETag`/`304`). Own spec/PR — out of scope of the bank Behat test-hardening work.
