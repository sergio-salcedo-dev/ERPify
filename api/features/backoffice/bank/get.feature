@wip
Feature: Get banks
  As an API consumer
  In order to manage banks
  I need to be able to retrieve banks

  Scenario: List all banks returns 200
    When I go to "/backoffice/banks"
    Then the response status code should be 200

  Scenario: Get a single bank by id
    When I send a POST request to "/backoffice/banks" with body:
    """
    {"name": "Get Test Bank", "short_name": "GTB"}
    """
    Then the response status code should be 201
    And I remember the JSON field "id" as "bankId"

    And I go to "/backoffice/banks/{bankId}"
    And the response status code should be 200
    And the response should contain "Get Test Bank"

  Scenario: Get a bank that does not exist returns 404
    When I go to "/backoffice/banks/00000000-0000-7000-8000-000000000000"
    Then the response status code should be 404
