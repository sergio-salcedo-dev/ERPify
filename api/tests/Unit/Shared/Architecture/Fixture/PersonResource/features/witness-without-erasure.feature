Feature: A witness that watches the write and not the erasure
  # Seeds a row of the type and reads it back, but asserts it is STILL THERE. This is the failure the witness
  # rule exists to catch: a scenario naming the type everywhere, green on every run, testifying to nothing
  # about whether the erasure reaches a row of it.

  Scenario: A row of the type is written and survives
    Given I execute the SQL query "INSERT INTO audit_log (resource_type) VALUES ('FixtureResource')" on connection "seed"
    And I execute the SQL query "SELECT id FROM audit_log WHERE resource_type = 'FixtureResource'"
    Then there should have 1 records in SQL result
