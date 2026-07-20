@anonymous
Feature: Reset a forgotten password uniformly
  As a user who lost access
  In order to regain access without leaking whether an account exists
  I need a forgot request that answers identically for any email and a reset that consumes a single-use link,
  establishes a fresh session, revokes every prior one and clears any lockout

  Background:
    Given I add "Content-Type" header equal to "application/json"
    And I add "Accept" header equal to "application/json"
    And I add "Origin" header equal to "http://localhost"
    And I add "X-CSRF-Token" header equal to "behat-stateless-csrf-nonce-000000"

  Scenario: A cross-site forgot request is refused before any work
    Given I add "Origin" header equal to "https://evil.example"
    When I send a POST request to "/backoffice/forgot-password" with body:
    """
    { "email": "alice@erpify.test" }
    """
    Then the response status code should be 403
    And the header "Content-Type" should contain "application/problem+json"
    And the JSON node "type" should be equal to "forbidden"

  Scenario: Forgot answers identically whether the account exists or not
    When I send a POST request to "/backoffice/forgot-password" with body:
    """
    { "email": "alice@erpify.test" }
    """
    Then the response status code should be 202
    And the response should be empty
    And I send a POST request to "/backoffice/forgot-password" with body:
    """
    { "email": "nobody@erpify.test" }
    """
    And the response status code should be 202
    And the response should be empty

  Scenario: A forgot for an active account records the request and mints exactly one token; an unknown one records nothing
    Given the stored events are cleared
    When I send a POST request to "/backoffice/forgot-password" with body:
    """
    { "email": "alice@erpify.test" }
    """
    Then the response status code should be 202
    And there should be 1 event stored named "erpify.iam.identity.password-reset-requested"
    And I execute the SQL query "SELECT id FROM identity_password_reset_token WHERE user_id = '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b'"
    And there should have 1 records in SQL result
    And the stored events are cleared
    And I send a POST request to "/backoffice/forgot-password" with body:
    """
    { "email": "nobody@erpify.test" }
    """
    And the response status code should be 202
    And there should be 0 events stored named "erpify.iam.identity.password-reset-requested"

  Scenario: A valid token for a suspended identity is walled and not consumed
    Given I execute the SQL query "INSERT INTO identity_password_reset_token (id, user_id, token_hash, expires_at, created_at, updated_at) VALUES ('0190e1f2-a3b4-7c5d-8e6f-1a2b3c4d5e03', '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a61', '293faa10ffcf0a61669a7b47a1761ae5a07bfecfbbc2e69e3f90a2723c287948', NOW() + INTERVAL '1 hour', NOW(), NOW())"
    When I send a POST request to "/backoffice/reset-password" with body:
    """
    { "token": "0190e1f2-a3b4-7c5d-8e6f-1a2b3c4d5e03.behat-suspended-reset-secret", "password": "brand-new-strong-password" }
    """
    Then the response status code should be 403
    And the header "Content-Type" should contain "application/problem+json"
    And the JSON node "type" should be equal to "account-suspended"
    And the header "Set-Cookie" should not exist
    And I execute the SQL query "SELECT id FROM identity_password_reset_token WHERE id = '0190e1f2-a3b4-7c5d-8e6f-1a2b3c4d5e03'"
    And there should have 1 records in SQL result

  Scenario: A valid token for a deactivated identity is walled by a specific 403
    Given I execute the SQL query "INSERT INTO identity_password_reset_token (id, user_id, token_hash, expires_at, created_at, updated_at) VALUES ('0190e1f2-a3b4-7c5d-8e6f-1a2b3c4d5e04', '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a62', '3d13deaeba930a5a7b3e4be6a0ec97cf75b569cef039df85f2d29d972610a022', NOW() + INTERVAL '1 hour', NOW(), NOW())"
    When I send a POST request to "/backoffice/reset-password" with body:
    """
    { "token": "0190e1f2-a3b4-7c5d-8e6f-1a2b3c4d5e04.behat-deactivated-reset-secret", "password": "brand-new-strong-password" }
    """
    Then the response status code should be 403
    And the JSON node "type" should be equal to "account-deactivated"

  Scenario: An expired, an unknown, a malformed and a non-uuid token are one indistinguishable invalid-token
    Given I execute the SQL query "INSERT INTO identity_password_reset_token (id, user_id, token_hash, expires_at, created_at, updated_at) VALUES ('0190e1f2-a3b4-7c5d-8e6f-1a2b3c4d5e02', '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b', '6087b5a9916bc298714197654465b32cc8c5ba37701db4efee880fcd9972e502', NOW() - INTERVAL '1 hour', NOW(), NOW())"
    When I send a POST request to "/backoffice/reset-password" with body:
    """
    { "token": "0190e1f2-a3b4-7c5d-8e6f-1a2b3c4d5e02.behat-expired-reset-secret", "password": "brand-new-strong-password" }
    """
    Then the response status code should be 400
    And the header "Content-Type" should contain "application/problem+json"
    And the JSON node "type" should be equal to "invalid-token"
    And I send a POST request to "/backoffice/reset-password" with body:
    """
    { "token": "0190e1f2-a3b4-7c5d-8e6f-1a2b3c4d5eff.some-unknown-secret", "password": "brand-new-strong-password" }
    """
    And the response status code should be 400
    And the JSON node "type" should be equal to "invalid-token"
    And I send a POST request to "/backoffice/reset-password" with body:
    """
    { "token": "not-a-selector-verifier-shape", "password": "brand-new-strong-password" }
    """
    And the response status code should be 400
    And the JSON node "type" should be equal to "invalid-token"
    And I send a POST request to "/backoffice/reset-password" with body:
    """
    { "token": "not-a-uuid-selector.some-secret", "password": "brand-new-strong-password" }
    """
    And the response status code should be 400
    And the JSON node "type" should be equal to "invalid-token"

  Scenario: A failed reset records no events and mutates nothing
    Given the stored events are cleared
    When I send a POST request to "/backoffice/reset-password" with body:
    """
    { "token": "0190e1f2-a3b4-7c5d-8e6f-1a2b3c4d5eee.some-unknown-secret", "password": "brand-new-strong-password" }
    """
    Then the response status code should be 400
    And there should be 0 events stored named "erpify.iam.identity.password-reset-completed"
    And there should be 0 events stored named "erpify.iam.session.all-revoked"

  Scenario: A valid reset changes the password, clears the lock, revokes every prior session and signs the user in
    Given I reload the fixtures
    And I execute the SQL query "UPDATE identity_user SET failed_attempts = 10, locked_until = '2099-01-01 00:00:00' WHERE email = 'alice@erpify.test'"
    And I execute the SQL query "INSERT INTO identity_password_reset_token (id, user_id, token_hash, expires_at, created_at, updated_at) VALUES ('0190e1f2-a3b4-7c5d-8e6f-1a2b3c4d5e01', '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b', '0b3922c421ab825d3cf5b869305ae41cf748c37316a740c3a70083baa639ab90', NOW() + INTERVAL '1 hour', NOW(), NOW())"
    And the stored events are cleared
    When I send a POST request to "/backoffice/reset-password" with body:
    """
    { "token": "0190e1f2-a3b4-7c5d-8e6f-1a2b3c4d5e01.behat-valid-reset-secret", "password": "brand-new-strong-password" }
    """
    Then the response status code should be 204
    And the header "Set-Cookie" should contain "httponly"
    And there should be 1 event stored named "erpify.iam.identity.password-reset-completed"
    And there should be 1 event stored named "erpify.iam.session.all-revoked"
    And I execute the SQL query "SELECT id FROM identity_user WHERE email = 'alice@erpify.test' AND failed_attempts = 0 AND locked_until IS NULL"
    And there should have 1 records in SQL result
    And I execute the SQL query "SELECT id FROM iam_session WHERE user_id = '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b' AND status = 'ACTIVE'"
    And there should have 1 records in SQL result
    And I send a POST request to "/backoffice/login" with body:
    """
    { "email": "alice@erpify.test", "password": "brand-new-strong-password" }
    """
    And the response status code should be 204
    And I send a POST request to "/backoffice/reset-password" with body:
    """
    { "token": "0190e1f2-a3b4-7c5d-8e6f-1a2b3c4d5e01.behat-valid-reset-secret", "password": "another-strong-password" }
    """
    And the response status code should be 400
    And the JSON node "type" should be equal to "invalid-token"

  Scenario: A saturated forgot for an existing account still answers the uniform 202 with the work silenced
    Given the stored events are cleared
    And the password-recovery budget is exhausted for email "alice@erpify.test"
    When I send a POST request to "/backoffice/forgot-password" with body:
    """
    { "email": "alice@erpify.test" }
    """
    Then the response status code should be 202
    And the response should be empty
    And there should be 0 events stored named "erpify.iam.identity.password-reset-requested"

  Scenario: A saturated forgot for an unknown account answers identically
    Given the stored events are cleared
    And the password-recovery budget is exhausted for email "nobody@erpify.test"
    When I send a POST request to "/backoffice/forgot-password" with body:
    """
    { "email": "nobody@erpify.test" }
    """
    Then the response status code should be 202
    And the response should be empty
    And there should be 0 events stored named "erpify.iam.identity.password-reset-requested"

  Scenario: A saturated selector folds a live reset link into the same opaque invalid-token wall
    Given I execute the SQL query "INSERT INTO identity_password_reset_token (id, user_id, token_hash, expires_at, created_at, updated_at) VALUES ('0190e1f2-a3b4-7c5d-8e6f-1a2b3c4d5e05', '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b', '0b3922c421ab825d3cf5b869305ae41cf748c37316a740c3a70083baa639ab90', NOW() + INTERVAL '1 hour', NOW(), NOW())"
    And the token-action budget is exhausted for selector "0190e1f2-a3b4-7c5d-8e6f-1a2b3c4d5e05"
    When I send a POST request to "/backoffice/reset-password" with body:
    """
    { "token": "0190e1f2-a3b4-7c5d-8e6f-1a2b3c4d5e05.behat-valid-reset-secret", "password": "brand-new-strong-password" }
    """
    Then the response status code should be 400
    And the JSON node "type" should be equal to "invalid-token"
    And the header "Set-Cookie" should not exist
    And I execute the SQL query "SELECT id FROM identity_password_reset_token WHERE id = '0190e1f2-a3b4-7c5d-8e6f-1a2b3c4d5e05'"
    And there should have 1 records in SQL result
