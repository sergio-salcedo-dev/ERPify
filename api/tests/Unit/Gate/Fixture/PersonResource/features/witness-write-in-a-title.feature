Feature: A witness whose only write is text that never runs
  Historically seeded with INSERT INTO audit_log (resource_type) VALUES ('FixtureResource')

  Scenario: An INSERT naming 'FixtureResource' is refused for a viewer
    Given I am logged in as a viewer
    And I execute the SQL query "SELECT id FROM audit_log WHERE resource_type = 'FixtureResource'"
    Then there should have 0 records in SQL result
