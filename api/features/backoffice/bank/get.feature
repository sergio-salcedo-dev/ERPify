Feature: Get banks
  As an API consumer
  In order to manage banks
  I need to be able to retrieve banks

  Scenario: Get a single bank by id
    When I send a "GET" request to "/backoffice/banks/11111111-1111-7000-8000-000000000001"
    Then the response status code should be 200
    And the JSON node "data" should have 8 elements
    And the JSON node "data.name" should be equal to "JPMorgan Chase"
    And the JSON node "data.shortName" should be equal to "JPM"
    And the JSON node "data.id" should be equal to "11111111-1111-7000-8000-000000000001"
    And the JSON node "data.createdAt" should not be null
    And the JSON node "data.updatedAt" should not be null
    And the JSON node "data.logoUrl" should be null
    And the JSON node "data.storedObjectUrl" should be null
    And the JSON node "data.accountCount" should be equal to the number 1
    And 2 requests got executed only for doctrine connection "default"

  Scenario: Get a bank with no associated accounts reports a zero account count
    When I send a "GET" request to "/backoffice/banks/11111111-1111-7000-8000-000000000003"
    Then the response status code should be 200
    And the JSON node "data.name" should be equal to "Wells Fargo"
    And the JSON node "data.accountCount" should be equal to the number 0
    And 2 requests got executed only for doctrine connection "default"

  Scenario Outline: Get a bank with a malformed id returns a 400 invalid-uuid Problem Details body
    When I send a "GET" request to "/backoffice/banks/<bankId>"
    Then the response status code should be 400
    And the header "Content-Type" should be equal to "application/problem+json"
    And the header "Cache-Control" should contain "no-store"
    And the response should be in JSON
    And the JSON node "type" should be equal to "invalid-uuid"
    And the JSON node "title" should be equal to "The provided value is not a valid UUID."
    And the JSON node "status" should be equal to the number 400
    And the JSON node "violations" should not exist
    And the JSON node "instance" should match "/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[0-9a-f]{4}-[0-9a-f]{12}$/"
    And the JSON node "correlation-id" should match "/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[0-9a-f]{4}-[0-9a-f]{12}$/"
    And the JSON node "debug" should have 5 elements
    And 0 requests got executed across all doctrine connections
    Examples:
      | bankId      |
      | null        |
      | invalidUuid |

  Scenario: Get a bank that does not exist returns a 404 bank-not-found Problem Details body
    When I send a "GET" request to "/backoffice/banks/2e6d865c-17b0-476a-85f2-037bf6d3b3dc"
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
    And the JSON node "debug" should have 5 elements
    And the JSON node "debug.exception_class" should exist
    And the JSON node "debug.message" should exist
    And the JSON node "debug.file" should exist
    And the JSON node "debug.line" should exist
    And the JSON node "debug.previous_chain" should exist
    And 1 request got executed only for doctrine connection "default"
