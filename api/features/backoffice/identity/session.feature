Feature: Server-side session registry and admission gate
  As the session subsystem
  In order that "authenticated" means "has a live, revocable session"
  I need login to mint a registry session, the gate to force re-login once it is revoked or expired, and the
  owner to read and revoke their own sessions

  Background:
    Given I add "Accept" header equal to "application/json"

  Scenario: The signed-in user reads their own identity and real roles
    When I send a "GET" request to "/me"
    Then the response status code should be 200
    And the JSON node "data.id" should be equal to "0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b"
    And the JSON node "data.email" should be equal to "alice@erpify.test"
    And the JSON node "data.roles" should have 2 elements
    And the JSON node "data.roles[0]" should be equal to "AUDIT_READER"
    And the JSON node "data.roles[1]" should be equal to "MANAGER"

  Scenario: My sessions lists the current device, distinguished
    When I send a "GET" request to "/sessions"
    Then the response status code should be 200
    And the JSON node "data" should have 1 elements
    And the JSON node "data[0].current" should be true
    And the JSON node "data[0].device" should be equal to "Behat test client"

  Scenario: Signing out other devices revokes them but never the current session
    Given I execute the SQL query "INSERT INTO iam_session (id, user_id, organization_id, status, expires_at, last_seen_at, device, created_at, updated_at) VALUES ('0190d1e2-f3a4-7b5c-8d6e-1f2a3b4c5dff', '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b', '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a50', 'ACTIVE', NOW() + INTERVAL '1 day', NOW(), 'A second device', NOW(), NOW())"
    And I send a "GET" request to "/sessions"
    And the JSON node "data" should have 2 elements
    When I send a "POST" request to "/sessions/revoke-others"
    Then the response status code should be 204
    And I send a "GET" request to "/sessions"
    And the response status code should be 200
    And the JSON node "data" should have 1 elements
    And the JSON node "data[0].current" should be true

  Scenario: A revoked session is inert on its next request
    Given I send a "GET" request to "/me"
    And the response status code should be 200
    When I execute the SQL query "UPDATE iam_session SET status = 'REVOKED' WHERE id = '0190d1e2-f3a4-7b5c-8d6e-1f2a3b4c5d01'"
    And I send a "GET" request to "/me"
    Then the response status code should be 401
    And the header "Content-Type" should contain "application/problem+json"
    And the JSON node "type" should be equal to "session-expired"

  Scenario: A time-expired session is inert on its next request
    When I execute the SQL query "UPDATE iam_session SET expires_at = NOW() - INTERVAL '1 hour' WHERE id = '0190d1e2-f3a4-7b5c-8d6e-1f2a3b4c5d01'"
    And I send a "GET" request to "/me"
    Then the response status code should be 401
    And the JSON node "type" should be equal to "session-expired"

  @anonymous
  Scenario: A successful login mints a live registry session the gate then admits
    Given I add "Content-Type" header equal to "application/json"
    And I add "Origin" header equal to "http://localhost"
    When I send a POST request to "/backoffice/login" with body:
    """
    {
      "email": "alice@erpify.test",
      "password": "alice-password"
    }
    """
    Then the response status code should be 204
    # The minted session carries the request through the gate: without a live registry row the next request
    # would 401, so reaching /me proves login established a real, gate-admissible session.
    And I send a "GET" request to "/me"
    And the response status code should be 200
    And the JSON node "data.email" should be equal to "alice@erpify.test"
