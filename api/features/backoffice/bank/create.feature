Feature: Create a bank
  As an API consumer
  In order to manage banks
  I need to be able to create a bank

  Background:
    Given I add "Content-Type" header equal to "application/json"
    And I add "Accept" header equal to "application/json"

  Scenario: Successfully create a bank
    When I send a POST request to "/backoffice/banks" with body:
    """
    {"name": "Test Bank", "shortName": "TB"}
    """
    Then the response status code should be 201
    And the JSON node "data" should have 7 elements
    And the JSON node "data.name" should be equal to "Test Bank"
    And the JSON node "data.shortName" should be equal to "TB"
    And the JSON node "data.id" should not be null
    And the JSON node "data.createdAt" should not be null
    And the JSON node "data.updatedAt" should not be null
    And the JSON node "data.logoUrl" should be null
    And the JSON node "data.storedObjectUrl" should be null
    And the JSON node "data.accountCount" should not exist
    And a request contains "INSERT" for doctrine connection "default"
    And 8 requests got executed only for doctrine connection "default"
    And the last inserted "Erpify\Backoffice\Bank\Domain\Entity\Bank" entity found by "shortName=TB" should match:
      | name      | Test Bank |
      | shortName | TB        |
    And there should have 1 "Erpify\Shared\Infrastructure\Persistence\Entity\StoredDomainEvent" entity found by "name=erpify.backoffice.bank.created"

  Scenario: It should fail when payload is empty
    When I send a POST request to "/backoffice/banks" with body:
    """
    {}
    """
    Then the response status code should be 422
    And the JSON node "type" should be equal to "validation-failed"
    And the JSON node "title" should be equal to "Validation failed."
    And the JSON node "instance" should be a UUID v7
    And the JSON node "correlation-id" should be a UUID v7
    And the JSON node "violations" should have 2 elements
    And the JSON node "violations[0]" should have 3 elements
    And the JSON node "violations[0].field" should be equal to "name"
    And the JSON node "violations[0].message" should be equal to "The name field is required."
    And the JSON node "violations[0].code" should be equal to "c1051bb4-d103-4f74-8988-acbcafc7fdc3"
    And the JSON node "violations[1].field" should be equal to "shortName"
    And the JSON node "violations[1].message" should be equal to "The code field is required."
    And the JSON node "violations[1].code" should be equal to "c1051bb4-d103-4f74-8988-acbcafc7fdc3"
    And 0 requests got executed across all doctrine connections

  Scenario: Fail to create a bank with missing fields
    When I send a POST request to "/backoffice/banks" with body:
    """
    {"name": "Incomplete Bank"}
    """
    Then the response status code should be 422
    And 0 requests got executed across all doctrine connections

  Scenario: Fail to create a bank whose name only differs in case from an existing one
    When I send a POST request to "/backoffice/banks" with body:
    """
    {"name": "bbva", "shortName": "BB"}
    """
    Then the response status code should be 422
    And the JSON node "type" should be equal to "validation-failed"
    And the JSON node "violations[0].field" should be equal to "name"
    And 2 requests got executed only for doctrine connection "default"

  Scenario: Fail to create a bank whose short name only differs in case from an existing one
    When I send a POST request to "/backoffice/banks" with body:
    """
    {"name": "Some New Bank", "shortName": "bbva"}
    """
    Then the response status code should be 422
    And the JSON node "type" should be equal to "validation-failed"
    And the JSON node "violations[0].field" should be equal to "shortName"
    And 2 requests got executed only for doctrine connection "default"

  Scenario: Fail to create a bank whose name only differs in diacritics from an existing one
    When I send a POST request to "/backoffice/banks" with body:
    """
    {"name": "Sociedad Anonima", "shortName": "SA2"}
    """
    Then the response status code should be 422
    And the JSON node "type" should be equal to "validation-failed"
    And the JSON node "violations[0].field" should be equal to "name"
    And 2 requests got executed only for doctrine connection "default"
