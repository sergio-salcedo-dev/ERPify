Feature: Delete a bank
  As an API consumer
  In order to manage banks
  I need to be able to delete a bank

  Scenario: Successfully delete a bank
    Given I send a POST request to "/backoffice/banks" with body:
    """
    {"name": "Bank To Delete", "short_name": "BTD"}
    """
    And the response status code should be 201
#    When I send a DELETE request to "/backoffice/banks/{bankId}"
#    Then the response status code should be 204

#  Scenario: Delete a bank that does not exist returns 404
#    When I send a DELETE request to "/backoffice/banks/00000000-0000-7000-8000-000000000000"
#    Then the response status code should be 404
