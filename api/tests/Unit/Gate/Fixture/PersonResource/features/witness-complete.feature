Feature: The clean witness twin
  # Seeds a row of the type and asserts none survives — the shape a real witness has to take. Its job is to
  # prove the dirty twins beside it fail for the reason claimed and not because the rule rejects everything.
  #
  # Under tests/, so Behat never collects it and the suite-coverage gate never sees it.

  Scenario: A row of the type is written and does not survive the erasure
    Given I execute the SQL query "INSERT INTO audit_log (resource_type) VALUES ('FixtureResource')" on connection "seed"
    When I send a "DELETE" request to "/backoffice/users/0190f200-0000-7000-8000-0000000000f1"
    Then the response status code should be 204
    And I execute the SQL query "SELECT id FROM audit_log WHERE resource_type = 'FixtureResource'"
    # A comment between the query and its count is idiomatic in the real suite and must not read as the
    # absence of an assertion.
    And there should have 0 records in SQL result
