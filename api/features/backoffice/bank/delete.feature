Feature: Delete a bank
  As an API consumer
  In order to manage banks
  I need to be able to delete a bank

  Scenario: Successfully delete a bank
    Given I add "Content-Type" header equal to "application/json"
    And I add "Accept" header equal to "application/json"
    And I send a POST request to "/backoffice/banks" with body:
    """
    {"name": "Bank To Delete", "shortName": "BTD"}
    """
    And the response status code should be 201
    When I send a "DELETE" request to "/backoffice/banks/{value}" using the JSON node "data.id" from the previous response
    Then the response status code should be 204

  Scenario: Delete a bank that does not exist returns a 404 bank-not-found Problem Details body
    When I send a "DELETE" request to "/backoffice/banks/2e6d865c-17b0-476a-85f2-037bf6d3b3dc"
    Then the response status code should be 404
    And the header "Content-Type" should be equal to "application/problem+json"
    And the header "Cache-Control" should contain "no-store"
    And the response should be in JSON
    And the JSON node "type" should be equal to "bank-not-found"
    And the JSON node "title" should be equal to "Bank with id <2e6d865c-17b0-476a-85f2-037bf6d3b3dc> not found."
    And the JSON node "status" should be equal to the number 404
    And the JSON node "bankId" should be equal to "2e6d865c-17b0-476a-85f2-037bf6d3b3dc"
    And the JSON node "instance" should match "/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[0-9a-f]{4}-[0-9a-f]{12}$/"
    And the JSON node "correlation-id" should match "/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[0-9a-f]{4}-[0-9a-f]{12}$/"

  Scenario: Delete a bank referenced by accounts returns a 409 bank-in-use Problem Details body
    Given I reload the fixtures
    And I send a "GET" request to "/backoffice/banks?names[]=JPMorgan%20Chase"
    And the response status code should be 200
    And the JSON node "data.items" should have 1 elements
    And the JSON node "data.items[0].id" should be equal to "11111111-1111-7000-8000-000000000001"
    When I send a "DELETE" request to "/backoffice/banks/11111111-1111-7000-8000-000000000001"
    Then the response status code should be 409
    And the header "Content-Type" should be equal to "application/problem+json"
    And the header "Cache-Control" should contain "no-store"
    And the response should be in JSON
    And the JSON node "type" should be equal to "bank-in-use"
    And the JSON node "title" should be equal to "Cannot delete the bank because it still has 1 associated account."
    And the JSON node "status" should be equal to the number 409
    And the JSON node "bankId" should be equal to "11111111-1111-7000-8000-000000000001"
    And the JSON node "accountCount" should be equal to the number 1
    And the JSON node "instance" should match "/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[0-9a-f]{4}-[0-9a-f]{12}$/"
    And the JSON node "correlation-id" should match "/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[0-9a-f]{4}-[0-9a-f]{12}$/"
