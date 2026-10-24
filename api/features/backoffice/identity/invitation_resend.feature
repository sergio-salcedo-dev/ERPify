Feature: Resend a pending invitation from the console
  As an administrator
  In order to get a working accept link to a person whose email was lost, expired or went astray
  I need one authenticated action, keyed by the invited person, that re-tokenises their live invitation, kills
  the previous link and emails the new one — without a container shell

  # ADMIN-only (users.resendInvitation). Keyed by the USER for the reason the revocation is: the console operates
  # on a row of the users register, which is owned by Identity and knows nothing of iam_invitation. The fresh
  # accept token travels only in the email; the response is bodyless.
  Background:
    # The seeded invitation's digest is replaced by the scenarios below, so restore it before each one.
    # The action submits no representation, so no Content-Type: only the accept call below carries a body.
    Given I reload the fixtures
    And I add "Accept" header equal to "application/json"

  Scenario: An administrator resends a member's pending invitation
    Given I am logged in as an administrator
    And the stored events are cleared
    When I send a POST request to "/backoffice/users/0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a70/invitation/resend"
    Then the response status code should be 204
    And the response should be empty
    # The invitation stays live, and so does the person: a resend changes the link, never the lifecycle.
    And I execute the SQL query "SELECT id FROM iam_invitation WHERE invited_user_id = '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a70' AND status = 'SENT'"
    And there should have 1 records in SQL result
    And I execute the SQL query "SELECT id FROM identity_user WHERE id = '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a70' AND status = 'INVITED'"
    And there should have 1 records in SQL result
    And there should be 1 event stored named "erpify.iam.invitation.resent"
    # Invitation events are deliberately unrouted — no realtime consumer — so the write leaves async empty.
    And 0 outbox events were created on the queue "async"
    And 1 notification email was sent
    And The notification email subject should be equal to "Your ERPify invitation"
    And The notification email recipient should be "iris@erpify.test"

  Scenario: The previously delivered link stops working the moment the invitation is resent
    Given I am logged in as an administrator
    When I send a POST request to "/backoffice/users/0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a70/invitation/resend"
    Then the response status code should be 204
    And I add "Content-Type" header equal to "application/json"
    And I add "Origin" header equal to "http://localhost"
    And I add "X-CSRF-Token" header equal to "behat-stateless-csrf-nonce-000000"
    # The seeded secret was the digest the resend replaced, so the old link collapses into the same opaque wall
    # as a forged one — and the invitee stays pending, holding only the link the email carried.
    And I send a POST request to "/backoffice/invitations/accept" with body:
    """
    {
      "token": "0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a90.behat-known-invitation-secret",
      "password": "a-brand-new-password"
    }
    """
    And the response status code should be 400
    And the JSON node "type" should be equal to "invalid-token"
    And I execute the SQL query "SELECT id FROM identity_user WHERE email = 'iris@erpify.test' AND status = 'INVITED'"
    And there should have 1 records in SQL result

  Scenario: A revoked invitation is not resendable
    Given I am logged in as an administrator
    When I send a DELETE request to "/backoffice/users/0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a70/invitation"
    Then the response status code should be 204
    And the stored events are cleared
    And I send a POST request to "/backoffice/users/0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a70/invitation/resend"
    And the response status code should be 404
    And the header "Content-Type" should contain "application/problem+json"
    And the JSON node "type" should be equal to "resendable-invitation-not-found"
    And the JSON node "userId" should be equal to "0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a70"
    And there should be 0 events stored named "erpify.iam.invitation.resent"
    And 0 notification emails were sent

  Scenario Outline: Nothing resendable is a 404, whether the person is active, uninvited or unknown
    Given I am logged in as an administrator
    And the stored events are cleared
    When I send a POST request to "/backoffice/users/<id>/invitation/resend"
    Then the response status code should be 404
    And the JSON node "type" should be equal to "resendable-invitation-not-found"
    And there should be 0 events stored named "erpify.iam.invitation.resent"
    And 0 notification emails were sent

    # An active member has no invitation left to re-deliver; ingrid is INVITED with no invitation row at all;
    # a well-formed id naming nobody is the same answer, because the endpoint never confirms who exists.
    Examples:
      | case             | id                                   |
      | active member    | 0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5d |
      | no invitation    | 0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a60 |
      | unknown person   | 2e6d865c-17b0-476a-85f2-037bf6d3b3dc |

  Scenario Outline: A malformed user id returns a 400 invalid-uuid Problem Details body
    Given I am logged in as an administrator
    When I send a POST request to "/backoffice/users/<id>/invitation/resend"
    Then the response status code should be 400
    And the JSON node "type" should be equal to "invalid-uuid"
    And the JSON node "status" should be equal to the number 400
    And 0 notification emails were sent

    Examples:
      | id         |
      | not-a-uuid |
      | 123        |

  Scenario: A viewer is refused the resend with 403 — users opts out of tier auto-grant
    Given I am logged in as a viewer
    And the stored events are cleared
    When I send a POST request to "/backoffice/users/0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a70/invitation/resend"
    Then the response status code should be 403
    And the JSON node "type" should be equal to "forbidden"
    And there should be 0 events stored named "erpify.iam.invitation.resent"
    And 0 notification emails were sent

  Scenario: The default audit-reader session is refused the resend with 403
    When I send a POST request to "/backoffice/users/0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a70/invitation/resend"
    Then the response status code should be 403
    And the JSON node "type" should be equal to "forbidden"

  @anonymous
  Scenario: An unauthenticated resend is a 401, not a 403
    When I send a POST request to "/backoffice/users/0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a70/invitation/resend"
    Then the response status code should be 401
    And the JSON node "type" should be equal to "unauthenticated"
