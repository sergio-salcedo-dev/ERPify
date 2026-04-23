Feature: Get banks
  As an API consumer
  In order to manage banks
  I need to be able to retrieve banks

  Scenario: List all banks returns 200
    When I send a "GET" request to "/backoffice/banks"
    Then the response status code should be 200

#  Scenario: Get a single bank by id
#    Given I send a POST request to "/backoffice/banks" with body:
#    """
#    {"name": "Get Test Bank", "short_name": "GTB"}
#    """
#    And the response status code should be 201
#    When I send a "GET" request to "/backoffice/{bankId}"
#    And the response status code should be 200
#    And the response should contain "Get Test Bank"

  Scenario: Get a bank that does not exist returns 400
    When I send a "GET" request to "/backoffice/invalidUuid"
    Then the response status code should be 404

  Scenario: Get a bank that does not exist returns 404
    When I send a "GET" request to "/backoffice/00000000-0000-7000-8000-000000000000"
    Then the response status code should be 404
