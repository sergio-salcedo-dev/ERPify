Feature: A witness whose seed inserts nothing
  # `INSERT … SELECT` inserts whatever the query returns, which over an empty table is zero rows. The
  # scenario then asserts an absence it never created, and passes for the same reason it would pass with the
  # erasure removed entirely. This repository shipped exactly this once.

  Scenario: The seed copies rows that are not there
    Given I execute the SQL query "INSERT INTO audit_log (resource_type) SELECT resource_type FROM audit_log WHERE resource_type = 'FixtureResource'" on connection "seed"
    When I send a "DELETE" request to "/backoffice/users/0190f200-0000-7000-8000-0000000000f3"
    Then I execute the SQL query "SELECT id FROM audit_log WHERE resource_type = 'FixtureResource'"
    And there should have 0 records in SQL result
