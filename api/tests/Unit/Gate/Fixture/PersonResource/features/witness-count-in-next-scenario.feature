Feature: A witness whose query and count belong to different scenarios
  # The query is the last step of the first scenario and the zero count the first step of the second, over a
  # different table. Adjacency alone would pair them; what stops it is that the `Scenario:` line between them
  # is kept, so the step after the query is a heading and not a count.

  Scenario: A row of the type is written and then read
    Given I execute the SQL query "INSERT INTO audit_log (resource_type) VALUES ('FixtureResource')" on connection "seed"
    And I execute the SQL query "SELECT id FROM audit_log WHERE resource_type = 'FixtureResource'"

  Scenario: An unrelated table happens to be empty
    Then there should have 0 records in SQL result
