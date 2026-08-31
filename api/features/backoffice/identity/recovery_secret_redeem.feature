Feature: Redeem a recovery secret (the sole administrator's only lockout edge)
  As the holder of a recovery secret
  In order to get back into an account an attacker is holding locked with nothing but its email address
  I need an anonymous endpoint that establishes a session and clears the lockout, and that reveals nothing
  about a secret it refuses

  # The edge #602 is about. Every other recovery path is keyed by the email address the attack already
  # occupies, so this one is keyed by the presented link's selector and by nothing else. Its refusals are
  # deliberately byte-identical: a malformed presentation, an unknown selector, a wrong secret and a drained
  # budget all answer the same 400 invalid-token, because a graded answer would tell an attacker which
  # selectors exist and are worth draining. The first two need no seeded row and share one outline below;
  # the wrong secret needs a live row to compare against, so it is a scenario of its own, and so is the
  # lapsed one. The drained budget is exercised at the unit level
  # (`RedeemRecoverySecretControllerTest`), where a per-selector budget can be set to one without the
  # limiter's window leaking into the rest of the suite.
  Background:
    Given I reload the fixtures
    And I add "Content-Type" header equal to "application/json"
    And I add "Accept" header equal to "application/json"
    And I add "Origin" header equal to "http://localhost"
    And I add "X-CSRF-Token" header equal to "behat-stateless-csrf-nonce-000000"

  Scenario: A valid secret clears a live lockout and establishes a session
    # The lockout is sealed in the FUTURE and then read back before the redemption runs. Without that
    # intermediate SELECT a seed that silently matched no row would let this scenario pass over an identity
    # that was never locked — asserting a transition that had nothing to transition from.
    Given I execute the SQL query "UPDATE identity_user SET failed_attempts = 10, locked_until = '2099-01-01 00:00:00' WHERE email = 'lena@erpify.test'"
    And I execute the SQL query "SELECT id FROM identity_user WHERE email = 'lena@erpify.test' AND locked_until > NOW()"
    And there should have 1 records in SQL result
    And I execute the SQL query "INSERT INTO identity_recovery_secret (id, user_id, secret_hash, expires_at, created_at, updated_at) VALUES ('0190f400-0000-7000-8000-0000000000a1', '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a65', '1eb66bb65a0c3db8c8bc00d65db18d26f8a7aba9e7ed643bbc2bf8ba63b6d70b', '2099-01-01 00:00:00', NOW(), NOW())"
    When I send a POST request to "/backoffice/recovery/redeem" with body:
    """
    {
      "secret": "0190f400-0000-7000-8000-0000000000a1.behat-recovery-secret-plaintext-000000000000"
    }
    """
    Then the response status code should be 204
    # The lockout is gone — both halves, not only the expiry the login wall happens to read.
    #
    # WHAT THIS ASSERTION CANNOT ATTRIBUTE, measured rather than assumed: deleting `clearLockout()` from the
    # use case leaves this green. `ClearLockoutOnLoginSuccess` listens on `LoginSuccessEvent` and clears the
    # counter in its own committed transaction, so the session establishment above already produces this
    # state. What this proves is therefore that the EDGE exists end to end — a locked identity ends unlocked
    # and admitted, which is what #602 needs — never which of the two writers cleared it. The use case keeps
    # its own explicit clear because its contract states it and because a listener two layers away is not
    # something this flow's correctness should rest on; the assertion below is the one only this use case
    # can satisfy.
    And I execute the SQL query "SELECT id FROM identity_user WHERE email = 'lena@erpify.test' AND failed_attempts = 0 AND locked_until IS NULL"
    And there should have 1 records in SQL result
    # The row is retired, so the same presentation cannot be spent twice.
    And I execute the SQL query "SELECT id FROM identity_recovery_secret WHERE id = '0190f400-0000-7000-8000-0000000000a1'"
    And there should have 0 records in SQL result
    # A session was minted for the identity the secret belongs to. This is the half that makes the endpoint
    # a recovery edge rather than an expensive delete.
    And I execute the SQL query "SELECT id FROM iam_session WHERE user_id = '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a65' AND status = 'ACTIVE'"
    And there should have 1 records in SQL result
    # The audit row nothing else writes. It is projected POST-COMMIT and unconditionally on the transaction
    # having committed, so removing the retirement does NOT red it — the `0 records` assertion four lines
    # above is what does, and attributing that red here would send the next reader to the wrong line.
    And I execute the SQL query "SELECT id FROM audit_log WHERE action = 'RECOVERY_SECRET_REDEEMED' AND resource_type = 'User' AND resource_id = '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a65'"
    And there should have 1 records in SQL result

  Scenario: The same secret cannot be redeemed twice
    Given I execute the SQL query "INSERT INTO identity_recovery_secret (id, user_id, secret_hash, expires_at, created_at, updated_at) VALUES ('0190f400-0000-7000-8000-0000000000a2', '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a65', '1eb66bb65a0c3db8c8bc00d65db18d26f8a7aba9e7ed643bbc2bf8ba63b6d70b', '2099-01-01 00:00:00', NOW(), NOW())"
    When I send a POST request to "/backoffice/recovery/redeem" with body:
    """
    {
      "secret": "0190f400-0000-7000-8000-0000000000a2.behat-recovery-secret-plaintext-000000000000"
    }
    """
    Then the response status code should be 204
    # The second presentation of the same link, in the same scenario: a consumed row must answer exactly as
    # a link that never existed.
    And I send a POST request to "/backoffice/recovery/redeem" with body:
    """
    {
      "secret": "0190f400-0000-7000-8000-0000000000a2.behat-recovery-secret-plaintext-000000000000"
    }
    """
    And the response status code should be 400
    And the JSON node "type" should be equal to "invalid-token"

  Scenario Outline: Every death case answers one byte-identical refusal
    # The three spellings differ in what is wrong with them and in nothing a caller can observe. A graded
    # answer here would be an existence oracle over selectors, which are the budget's key.
    #
    # All three die BEFORE `RecoverySecret::verify()` runs — the split fails, the UUID guard refuses, or the
    # selector resolves to no row — so what they pin is the refusal's shape, never the comparison's. The two
    # cases that do reach the comparison are the scenarios below this one, and they exist because the
    # byte-identical claim is worth nothing if it is only ever asserted where nothing is compared.
    When I send a POST request to "/backoffice/recovery/redeem" with body:
    """
    {
      "secret": "<presentation>"
    }
    """
    Then the response status code should be 400
    And the JSON node "type" should be equal to "invalid-token"
    And the JSON node "title" should be equal to "This link is no longer valid."

    Examples:
      | presentation                                                                        |
      | no-separator-at-all                                                                 |
      | not-a-uuid.some-secret                                                              |
      | 0190f400-0000-7000-8000-0000000000ff.some-secret                                    |

  Scenario: A wrong secret against a LIVE selector answers the same refusal and spends nothing
    # The case the outline above cannot reach: here the row exists, the selector resolves and
    # `RecoverySecret::verify()` actually runs a comparison — so this is where "byte-identical" is worth
    # asserting. The row must survive, or a wrong guess would destroy the credential it failed to present.
    Given I execute the SQL query "INSERT INTO identity_recovery_secret (id, user_id, secret_hash, expires_at, created_at, updated_at) VALUES ('0190f400-0000-7000-8000-0000000000a5', '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a65', '1eb66bb65a0c3db8c8bc00d65db18d26f8a7aba9e7ed643bbc2bf8ba63b6d70b', '2099-01-01 00:00:00', NOW(), NOW())"
    And I execute the SQL query "SELECT id FROM identity_recovery_secret WHERE id = '0190f400-0000-7000-8000-0000000000a5'"
    And there should have 1 records in SQL result
    When I send a POST request to "/backoffice/recovery/redeem" with body:
    """
    {
      "secret": "0190f400-0000-7000-8000-0000000000a5.not-the-plaintext-that-was-minted-000000"
    }
    """
    Then the response status code should be 400
    And the JSON node "type" should be equal to "invalid-token"
    And the JSON node "title" should be equal to "This link is no longer valid."
    And I execute the SQL query "SELECT id FROM identity_recovery_secret WHERE id = '0190f400-0000-7000-8000-0000000000a5'"
    And there should have 1 records in SQL result

  Scenario: A lapsed secret answers the same refusal, and expiry does not delete the row
    # The other comparison-reaching case: the presentation is the CORRECT one, so only the expiry can refuse
    # it. The surviving row is the point rather than an afterthought — expiry makes a secret unredeemable and
    # deletes nothing, and no sweep exists, so the row and the person reference in it outlive the capability.
    Given I execute the SQL query "INSERT INTO identity_recovery_secret (id, user_id, secret_hash, expires_at, created_at, updated_at) VALUES ('0190f400-0000-7000-8000-0000000000a6', '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a65', '1eb66bb65a0c3db8c8bc00d65db18d26f8a7aba9e7ed643bbc2bf8ba63b6d70b', '2020-01-01 00:00:00', NOW(), NOW())"
    And I execute the SQL query "SELECT id FROM identity_recovery_secret WHERE id = '0190f400-0000-7000-8000-0000000000a6' AND expires_at < NOW()"
    And there should have 1 records in SQL result
    When I send a POST request to "/backoffice/recovery/redeem" with body:
    """
    {
      "secret": "0190f400-0000-7000-8000-0000000000a6.behat-recovery-secret-plaintext-000000000000"
    }
    """
    Then the response status code should be 400
    And the JSON node "type" should be equal to "invalid-token"
    And the JSON node "title" should be equal to "This link is no longer valid."
    And I execute the SQL query "SELECT id FROM identity_recovery_secret WHERE id = '0190f400-0000-7000-8000-0000000000a6'"
    And there should have 1 records in SQL result

  Scenario: A cross-site POST is refused before the secret is looked at
    Given I add "Origin" header equal to "https://evil.example"
    When I send a POST request to "/backoffice/recovery/redeem" with body:
    """
    {
      "secret": "0190f400-0000-7000-8000-0000000000a3.behat-recovery-secret-plaintext-000000000000"
    }
    """
    Then the response status code should be 403

  Scenario: The endpoint is reachable without a session
    # It has to be: whoever needs it cannot sign in by definition. This asserts the access-control exemption
    # is live rather than assumed — an anonymous caller reaches the application's own refusal, not a 401.
    When I send a POST request to "/backoffice/recovery/redeem" with body:
    """
    {
      "secret": "0190f400-0000-7000-8000-0000000000a4.some-secret"
    }
    """
    Then the response status code should be 400
    And the JSON node "type" should be equal to "invalid-token"

  Scenario: A session that cannot be established leaves the secret entirely intact
    # The partial-failure contract, end to end. `leo` has no membership, so session minting raises and the
    # endpoint answers 503 — the same shape a session-store outage produces. What matters is what did NOT
    # happen: the re-login runs BEFORE the row is retired, so a failure here costs nothing and the holder can
    # try again. Inverted, this would be the worst outcome the design has: secret spent, row gone, and a
    # signed-out sole administrator with nothing left to present.
    Given I execute the SQL query "INSERT INTO identity_recovery_secret (id, user_id, secret_hash, expires_at, created_at, updated_at) VALUES ('0190f400-0000-7000-8000-0000000000a5', '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a64', '1eb66bb65a0c3db8c8bc00d65db18d26f8a7aba9e7ed643bbc2bf8ba63b6d70b', '2099-01-01 00:00:00', NOW(), NOW())"
    When I send a POST request to "/backoffice/recovery/redeem" with body:
    """
    {
      "secret": "0190f400-0000-7000-8000-0000000000a5.behat-recovery-secret-plaintext-000000000000"
    }
    """
    Then the response status code should be 503
    And I execute the SQL query "SELECT id FROM identity_recovery_secret WHERE id = '0190f400-0000-7000-8000-0000000000a5'"
    And there should have 1 records in SQL result
