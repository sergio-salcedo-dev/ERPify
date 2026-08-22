Feature: A witness whose only write is commented out
  # The write below is DEAD TEXT. A rule reading raw lines would accept this scenario as seeding a row of the
  # type, so the assertion that follows would be read as an erasure it witnessed — when the table was empty
  # all along. Commenting a step out is how a scenario stops doing something while still naming it.
  # Given I execute the SQL query "INSERT INTO audit_log (resource_type) VALUES ('FixtureResource')" on connection "seed"

  Scenario: The row is never seeded, and the absence is asserted anyway
    Given I execute the SQL query "SELECT id FROM audit_log WHERE resource_type = 'FixtureResource'"
    Then there should have 0 records in SQL result
