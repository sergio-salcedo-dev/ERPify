Feature: Unlock an identity's persisted lockout (administrative recovery)
  As an administrator
  In order to recover an identity nothing else can reach — an attacker who knows only the target's email can
  hold both the login and the password-reset recovery edges shut
  I need a gated endpoint that clears a lockout, reports whether it actually changed anything, and can never be
  turned on the acting administrator's own identity

  # ADMIN-only (users.unlock). POST, not PATCH — there is no resource representation to submit, only the intent
  # to clear whatever lockout the target currently holds. Wraps the already-idempotent
  # `User::clearLockout()`, so a call against an identity that was never locked is still a 200, distinguished
  # from a genuine recovery by `data.unlocked`. The audit row is written either way — the lever being invoked
  # is itself the fact worth keeping, not only a successful recovery. An administrator may never target their
  # own identity: 409 self-unlock-forbidden, refused before any row is touched.
  Background:
    Given I add "Accept" header equal to "application/json"

  Scenario: An administrator unlocks a genuinely locked identity
    Given I am logged in as an administrator
    And I execute the SQL query "UPDATE identity_user SET failed_attempts = 10, locked_until = '2099-01-01 00:00:00' WHERE email = 'leo@erpify.test'"
    When I send a "POST" request to "/backoffice/users/0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a64/unlock"
    Then the response status code should be 200
    And the JSON node "data.id" should be equal to "0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a64"
    And the JSON node "data.email" should be equal to "leo@erpify.test"
    And the JSON node "data.unlocked" should be true
    # The counter and the expiry are both cleared, not merely the one the login wall reads.
    And I execute the SQL query "SELECT id FROM identity_user WHERE id = '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a64' AND failed_attempts = 0 AND locked_until IS NULL"
    And there should have 1 records in SQL result
    And I execute the SQL query "SELECT action, level, actor_type, actor_id, resource_type, resource_id FROM audit_log WHERE correlation_id = '<correlationId>' AND action = 'ACCOUNT_UNLOCKED_BY_ADMIN'"
    And the SQL result as JSON should be:
    """
    [
      {
        "action": "ACCOUNT_UNLOCKED_BY_ADMIN",
        "level": "security",
        "actor_type": "user",
        "actor_id": "0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a66",
        "resource_type": "User",
        "resource_id": "0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a64"
      }
    ]
    """
    And I execute the SQL query "SELECT id FROM audit_log WHERE correlation_id = '<correlationId>' AND action = 'ACCOUNT_UNLOCKED_BY_ADMIN' AND metadata = jsonb_build_object('unlocked', true)"
    And there should have 1 records in SQL result

  Scenario: An administrator unlocks an already-unlocked identity — reports no mutation, still audits the call
    Given I am logged in as an administrator
    When I send a "POST" request to "/backoffice/users/0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5d/unlock"
    Then the response status code should be 200
    And the JSON node "data.id" should be equal to "0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5d"
    And the JSON node "data.unlocked" should be false
    # The lever was invoked and is worth recording regardless of effect — an administrator reaching for
    # `users.unlock` against an account that turns out not to be locked is exactly the use this trail exists
    # to make reviewable, not only a successful recovery.
    And I execute the SQL query "SELECT id FROM audit_log WHERE correlation_id = '<correlationId>' AND action = 'ACCOUNT_UNLOCKED_BY_ADMIN' AND resource_id = '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5d' AND metadata = jsonb_build_object('unlocked', false)"
    And there should have 1 records in SQL result

  @anonymous
  Scenario: An unauthenticated unlock is a 401, not a 403
    When I send a "POST" request to "/backoffice/users/0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5d/unlock"
    Then the response status code should be 401
    And the JSON node "type" should be equal to "unauthenticated"

  Scenario: A viewer is refused the unlock with 403 — users opts out of tier auto-grant
    Given I am logged in as a viewer
    When I send a "POST" request to "/backoffice/users/0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5f/unlock"
    Then the response status code should be 403
    And the JSON node "type" should be equal to "forbidden"

  Scenario: The default audit-reader session is refused the unlock with 403 — unlock is ADMIN-only
    When I send a "POST" request to "/backoffice/users/0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5f/unlock"
    Then the response status code should be 403
    And the JSON node "type" should be equal to "forbidden"

  Scenario: An administrator cannot unlock their own account — 409 self-unlock-forbidden
    Given I am logged in as an administrator
    And I execute the SQL query "UPDATE identity_user SET failed_attempts = 10, locked_until = '2099-01-01 00:00:00' WHERE email = 'admin@erpify.test'"
    When I send a "POST" request to "/backoffice/users/0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a66/unlock"
    Then the response status code should be 409
    And the header "Content-Type" should be equal to "application/problem+json"
    And the JSON node "type" should be equal to "self-unlock-forbidden"
    # Refused before any row is touched: the lockout state seeded above survives untouched.
    And I execute the SQL query "SELECT id FROM identity_user WHERE id = '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a66' AND failed_attempts = 10 AND locked_until IS NOT NULL"
    And there should have 1 records in SQL result
    And I execute the SQL query "SELECT id FROM audit_log WHERE action = 'ACCOUNT_UNLOCKED_BY_ADMIN' AND resource_id = '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a66'"
    And there should have 0 records in SQL result

  Scenario: Self-unlock is refused even when the admin spells their own id in a different case
    Given I am logged in as an administrator
    When I send a "POST" request to "/backoffice/users/0190A1B2-C3D4-7E5F-8A9B-0C1D2E3F4A66/unlock"
    Then the response status code should be 409
    And the JSON node "type" should be equal to "self-unlock-forbidden"

  Scenario Outline: A malformed id returns a 400 invalid-uuid Problem Details body
    Given I am logged in as an administrator
    When I send a "POST" request to "/backoffice/users/<id>/unlock"
    Then the response status code should be 400
    And the JSON node "type" should be equal to "invalid-uuid"
    And the JSON node "status" should be equal to the number 400

    Examples:
      | id         |
      | not-a-uuid |
      | 123        |

  Scenario: Unlocking a well-formed but unknown id is a 404 user-not-found
    Given I am logged in as an administrator
    When I send a "POST" request to "/backoffice/users/2e6d865c-17b0-476a-85f2-037bf6d3b3dc/unlock"
    Then the response status code should be 404
    And the JSON node "type" should be equal to "user-not-found"
