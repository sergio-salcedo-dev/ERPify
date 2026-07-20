@anonymous
Feature: Accept an invitation
  As a person invited to ERPify
  In order to become operational without a public sign-up
  I need one public write that sets my password, admits my identity and establishes my session — while every
  dead token collapses to a single opaque wall that never reveals why it stopped working

  Background:
    # The invitation token is single-use: each scenario consumes or probes it, so restore the seeded SENT
    # invitation before every scenario (the suite restores only per-feature otherwise).
    Given I reload the fixtures
    And I add "Content-Type" header equal to "application/json"
    And I add "Accept" header equal to "application/json"
    And I add "Origin" header equal to "http://localhost"
    And I add "X-CSRF-Token" header equal to "behat-stateless-csrf-nonce-000000"

  Scenario: A valid token activates the identity, retires the invitation and establishes a session
    When I send a POST request to "/backoffice/invitations/accept" with body:
    """
    {
      "token": "0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a90.behat-known-invitation-secret",
      "password": "a-brand-new-password"
    }
    """
    Then the response status code should be 204
    And the response should be empty
    And the header "Set-Cookie" should contain "httponly"
    And I execute the SQL query "SELECT id FROM identity_user WHERE email = 'iris@erpify.test' AND status = 'ACTIVE'"
    And there should have 1 records in SQL result
    And I execute the SQL query "SELECT id FROM iam_invitation WHERE status = 'ACCEPTED'"
    And there should have 1 records in SQL result

  Scenario: A wrong secret is refused with an opaque invalid-token, minting no session
    When I send a POST request to "/backoffice/invitations/accept" with body:
    """
    {
      "token": "0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a90.the-wrong-secret",
      "password": "a-brand-new-password"
    }
    """
    Then the response status code should be 400
    And the header "Content-Type" should contain "application/problem+json"
    And the JSON node "type" should be equal to "invalid-token"
    And the header "Set-Cookie" should not exist

  Scenario: A non-existent invitation is the SAME opaque invalid-token, never revealing existence
    When I send a POST request to "/backoffice/invitations/accept" with body:
    """
    {
      "token": "0190ffff-ffff-7fff-8fff-ffffffffffff.any-secret",
      "password": "a-brand-new-password"
    }
    """
    Then the response status code should be 400
    And the JSON node "type" should be equal to "invalid-token"

  Scenario: A malformed token is the SAME opaque invalid-token
    When I send a POST request to "/backoffice/invitations/accept" with body:
    """
    {
      "token": "not-a-valid-token",
      "password": "a-brand-new-password"
    }
    """
    Then the response status code should be 400
    And the JSON node "type" should be equal to "invalid-token"

  Scenario: Re-presenting an already-accepted token is the SAME opaque invalid-token
    When I send a POST request to "/backoffice/invitations/accept" with body:
    """
    {
      "token": "0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a90.behat-known-invitation-secret",
      "password": "a-brand-new-password"
    }
    """
    Then the response status code should be 204
    And I send a POST request to "/backoffice/invitations/accept" with body:
    """
    {
      "token": "0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a90.behat-known-invitation-secret",
      "password": "another-password"
    }
    """
    And the response status code should be 400
    And the JSON node "type" should be equal to "invalid-token"

  Scenario: A cross-site accept is refused before mutating, leaving the identity INVITED
    Given I add "Origin" header equal to "https://evil.example"
    When I send a POST request to "/backoffice/invitations/accept" with body:
    """
    {
      "token": "0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a90.behat-known-invitation-secret",
      "password": "a-brand-new-password"
    }
    """
    Then the response status code should be 403
    And the JSON node "type" should be equal to "forbidden"
    And I execute the SQL query "SELECT id FROM identity_user WHERE email = 'iris@erpify.test' AND status = 'INVITED'"
    And there should have 1 records in SQL result

  Scenario: A failed accept emits no InvitationAccepted event and mutates nothing
    Given the stored events are cleared
    When I send a POST request to "/backoffice/invitations/accept" with body:
    """
    {
      "token": "0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a90.the-wrong-secret",
      "password": "a-brand-new-password"
    }
    """
    Then the response status code should be 400
    And there should be 0 events stored named "erpify.iam.invitation.accepted"

  Scenario: A successful accept emits exactly one InvitationAccepted event
    Given the stored events are cleared
    When I send a POST request to "/backoffice/invitations/accept" with body:
    """
    {
      "token": "0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a90.behat-known-invitation-secret",
      "password": "a-brand-new-password"
    }
    """
    Then the response status code should be 204
    And there should be 1 event stored named "erpify.iam.invitation.accepted"

  Scenario: A saturated selector folds a LIVE invitation link into the same opaque invalid-token wall
    Given the token-action budget is exhausted for selector "0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a90"
    When I send a POST request to "/backoffice/invitations/accept" with body:
    """
    {
      "token": "0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a90.behat-known-invitation-secret",
      "password": "a-brand-new-password"
    }
    """
    Then the response status code should be 400
    And the JSON node "type" should be equal to "invalid-token"
    And the header "Set-Cookie" should not exist
    And I execute the SQL query "SELECT id FROM identity_user WHERE email = 'iris@erpify.test' AND status = 'INVITED'"
    And there should have 1 records in SQL result

  Scenario: An accept with no CSRF token header is refused before the token is even read
    Given I remove "X-CSRF-Token" header
    When I send a POST request to "/backoffice/invitations/accept" with body:
    """
    {
      "token": "0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a90.behat-known-invitation-secret",
      "password": "a-brand-new-password"
    }
    """
    Then the response status code should be 401
    And the JSON node "type" should be equal to "unauthenticated"
    And the header "Set-Cookie" should not exist
    And I execute the SQL query "SELECT id FROM identity_user WHERE email = 'iris@erpify.test' AND status = 'INVITED'"
    And there should have 1 records in SQL result
