Feature: Get a bank account
  As an API consumer
  In order to inspect an account
  I need to be able to retrieve a single bank account by id

  Scenario: Get a single account by id returns the full single-account projection
    When I send a "GET" request to "/backoffice/bank-accounts/33333333-3333-7000-8000-000000000001"
    Then the response status code should be 200
    And the JSON node "data" should have 10 elements
    And the JSON node "data.id" should be equal to "33333333-3333-7000-8000-000000000001"
    And the JSON node "data.bankId" should be equal to "11111111-1111-7000-8000-000000000001"
    And the JSON node "data.holderName" should be equal to "Globex Corporation"
    And the JSON node "data.iban" should be equal to "DE89370400440532013000"
    And the JSON node "data.bic" should be equal to "DEUTDEFFXXX"
    And the JSON node "data.alias" should be equal to "Globex Treasury"
    And the JSON node "data.currency" should be equal to "EUR"
    And the JSON node "data.status" should be equal to "INACTIVE"
    And the JSON node "data.createdAt" should not be null
    And the JSON node "data.updatedAt" should not be null

  Scenario: The status is its identity wire value and absent optional fields are null
    When I send a "GET" request to "/backoffice/bank-accounts/33333333-3333-7000-8000-000000000002"
    Then the response status code should be 200
    And the JSON node "data.holderName" should be equal to "Initech LLC"
    And the JSON node "data.iban" should be equal to "FR1420041010050500013M02606"
    And the JSON node "data.status" should be equal to "ACTIVE"
    And the JSON node "data.bic" should be null
    And the JSON node "data.alias" should be null

  Scenario Outline: Get an account with a malformed id returns a 400 invalid-uuid Problem Details body
    When I send a "GET" request to "/backoffice/bank-accounts/<accountId>"
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
      | accountId   |
      | null        |
      | invalidUuid |

  Scenario: Get an account that does not exist returns a 404 bank-account-not-found Problem Details body
    When I send a "GET" request to "/backoffice/bank-accounts/2e6d865c-17b0-476a-85f2-037bf6d3b3dc"
    Then the response status code should be 404
    And the header "Content-Type" should be equal to "application/problem+json"
    And the header "Cache-Control" should contain "no-store"
    And the JSON node "type" should be equal to "bank-account-not-found"
    And the JSON node "title" should be equal to "Bank account with id <2e6d865c-17b0-476a-85f2-037bf6d3b3dc> not found."
    And the JSON node "status" should be equal to the number 404
    And the JSON node "bankAccountId" should be equal to "2e6d865c-17b0-476a-85f2-037bf6d3b3dc"
    And the JSON node "instance" should be a valid UUID
    And the JSON node "correlation-id" should be a valid UUID
