Feature: Update a bank
  As an API consumer
  In order to manage banks
  I need to be able to update a bank

  # Seed the bank out-of-band via raw SQL on a side connection the query counter ignores, so the
  # asserted budget isolates the update path from the cost of creating the bank.
  Scenario: Successfully update a bank
    Given I add "Content-Type" header equal to "application/json"
    And I add "Accept" header equal to "application/json"
    And I execute the SQL query "INSERT INTO bank (id, name, name_normalized, short_name, created_at, updated_at) VALUES ('ed17ed00-0000-7000-8000-000000000001', 'Original Bank', 'original bank', 'OB', NOW(), NOW())" on connection "seed"
    When I send a PUT request to "/backoffice/banks/ed17ed00-0000-7000-8000-000000000001" with body:
    """
    {"name": "Updated Bank", "shortName": "UB"}
    """
    Then the response status code should be 200
    And the response should contain "Updated Bank"
    And 9 requests got executed only for doctrine connection "default"

#  Scenario: Update a bank that does not exist returns 404
#    When I send a PUT request to "/backoffice/banks/00000000-0000-7000-8000-000000000000" with body:
#    """
#    {"name": "Updated Bank", "shortName": "UB"}
#    """
#    Then the response status code should be 404
