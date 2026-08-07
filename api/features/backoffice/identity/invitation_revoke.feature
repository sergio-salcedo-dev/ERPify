Feature: Revoke a pending invitation from the console
  As an administrator
  In order to pull back an invitation that should never have gone out
  I need one authenticated action, keyed by the invited person, that retires every live invitation of theirs
  and kills the delivered accept token — without a container shell

  # ADMIN-only (users.revokeInvitation). The endpoint is keyed by the USER, not by the invitation: the console
  # operates on a row of the users register, which is owned by Identity and knows nothing of iam_invitation.
  # Nothing constrains a user to one invitation (invited_user_id carries a plain index, no uniqueness), so it
  # revokes every SENT one atomically — under incident response, refusing on several would leave live tokens.
  Background:
    # The seeded invitation is consumed by the scenarios below, so restore it before each one (the suite
    # restores only per-feature otherwise), the same reason the accept feature reloads.
    Given I reload the fixtures
    And I add "Content-Type" header equal to "application/json"
    And I add "Accept" header equal to "application/json"

  Scenario: An administrator revokes a member's pending invitation
    Given I am logged in as an administrator
    And the stored events are cleared
    And I reset the stats for all doctrine connections
    When I send a DELETE request to "/backoffice/users/0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a70/invitation"
    Then the response status code should be 204
    And the response should be empty
    And I execute the SQL query "SELECT id FROM iam_invitation WHERE invited_user_id = '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a70' AND status = 'REVOKED'"
    And there should have 1 records in SQL result
    And there should be 1 event stored named "erpify.iam.invitation.revoked"
    # The identity is withdrawn in the same transaction. Without it the register would keep a person at
    # INVITED carrying the roles that were being granted, for as long as the installation lives.
    And I execute the SQL query "SELECT id FROM identity_user WHERE id = '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a70' AND status = 'REVOKED'"
    And there should have 1 records in SQL result
    And there should be 1 event stored named "erpify.iam.identity.invitation-revoked"
    # Invitation events are deliberately unrouted — no realtime consumer — so the write leaves async empty.
    And 0 outbox events were created on the queue "async"
    # The wrapped write's query budget (auth/admission lookups are excluded from the counter): one revocation
    # touches two aggregates in one transaction — the invitation and the identity it provisioned — each read
    # under a row lock, updated, and with its domain event appended to the event store. Measured, not derived:
    # a drop here means one of the two halves stopped happening.
    And 22 requests got executed only for doctrine connection "default"

  Scenario: The delivered token stops working the moment the invitation is revoked
    Given I am logged in as an administrator
    When I send a DELETE request to "/backoffice/users/0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a70/invitation"
    Then the response status code should be 204
    And I add "Origin" header equal to "http://localhost"
    And I add "X-CSRF-Token" header equal to "behat-stateless-csrf-nonce-000000"
    # The whole point of the lever: the link that was already emailed collapses into the same opaque wall as
    # a forged one, so revocation is not merely a status change in a table nobody consults.
    And I send a POST request to "/backoffice/invitations/accept" with body:
    """
    {
      "token": "0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a90.behat-known-invitation-secret",
      "password": "a-brand-new-password"
    }
    """
    And the response status code should be 400
    And the JSON node "type" should be equal to "invalid-token"
    # The row survives the revocation — deleting it would strand the person id the invitation already put in
    # the reproducible log and in the membership that admitted them.
    And I execute the SQL query "SELECT id FROM identity_user WHERE email = 'iris@erpify.test' AND status = 'REVOKED'"
    And there should have 1 records in SQL result

  Scenario: Revoking again is a 404 — a spent invitation is not revocable
    Given I am logged in as an administrator
    When I send a DELETE request to "/backoffice/users/0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a70/invitation"
    Then the response status code should be 204
    And I send a DELETE request to "/backoffice/users/0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a70/invitation"
    And the response status code should be 404
    And the header "Content-Type" should contain "application/problem+json"
    And the JSON node "type" should be equal to "revocable-invitation-not-found"
    And the JSON node "userId" should be equal to "0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a70"

  Scenario Outline: Nothing revocable is a 404, whether the person is active or unknown
    Given I am logged in as an administrator
    And the stored events are cleared
    When I send a DELETE request to "/backoffice/users/<id>/invitation"
    Then the response status code should be 404
    And the JSON node "type" should be equal to "revocable-invitation-not-found"
    And there should be 0 events stored named "erpify.iam.invitation.revoked"

    # An active member never had an invitation left to pull; a well-formed id naming nobody is the same
    # answer, because the endpoint never confirms whether a person exists.
    Examples:
      | case            | id                                   |
      | active member   | 0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5d |
      | unknown person  | 2e6d865c-17b0-476a-85f2-037bf6d3b3dc |

  Scenario Outline: A malformed user id returns a 400 invalid-uuid Problem Details body
    Given I am logged in as an administrator
    When I send a DELETE request to "/backoffice/users/<id>/invitation"
    Then the response status code should be 400
    And the JSON node "type" should be equal to "invalid-uuid"
    And the JSON node "status" should be equal to the number 400

    Examples:
      | id         |
      | not-a-uuid |
      | 123        |

  Scenario: A viewer is refused the revocation with 403 — users opts out of tier auto-grant
    Given I am logged in as a viewer
    And the stored events are cleared
    When I send a DELETE request to "/backoffice/users/0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a70/invitation"
    Then the response status code should be 403
    And the JSON node "type" should be equal to "forbidden"
    And there should be 0 events stored named "erpify.iam.invitation.revoked"
    And I execute the SQL query "SELECT id FROM iam_invitation WHERE invited_user_id = '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a70' AND status = 'SENT'"
    And there should have 1 records in SQL result

  Scenario: The default audit-reader session is refused the revocation with 403
    When I send a DELETE request to "/backoffice/users/0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a70/invitation"
    Then the response status code should be 403
    And the JSON node "type" should be equal to "forbidden"

  @anonymous
  Scenario: An unauthenticated revocation is a 401, not a 403
    When I send a DELETE request to "/backoffice/users/0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a70/invitation"
    Then the response status code should be 401
    And the JSON node "type" should be equal to "unauthenticated"
