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

  Scenario: A wrong current password is a 403 that changes nothing and evicts nobody
    Given the stored events are cleared
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
    # Mapping fails before the use case runs, so the aggregate is never reached and no KDF is paid.
    And there should be 0 events stored named "erpify.iam.identity.password-changed"
    And 0 notification emails were sent

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

  @anonymous
  Scenario: The endpoint is closed to a caller with no session
    Given the stored events are cleared
    When I send a POST request to "/me/password" with body:
    """
    { "currentPassword": "alice-password", "newPassword": "brand-new-strong-password" }
    """
    Then the response status code should be 401
    And there should be 0 events stored named "erpify.iam.identity.password-changed"
