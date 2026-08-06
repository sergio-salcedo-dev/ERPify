Feature: Change my own password from a live session
  As a signed-in user
  In order to rotate my credential without losing access to the application
  I need an endpoint that re-proves the password I hold, replaces it, signs my other devices out and leaves
  the device I am using still navigating

  # Every scenario that actually replaces the credential restores the fixtures as its LAST step, and the
  # position is load-bearing rather than tidiness: the suite seats its session token before the first step
  # runs, so a restore at the START of the next scenario would swap the stored hash out from under a token
  # already built over it — the firewall reads that as a de-authentication and answers 401 before the
  # endpoint is ever reached. Cleaning up on the way out keeps the token and the row in step.
  Background:
    Given I add "Content-Type" header equal to "application/json"
    And I add "Accept" header equal to "application/json"

  Scenario: A correct change replaces the credential, keeps this device signed in and revokes every other one
    Given I execute the SQL query "INSERT INTO iam_session (id, user_id, organization_id, status, expires_at, device, created_at, updated_at) VALUES ('0190d1e2-f3a4-7b5c-8d6e-1f2a3b4c5dfe', '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b', '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a50', 'ACTIVE', NOW() + INTERVAL '1 day', 'A second device', NOW(), NOW())"
    And the stored events are cleared
    When I send a POST request to "/me/password" with body:
    """
    { "currentPassword": "alice-password", "newPassword": "brand-new-strong-password" }
    """
    Then the response status code should be 204
    And the response should be empty
    And there should be 1 event stored named "erpify.iam.identity.password-changed"
    And there should be 1 event stored named "erpify.iam.session.all-revoked"
    # The identity aggregate is a natural person, so its facts are handled in process; a copy on a persisted
    # transport would outlive the erasure the application confirms to the person the id names.
    And 0 outbox events were created on the queue "async"
    And 1 notification email was sent
    And I get the sent notification email number 1
    And The notification email recipient should be "alice@erpify.test"
    And The notification email subject should be equal to "Your ERPify password has changed"
    # The device that made the change keeps navigating: the revoke swept every session and the programmatic
    # login minted a replacement onto this request's cookie.
    And I send a "GET" request to "/me"
    And the response status code should be 200
    And the JSON node "data.email" should be equal to "alice@erpify.test"
    # Exactly one session survives, and it is neither of the two that existed before.
    And I execute the SQL query "SELECT id FROM iam_session WHERE user_id = '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b' AND status = 'ACTIVE'"
    And there should have 1 records in SQL result
    And I execute the SQL query "SELECT id FROM iam_session WHERE id IN ('0190d1e2-f3a4-7b5c-8d6e-1f2a3b4c5d01', '0190d1e2-f3a4-7b5c-8d6e-1f2a3b4c5dfe') AND status = 'ACTIVE'"
    And there should have 0 records in SQL result
    # The stored credential really moved: the old one no longer re-proves ownership, the new one does.
    And I send a POST request to "/me/password" with body:
    """
    { "currentPassword": "alice-password", "newPassword": "yet-another-strong-password" }
    """
    And the response status code should be 403
    And the JSON node "type" should be equal to "invalid-current-password"
    And I send a POST request to "/me/password" with body:
    """
    { "currentPassword": "brand-new-strong-password", "newPassword": "yet-another-strong-password" }
    """
    And the response status code should be 204
    And I reload the fixtures

  Scenario: A wrong current password is a 403 that changes nothing, evicts nobody and leaves a forensic row
    Given the stored events are cleared
    And I add "X-Correlation-Id" header equal to "0190dead-beef-7abc-8def-00112233c0de"
    When I send a POST request to "/me/password" with body:
    """
    { "currentPassword": "not-alices-password", "newPassword": "brand-new-strong-password" }
    """
    Then the response status code should be 403
    And the header "Content-Type" should contain "application/problem+json"
    And the JSON node "type" should be equal to "invalid-current-password"
    And there should be 0 events stored named "erpify.iam.identity.password-changed"
    And there should be 0 events stored named "erpify.iam.session.all-revoked"
    And 0 notification emails were sent
    # The guess is recorded synchronously, and RESOURCE-LESS: the only candidate resource is the caller's own
    # identity, which `actor_id` already seals — naming it again would write actor_id == resource_id by
    # construction, onto the person axis of `audit_log.resource_id`. The row survives the refusal because the
    # use case rolls its transaction back before the exception reaches the listener.
    And I execute the SQL query "SELECT action, level, actor_type, actor_id, resource_type, resource_id, metadata FROM audit_log WHERE action = 'INVALID_CURRENT_PASSWORD' AND correlation_id = '0190dead-beef-7abc-8def-00112233c0de'"
    And the SQL result as JSON should be:
    """
    [
      {
        "action": "INVALID_CURRENT_PASSWORD",
        "level": "security",
        "actor_type": "user",
        "actor_id": "0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b",
        "resource_type": null,
        "resource_id": null,
        "metadata": "{\"route\": \"iam_me_change_password\"}"
      }
    ]
    """
    # 403 rather than 401 is the point: a mistyped password must leave the caller inside the application.
    And I execute the SQL query "SELECT id FROM iam_session WHERE id = '0190d1e2-f3a4-7b5c-8d6e-1f2a3b4c5d01' AND status = 'ACTIVE'"
    And there should have 1 records in SQL result
    And I send a "GET" request to "/me"
    And the response status code should be 200
    # The credential is untouched, so it still re-proves ownership.
    And I send a POST request to "/me/password" with body:
    """
    { "currentPassword": "alice-password", "newPassword": "brand-new-strong-password" }
    """
    And the response status code should be 204
    And I reload the fixtures

  Scenario: A new password equal to the current one is a 422 that writes nothing
    Given the stored events are cleared
    When I send a POST request to "/me/password" with body:
    """
    { "currentPassword": "alice-password", "newPassword": "alice-password" }
    """
    Then the response status code should be 422
    And the header "Content-Type" should contain "application/problem+json"
    And the JSON node "type" should be equal to "new-password-must-differ"
    And there should be 0 events stored named "erpify.iam.identity.password-changed"
    And there should be 0 events stored named "erpify.iam.session.all-revoked"
    And 0 notification emails were sent
    And I send a "GET" request to "/me"
    And the response status code should be 200

  Scenario: A new password below the policy floor is refused at the wire edge
    Given the stored events are cleared
    When I send a POST request to "/me/password" with body:
    """
    { "currentPassword": "alice-password", "newPassword": "short" }
    """
    Then the response status code should be 422
    And the JSON node "type" should be equal to "validation-failed"
    And the JSON node "violations" should have 1 element
    And the JSON node "violations[0].field" should be equal to "newPassword"
    And the JSON node "violations[0].message" should be equal to "The password must be at least 8 characters."
    # Mapping fails before the use case runs, so the aggregate is never reached and no KDF is paid.
    And there should be 0 events stored named "erpify.iam.identity.password-changed"
    And 0 notification emails were sent

  # `NotBlank` admits eight spaces, so the policy owns this refusal on its own. The rule is written with
  # `mb_trim`, which is why a U+00A0 does not survive it — an ASCII `trim()` would let all eight through and
  # store a credential nobody can retype.
  Scenario: A new password made only of whitespace is refused, non-breaking spaces included
    Given the stored events are cleared
    When I send a POST request to "/me/password" with body:
    """
    { "currentPassword": "alice-password", "newPassword": "        " }
    """
    Then the response status code should be 422
    And the JSON node "violations[0].field" should be equal to "newPassword"
    And the JSON node "violations[0].message" should be equal to "The password must contain at least one non-whitespace character."
    And I send a POST request to "/me/password" with body:
    """
    { "currentPassword": "alice-password", "newPassword": "        " }
    """
    And the response status code should be 422
    And the JSON node "violations[0].message" should be equal to "The password must contain at least one non-whitespace character."
    And I send a POST request to "/me/password" with body:
    """
    { "currentPassword": "alice-password", "newPassword": "　　　　　　　　" }
    """
    And the response status code should be 422
    And the JSON node "violations[0].message" should be equal to "The password must contain at least one non-whitespace character."
    And there should be 0 events stored named "erpify.iam.identity.password-changed"

  Scenario: A new password above the policy ceiling is refused at the wire edge
    Given the stored events are cleared
    When I send a POST request to "/me/password" with body:
    """
    { "currentPassword": "alice-password", "newPassword": "0123456789012345678901234567890123456789012345678901234567890123456789012345678901234567890123456789012345678901234567890123456789" }
    """
    Then the response status code should be 422
    And the JSON node "violations[0].field" should be equal to "newPassword"
    And the JSON node "violations[0].message" should be equal to "The password must not exceed 128 characters."
    And there should be 0 events stored named "erpify.iam.identity.password-changed"

  # The wall the use case raises from behind the row lock, in the vocabulary the rest of the product uses for a
  # walled identity — not the lifecycle-transition 409 nobody asked about. The session outlives the suspension
  # (the firewall refreshes the user, it does not re-run the admission checks), so the request really does
  # arrive here.
  Scenario: An identity suspended while its session is alive is walled before any credential work
    Given the stored events are cleared
    And I execute the SQL query "UPDATE identity_user SET status = 'SUSPENDED' WHERE email = 'alice@erpify.test'"
    When I send a POST request to "/me/password" with body:
    """
    { "currentPassword": "alice-password", "newPassword": "brand-new-strong-password" }
    """
    Then the response status code should be 403
    And the header "Content-Type" should contain "application/problem+json"
    And the JSON node "type" should be equal to "account-suspended"
    And there should be 0 events stored named "erpify.iam.identity.password-changed"
    And there should be 0 events stored named "erpify.iam.session.all-revoked"
    And 0 notification emails were sent
    And I reload the fixtures

  # A live lockout is orthogonal to `ACTIVE`, so it never bars this route, and it is gone by the end of the
  # request. This scenario asserts that OUTCOME and nothing finer: the post-commit login also fires
  # `ClearLockoutOnLoginSuccess`, so deleting the explicit `clearLockout()` leaves it green. That the clearing
  # is a decision of the use case rather than a side effect inherited two layers away is pinned where it can
  # go red — `ChangeMyPasswordTest`, over doubles that mint no session.
  Scenario: A locked identity that re-proves its password changes it and leaves unlocked
    Given the stored events are cleared
    And I execute the SQL query "UPDATE identity_user SET failed_attempts = 10, locked_until = '2099-01-01 00:00:00' WHERE email = 'alice@erpify.test'"
    When I send a POST request to "/me/password" with body:
    """
    { "currentPassword": "alice-password", "newPassword": "brand-new-strong-password" }
    """
    Then the response status code should be 204
    And there should be 1 event stored named "erpify.iam.identity.password-changed"
    And I execute the SQL query "SELECT id FROM identity_user WHERE email = 'alice@erpify.test' AND failed_attempts = 0 AND locked_until IS NULL"
    And there should have 1 records in SQL result
    And I reload the fixtures

  Scenario: A missing current password is refused at the wire edge
    When I send a POST request to "/me/password" with body:
    """
    { "newPassword": "brand-new-strong-password" }
    """
    Then the response status code should be 422
    And the JSON node "type" should be equal to "validation-failed"
    And the JSON node "violations[0].field" should be equal to "currentPassword"

  # The payload is strict, so a body that also asks for something this endpoint does not implement is refused
  # whole rather than half-executed.
  Scenario: A body carrying a member the endpoint does not implement is refused whole
    Given the stored events are cleared
    When I send a POST request to "/me/password" with body:
    """
    {
      "currentPassword": "alice-password",
      "newPassword": "brand-new-strong-password",
      "roles": ["ADMIN"]
    }
    """
    Then the response status code should be 422
    And the JSON node "type" should be equal to "validation-failed"
    And the JSON node "violations[0].field" should be equal to "roles"
    And there should be 0 events stored named "erpify.iam.identity.password-changed"

  # The budget is primed in the SAME scenario as the request that observes it: the test cache pool is the array
  # adapter, reset on every kernel.terminate, so "five succeed and the sixth is refused" cannot be expressed as
  # six sequential HTTP steps.
  Scenario: A drained per-identity budget refuses the change out loud, before any credential work
    Given the stored events are cleared
    And the password-change budget is exhausted for identity "0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b"
    When I send a POST request to "/me/password" with body:
    """
    { "currentPassword": "alice-password", "newPassword": "brand-new-strong-password" }
    """
    Then the response status code should be 429
    And the header "Content-Type" should contain "application/problem+json"
    And the JSON node "type" should be equal to "rate-limited"
    # A 429 owes the caller a retry hint (RFC 9110 §10.2.3), and the drained budget must be the one the headers
    # describe — not the per-IP budget this request left untouched.
    And the header "Retry-After" should exist
    And the header "Retry-After" should match "/^[1-9]\d*$/"
    And the header "RateLimit-Remaining" should be equal to "0"
    # Refused before the payload is weighed: no KDF, no aggregate, no effects.
    And there should be 0 events stored named "erpify.iam.identity.password-changed"
    And there should be 0 events stored named "erpify.iam.session.all-revoked"
    And 0 notification emails were sent
    # The visible refusal is legitimate here and nowhere upstream: the caller already holds this identity, so
    # naming its budget discloses nothing a session did not already prove.
    And I send a "GET" request to "/me"
    And the response status code should be 200

  @anonymous
  Scenario: The endpoint is closed to a caller with no session
    Given the stored events are cleared
    When I send a POST request to "/me/password" with body:
    """
    { "currentPassword": "alice-password", "newPassword": "brand-new-strong-password" }
    """
    Then the response status code should be 401
    And there should be 0 events stored named "erpify.iam.identity.password-changed"
