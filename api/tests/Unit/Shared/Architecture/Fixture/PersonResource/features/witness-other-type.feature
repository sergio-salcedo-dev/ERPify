Feature: A witness that is entirely about another type
  # Every step here is a well-formed seed-then-vanish pair — for a DIFFERENT resource type. It is what
  # separates a rule that checks "some INSERT and some SELECT exist" from one that checks they are about the
  # type being declared, and dropping either literal test from the rule turns this red.

  Scenario: A row of another type is written and does not survive
    Given I execute the SQL query "INSERT INTO audit_log (resource_type) VALUES ('OtherType')" on connection "seed"
    When I send a "DELETE" request to "/backoffice/users/0190f200-0000-7000-8000-0000000000f2"
    Then the response status code should be 204
    And I execute the SQL query "SELECT id FROM audit_log WHERE resource_type = 'OtherType'"
    And there should have 0 records in SQL result
