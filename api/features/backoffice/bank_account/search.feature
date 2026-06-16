Feature: List the accounts of a bank
  As an API consumer
  In order to resolve a bank that cannot be deleted
  I need to retrieve the accounts associated with a bank

  # Keyset envelope inherited from Epic 1: {items, pagination:{hasNext,hasPrev,count,links:{next,prev}}}.
  # Two queries per page: the existence guard (cheap COUNT, no entity hydration) plus the accounts page.
  # The full canonical IBAN (upper-case, no separators) is returned — masking is a client concern.
  Scenario: List the accounts of a bank returns the full account read projection
    When I send a "GET" request to "/backoffice/banks/11111111-1111-7000-8000-000000000001/accounts?limit=100"
    Then the response status code should be 200
    And the JSON node "data" should have 1 elements
    And the JSON node "data[0].id" should be equal to "33333333-3333-7000-8000-000000000001"
    And the JSON node "data[0].holderName" should be equal to "Globex Corporation"
    And the JSON node "data[0].iban" should be equal to "DE89370400440532013000"
    And the JSON node "data[0].bic" should be equal to "DEUTDEFFXXX"
    And the JSON node "data[0].alias" should be equal to "Globex Treasury"
    And the JSON node "data[0].currency" should be equal to "EUR"
    And the JSON node "data[0].status" should be equal to "INACTIVE"
    And the JSON node "pagination" should have 4 elements
    And the JSON node "pagination.hasNext" should be false
    And the JSON node "pagination.hasPrev" should be false
    And the JSON node "pagination.count" should be null
    And the JSON node "pagination.links.next" should be null
    And the JSON node "pagination.links.prev" should be null
    And 2 requests got executed only for doctrine connection "default"

  Scenario: The status is serialized as its identity wire value and absent optional fields are null
    When I send a "GET" request to "/backoffice/banks/11111111-1111-7000-8000-000000000002/accounts?limit=100"
    Then the response status code should be 200
    And the JSON node "data" should have 1 elements
    And the JSON node "data[0].holderName" should be equal to "Initech LLC"
    And the JSON node "data[0].iban" should be equal to "FR1420041010050500013M02606"
    And the JSON node "data[0].currency" should be equal to "EUR"
    And the JSON node "data[0].status" should be equal to "ACTIVE"
    And the JSON node "data[0].bic" should be null
    And the JSON node "data[0].alias" should be null
    And 2 requests got executed only for doctrine connection "default"

  Scenario: A bank with no associated accounts returns an empty page
    When I send a "GET" request to "/backoffice/banks/11111111-1111-7000-8000-000000000003/accounts"
    Then the response status code should be 200
    And the JSON node "data" should have 0 elements
    And the JSON node "pagination.hasNext" should be false
    And the JSON node "pagination.hasPrev" should be false
    And 2 requests got executed only for doctrine connection "default"

  Scenario: The limit caps the page size
    When I send a "GET" request to "/backoffice/banks/11111111-1111-7000-8000-000000000001/accounts?limit=1"
    Then the response status code should be 200
    And the JSON node "data" should have 1 elements
    And 2 requests got executed only for doctrine connection "default"

  # Citigroup holds three accounts: a full keyset round-trip across pages, exercising the nested-route
  # next-link generation (the `{id}` route param threaded into the cursor link) and the engine's
  # deterministic tie-break ordering.
  Scenario: Keyset pagination walks a bank's accounts across pages
    When I send a "GET" request to "/backoffice/banks/11111111-1111-7000-8000-000000000004/accounts?limit=2"
    Then the response status code should be 200
    And the JSON node "data" should have 2 elements
    And the JSON node "pagination.hasNext" should be true
    And the JSON node "pagination.hasPrev" should be false
    And the JSON node "pagination.links.next" should not be null
    And I follow the "pagination.links.next" link from the previous response
    And the response status code should be 200
    And the JSON node "data" should have 1 elements
    And the JSON node "pagination.hasNext" should be false
    And the JSON node "pagination.hasPrev" should be true

  Scenario Outline: A malformed bank id returns a 400 invalid-uuid Problem Details body
    When I send a "GET" request to "/backoffice/banks/<bankId>/accounts"
    Then the response status code should be 400
    And the header "Content-Type" should be equal to "application/problem+json"
    And the header "Cache-Control" should contain "no-store"
    And the JSON node "type" should be equal to "invalid-uuid"
    And the JSON node "title" should be equal to "The provided value is not a valid UUID."
    And the JSON node "status" should be equal to the number 400
    And the JSON node "instance" should be a valid UUID
    And the JSON node "correlation-id" should be a valid UUID
    And 0 requests got executed across all doctrine connections
    Examples:
      | bankId      |
      | null        |
      | invalidUuid |

  Scenario: An accounts request for a bank that does not exist returns a 404 bank-not-found body
    When I send a "GET" request to "/backoffice/banks/2e6d865c-17b0-476a-85f2-037bf6d3b3dc/accounts"
    Then the response status code should be 404
    And the header "Content-Type" should be equal to "application/problem+json"
    And the header "Cache-Control" should contain "no-store"
    And the JSON node "type" should be equal to "bank-not-found"
    And the JSON node "title" should be equal to "Bank with id <2e6d865c-17b0-476a-85f2-037bf6d3b3dc> not found."
    And the JSON node "status" should be equal to the number 404
    And the JSON node "bankId" should be equal to "2e6d865c-17b0-476a-85f2-037bf6d3b3dc"
    And the JSON node "instance" should be a valid UUID
    And the JSON node "correlation-id" should be a valid UUID
    And 1 request got executed only for doctrine connection "default"
