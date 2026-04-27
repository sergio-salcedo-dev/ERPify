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

  Scenario: Search a bank by names in array form returns matching results
    When I send a "GET" request to "/backoffice/banks?names[]=santander"
    Then the response status code should be 200
    And the JSON node "data.items" should have 1 elements
    And the JSON node "data.pagination" should exist

  Scenario: Search a bank by an invalid id returns 422
    When I send a "GET" request to "/backoffice/banks?ids[]=invalid"
    Then the response status code should be 422
    And the validation error on "ids[0]" should contain "valid"

  Scenario: Unknown pagination mode returns 422
    When I send a "GET" request to "/backoffice/banks?paginationMode=unknownPaginationMode"
    Then the response status code should be 422

  Scenario: Array-form pagination mode returns 422
    When I send a "GET" request to "/backoffice/banks?paginationMode[]=light"
    Then the response status code should be 422

  Scenario Outline: Invalid page returns 422
    When I send a "GET" request to "/backoffice/banks?page=<page>"
    Then the response status code should be 422
    Examples:
      | page  |
      | 0     |
      | -1    |
      | 10001 |

  Scenario Outline: Invalid limit returns 422
    When I send a "GET" request to "/backoffice/banks?limit=<limit>"
    Then the response status code should be 422
    Examples:
      | limit |
      | 0     |
      | -1    |
      | 1001  |
      | abc   |

  # PaginatorCursorFactory silently coerces unsigned input to empty cursor (HMAC hardening),
  # so missing-separator or signature-mismatch produces 200, not 400. See spec change log #1.
  Scenario: Cursor without HMAC signature is silently treated as empty
    When I send a "GET" request to "/backoffice/banks?cursor=invalidBase64"
    Then the response status code should be 200
