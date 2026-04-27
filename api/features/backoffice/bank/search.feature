Feature: Search banks
  As an API consumer
  In order to manage banks
  I need to be able to retrieve banks

  Scenario: List all banks
    When I send a "GET" request to "/backoffice/banks"
    Then the response status code should be 200
    And the JSON node "data" should have 5 elements
    And the JSON nodes matching "data[*]" should have 4 children
    And the JSON nodes matching "data[*].name" should exist
    And the JSON nodes matching "data[*].shortName" should exist
    And the JSON nodes matching "data[*].createdAt" should exist
    And the JSON nodes matching "data[*].updatedAt" should exist

  Scenario: Search a bank by a valid id that does not exist returns no results
    When I send a "GET" request to "/backoffice/banks?id=2e6d865c-17b0-476a-85f2-037bf6d3b3dc"
    Then the response status code should be 200
    And the JSON node "data" should have 0 elements
