Feature: Enrol an optional two-step verification authenticator
  As a signed-in administrator
  In order to stop a stolen password from being enough to open my account
  I need to start a TOTP enrolment behind my current password, be shown its secret once, and confirm it with a code

  # A code computed from the secret cannot be produced from this vocabulary, so the successful confirmation and what
  # follows it (sessions revoked, the device signed back in, the owner mailed) are proven by
  # `TotpEnrolmentFunctionalTest`; these scenarios hold the refusals and the enrolment itself. The factor slot is one
  # per identity, so every scenario restores the fixtures (which hold no factor) and then signs in, because the
  # restore drops the session the suite seated before the first step.
  Background:
    Given I reload the fixtures
    And I add "Content-Type" header equal to "application/json"
    And I add "Accept" header equal to "application/json"
    And I am logged in as an administrator

  Scenario: A wrong current password enrols nothing
    When I send a POST request to "/me/second-factor/totp" with body:
    """
    { "currentPassword": "not-the-admin-password" }
    """
    Then the response status code should be 403
    And the response should contain "invalid-current-password"
    And I execute the SQL query "SELECT id FROM identity_totp_factor WHERE user_id = '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a66'"
    And there should have 0 records in SQL result

  Scenario: A proven enrolment shows the secret once, uncached, and leaves a pending factor that holds only ciphertext
    When I send a POST request to "/me/second-factor/totp" with body:
    """
    { "currentPassword": "admin-password" }
    """
    Then the response status code should be 201
    And the header "Cache-Control" should contain "no-store"
    And the JSON node "data.secret" should match "/^[A-Z2-7]{32}$/"
    And the JSON node "data.provisioningUri" should contain "otpauth://totp/ERPify"
    And I execute the SQL query "SELECT id FROM identity_totp_factor WHERE user_id = '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a66' AND enabled_at IS NULL AND failed_confirm_attempts = 0"
    And there should have 1 records in SQL result
    # The trail records THAT an enrolment started, with nothing about the secret.
    And I execute the SQL query "SELECT id FROM audit_log WHERE action = 'SECOND_FACTOR_ENROLMENT_STARTED' AND level = 'security' AND resource_type = 'User' AND resource_id = '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a66'"
    And there should have 1 records in SQL result

  Scenario: A malformed code is refused, counted against the enrolment, and the factor stays pending
    Given I send a POST request to "/me/second-factor/totp" with body:
    """
    { "currentPassword": "admin-password" }
    """
    And the response status code should be 201
    When I send a POST request to "/me/second-factor/totp/confirm" with body:
    """
    { "code": "abcdef" }
    """
    Then the response status code should be 403
    And the response should contain "invalid-second-factor-code"
    And I execute the SQL query "SELECT id FROM identity_totp_factor WHERE user_id = '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a66' AND enabled_at IS NULL AND failed_confirm_attempts = 1"
    And there should have 1 records in SQL result

  Scenario: Confirming with nothing enrolled is a conflict
    When I send a POST request to "/me/second-factor/totp/confirm" with body:
    """
    { "code": "123456" }
    """
    Then the response status code should be 409
    And the response should contain "second-factor-not-enrolled"

  Scenario: A pending enrolment older than ten minutes answers like no enrolment at all
    Given I execute the SQL query "INSERT INTO identity_totp_factor (id, user_id, scope_id, sealed_secret, enrolment_started_at, failed_confirm_attempts, enabled_at, last_accepted_step, created_at, updated_at) VALUES ('0190f700-0000-7000-8000-0000000000a1', '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a66', '0190f700-0000-7000-8000-0000000000a2', 'never-opened', NOW() - INTERVAL '11 minutes', 0, NULL, NULL, NOW(), NOW())"
    And I execute the SQL query "SELECT id FROM identity_totp_factor WHERE id = '0190f700-0000-7000-8000-0000000000a1'"
    And there should have 1 records in SQL result
    When I send a POST request to "/me/second-factor/totp/confirm" with body:
    """
    { "code": "123456" }
    """
    Then the response status code should be 409
    And the response should contain "second-factor-not-enrolled"

  Scenario: A pending enrolment that has refused five codes answers like no enrolment at all
    Given I execute the SQL query "INSERT INTO identity_totp_factor (id, user_id, scope_id, sealed_secret, enrolment_started_at, failed_confirm_attempts, enabled_at, last_accepted_step, created_at, updated_at) VALUES ('0190f700-0000-7000-8000-0000000000b1', '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a66', '0190f700-0000-7000-8000-0000000000b2', 'never-opened', NOW(), 5, NULL, NULL, NOW(), NOW())"
    And I execute the SQL query "SELECT id FROM identity_totp_factor WHERE id = '0190f700-0000-7000-8000-0000000000b1'"
    And there should have 1 records in SQL result
    When I send a POST request to "/me/second-factor/totp/confirm" with body:
    """
    { "code": "123456" }
    """
    Then the response status code should be 409
    And the response should contain "second-factor-not-enrolled"
