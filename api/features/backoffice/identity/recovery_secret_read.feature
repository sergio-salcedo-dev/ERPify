Feature: Read whether my recovery secret is live
  As an administrator
  In order to know whether I still have a way back in if an attacker holds my account locked
  I need the profile read to say whether my recovery secret still signs me in, not merely whether a row exists

  # `live` is the server's rule, the same one redemption applies: an expired row still occupies the slot (a
  # mint over it is refused until it is revoked) yet signs nobody in, so `exists` alone would read as covered
  # over a credential that grants nothing. The three states are seeded as rows rather than minted through the
  # API so each one is reached without spending the per-identity password-proof budget, and each seed is read
  # back so a row that matched nothing cannot let an assertion pass over a state that was never built. The
  # slot is unique per identity, so every scenario starts from the fixtures rather than its predecessor's row.
  Background:
    Given I reload the fixtures
    And I add "Accept" header equal to "application/json"
    And I am logged in as an administrator

  Scenario: An administrator with no recovery secret is told none is live
    Given I execute the SQL query "SELECT id FROM identity_recovery_secret WHERE user_id = '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a66'"
    And there should have 0 records in SQL result
    When I send a "GET" request to "/me/recovery-secret"
    Then the response status code should be 200
    And the JSON node "data.exists" should be false
    And the JSON node "data.live" should be false
    And the JSON node "data.mintedAt" should be null
    And the JSON node "data.expiresAt" should be null

  Scenario: An administrator holding an unexpired recovery secret is told it is live
    Given I execute the SQL query "INSERT INTO identity_recovery_secret (id, user_id, secret_hash, expires_at, created_at, updated_at) VALUES ('0190f400-0000-7000-8000-0000000000c1', '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a66', '1eb66bb65a0c3db8c8bc00d65db18d26f8a7aba9e7ed643bbc2bf8ba63b6d70b', '2099-01-01 00:00:00', NOW(), NOW())"
    And I execute the SQL query "SELECT id FROM identity_recovery_secret WHERE user_id = '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a66' AND expires_at > NOW()"
    And there should have 1 records in SQL result
    When I send a "GET" request to "/me/recovery-secret"
    Then the response status code should be 200
    And the JSON node "data.exists" should be true
    And the JSON node "data.live" should be true
    And the JSON node "data.mintedAt" should not be null
    And the JSON node "data.expiresAt" should contain "2099-01-01"
    # The selector is a denial capability and never travels, live or not.
    And the response should not contain "0190f400-0000-7000-8000-0000000000c1"

  Scenario: An administrator holding a lapsed recovery secret is told it exists but is not live
    Given I execute the SQL query "INSERT INTO identity_recovery_secret (id, user_id, secret_hash, expires_at, created_at, updated_at) VALUES ('0190f400-0000-7000-8000-0000000000c2', '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a66', '1eb66bb65a0c3db8c8bc00d65db18d26f8a7aba9e7ed643bbc2bf8ba63b6d70b', '2020-01-01 00:00:00', '2010-01-01 00:00:00', '2010-01-01 00:00:00')"
    And I execute the SQL query "SELECT id FROM identity_recovery_secret WHERE user_id = '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a66' AND expires_at < NOW()"
    And there should have 1 records in SQL result
    When I send a "GET" request to "/me/recovery-secret"
    Then the response status code should be 200
    And the JSON node "data.exists" should be true
    And the JSON node "data.live" should be false
    And the JSON node "data.expiresAt" should contain "2020-01-01"
