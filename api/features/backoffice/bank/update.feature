Feature: Update a bank
  As an API consumer
  In order to manage banks
  I need to be able to update a bank

  Scenario: Successfully update a bank
    When I send a POST request to "/api/v1/backoffice/banks" with body:
    """
    {"name": "Original Bank", "short_name": "OB"}
    """
    Then the response status code should be 201
    And I remember the JSON field "id" as "bankId"

    And I send a PUT request to "/api/v1/backoffice/banks/{bankId}" with body:
    """
    {"name": "Updated Bank", "short_name": "UB"}
    """
    And the response status code should be 200
    And the response should contain "Updated Bank"

  Scenario: Update a bank that does not exist returns 404
    When I send a PUT request to "/api/v1/backoffice/banks/00000000-0000-7000-8000-000000000000" with body:
    """
    {"name": "Updated Bank", "short_name": "UB"}
    """
    Then the response status code should be 404
