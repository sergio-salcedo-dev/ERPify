Feature: Get banks
  As an API consumer
  In order to manage banks
  I need to be able to retrieve banks

  Scenario: Get a single bank by id
    When I send a "GET" request to "/backoffice/banks/11111111-1111-7000-8000-000000000001"
    Then the response status code should be 200
    And the JSON node "data" should have 7 elements
    And the JSON node "data.name" should be equal to "ING"
    And the JSON node "data.shortName" should be equal to "ING"
    And the JSON node "data.createdAt" should not be null
    And the JSON node "data.updatedAt" should not be null

  Scenario Outline: Get a bank that does not exist returns 400
    When I send a "GET" request to "/backoffice/banks/<bankId>"
    Then the response status code should be 400
    And the validation error on "id" should be "<errorMessage>"
    Examples:
      | bankId      | errorMessage                    |
      | null        | This value should not be blank. |
      | invalidUuid | This value is not a valid UUID. |

  Scenario: Get a bank that does not exist returns 404
    When I send a "GET" request to "/backoffice/banks/2e6d865c-17b0-476a-85f2-037bf6d3b3dc"
    Then the response status code should be 404
    And the validation error on "uuid" should be "Bank with id <2e6d865c-17b0-476a-85f2-037bf6d3b3dc> not found."
