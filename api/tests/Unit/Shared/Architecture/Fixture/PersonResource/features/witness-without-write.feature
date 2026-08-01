Feature: A witness that asserts an absence it never created
  # Asserts no row of the type survives without ever seeding one, so the assertion holds over an empty table
  # and would hold with the erasure deleted entirely. Vacuous by construction — the precedent this epic paid
  # for is exactly a test that never created the divergence it asserted away.

  Scenario: No row of the type is found, and none was ever written
    Given I execute the SQL query "SELECT id FROM audit_log WHERE resource_type = 'FixtureResource'"
    Then there should have 0 records in SQL result
