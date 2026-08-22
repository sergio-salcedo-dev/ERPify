Feature: A witness assembled out of two honest halves
  # Each scenario is defensible on its own. Together they say nothing: the one that asserts the absence never
  # created it, and the one that creates the row never looks for it again. This is the realistic drift shape —
  # what a file becomes when a seed is moved into a scenario of its own and nobody re-reads the assertion.

  Scenario: The trail no longer names the subject
    Given I execute the SQL query "SELECT id FROM audit_log WHERE resource_type = 'FixtureResource'"
    Then there should have 0 records in SQL result

  Scenario: A row of the type can be seeded
    Given I execute the SQL query "INSERT INTO audit_log (resource_type) VALUES ('FixtureResource')" on connection "seed"
    Then the response status code should be 204
