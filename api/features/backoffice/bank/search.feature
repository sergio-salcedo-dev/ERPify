Feature: Search banks
  As an API consumer
  In order to manage banks
  I need to be able to retrieve banks

  Scenario: List all banks
    When I send a "GET" request to "/backoffice/banks"
    Then the response status code should be 200
    And the JSON node "data.items" should have 5 elements
    And the JSON nodes matching "data.items[*]" should have 5 children
    And the JSON nodes matching "data.items[*].id" should exist
    And the JSON nodes matching "data.items[*].name" should exist
    And the JSON nodes matching "data.items[*].shortName" should exist
    And the JSON nodes matching "data.items[*].createdAt" should exist
    And the JSON nodes matching "data.items[*].updatedAt" should exist
    And the JSON node "data.pagination" should have 5 elements
    And the JSON node "data.pagination.currentPage" should be equal to the number 1
    And the JSON node "data.pagination.pageCount" should be null
    And the JSON node "data.pagination.hasMorePages" should be false
    And the JSON node "data.pagination.cursor" should not be null

  Scenario: Search a bank by a valid id that does not exist returns no results
    When I send a "GET" request to "/backoffice/banks?ids[]=2e6d865c-17b0-476a-85f2-037bf6d3b3dc"
    Then the response status code should be 200
    And the JSON node "data.items" should have 0 elements
    And the JSON node "data.pagination" should exist

  Scenario: Search a bank by an invalid id returns no results
    When I send a "GET" request to "/backoffice/banks?ids[]=invalid"
    Then the response status code should be 200
    And the JSON node "data.items" should have 0 elements
    And the JSON node "data.pagination" should exist
