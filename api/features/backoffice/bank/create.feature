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
    {
      "name": "Test Bank",
      "shortName": "TB"
    }
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
    And the last inserted "Bank" entity found by "shortName=TB" should match:
      | name            | Test Bank                                       |
      | nameNormalized  | test bank                                       |
      | shortName       | TB                                              |
      | logo            | null                                            |
      | storedObject    | null                                            |
      | id::uuid        | v7                                              |
      | id::regex       | /^[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}$/ |
      | createdAt::date | now                                             |
    And there should have 1 "StoredDomainEvent" entity found by "name=erpify.backoffice.bank.created"
    And 1 domain event named "erpify.backoffice.bank.created" should be stored
    And the stored domain event "erpify.backoffice.bank.created" body "name" should be equal to "Test Bank"
    And the stored domain event "erpify.backoffice.bank.created" body "shortName" should be equal to "TB"

  # The fixture DB restores per feature, not per scenario, so the earlier "Successfully create a
  # bank" scenario's stored event would survive into the create-422 scenarios. Each rejection
  # scenario empties the domain_event outbox first, then resets the query counter (the deletes run
  # on the default connection) before asserting the rejected request stored no bank-created event.
  Scenario: It should fail when payload is empty
    Given the "StoredDomainEvent" entities found by "" are deleted
    And I reset the stats for all doctrine connections
    When I send a POST request to "/backoffice/banks" with body:
    """
    {}
    """
    Then the response status code should be 422
    And the header "Content-Type" should be equal to "application/problem+json"
    And the header "Cache-Control" should contain "no-store"
    And the JSON node "type" should be equal to "validation-failed"
    And the JSON node "title" should be equal to "Validation failed."
    And the JSON node "status" should be equal to the number 422
    And the JSON node "instance" should be a valid UUID
    And the JSON node "correlation-id" should be a valid UUID
    And the JSON node "violations" should have 2 elements
    And the JSON node "violations[0]" should have 3 elements
    And the JSON node "violations[0].field" should be equal to "name"
    And the JSON node "violations[0].message" should be equal to "The name field is required."
    And the JSON node "violations[0].code" should be equal to "c1051bb4-d103-4f74-8988-acbcafc7fdc3"
    And the JSON node "violations[1].field" should be equal to "shortName"
    And the JSON node "violations[1].message" should be equal to "The code field is required."
    And the JSON node "violations[1].code" should be equal to "c1051bb4-d103-4f74-8988-acbcafc7fdc3"
    And 0 requests got executed across all doctrine connections
    And there should have 0 "StoredDomainEvent" entities found by "name=erpify.backoffice.bank.created"

  Scenario: Fail to create a bank with missing fields
    Given the "StoredDomainEvent" entities found by "" are deleted
    And I reset the stats for all doctrine connections
    When I send a POST request to "/backoffice/banks" with body:
    """
    {
      "name": "Incomplete Bank"
    }
    """
    Then the response status code should be 422
    And the header "Content-Type" should be equal to "application/problem+json"
    And the header "Cache-Control" should contain "no-store"
    And the JSON node "type" should be equal to "validation-failed"
    And the JSON node "title" should be equal to "Validation failed."
    And the JSON node "status" should be equal to the number 422
    And the JSON node "instance" should be a valid UUID
    And the JSON node "correlation-id" should be a valid UUID
    And the JSON node "violations" should have 1 element
    And the JSON node "violations[0]" should have 3 elements
    And the JSON node "violations[0].field" should be equal to "shortName"
    And the JSON node "violations[0].message" should be equal to "The code field is required."
    And the JSON node "violations[0].code" should be equal to "c1051bb4-d103-4f74-8988-acbcafc7fdc3"
    And 0 requests got executed across all doctrine connections
    And there should have 0 "StoredDomainEvent" entities found by "name=erpify.backoffice.bank.created"

  Scenario: Fail to create a bank whose name only differs in case from an existing one
    Given the "StoredDomainEvent" entities found by "" are deleted
    And I reset the stats for all doctrine connections
    When I send a POST request to "/backoffice/banks" with body:
    """
    {
      "name": "bbva",
      "shortName": "BB"
    }
    """
    Then the response status code should be 422
    And the header "Content-Type" should be equal to "application/problem+json"
    And the header "Cache-Control" should contain "no-store"
    And the JSON node "type" should be equal to "validation-failed"
    And the JSON node "title" should be equal to "Validation failed."
    And the JSON node "status" should be equal to the number 422
    And the JSON node "instance" should be a valid UUID
    And the JSON node "correlation-id" should be a valid UUID
    And the JSON node "violations" should have 1 element
    And the JSON node "violations[0]" should have 3 elements
    And the JSON node "violations[0].field" should be equal to "name"
    And the JSON node "violations[0].message" should be equal to "This bank name is already in use."
    And the JSON node "violations[0].code" should be equal to "23bd9dbf-6b9b-41cd-a99e-4844bcf3077f"
    And 2 requests got executed only for doctrine connection "default"
    And there should have 0 "StoredDomainEvent" entities found by "name=erpify.backoffice.bank.created"

  Scenario: Fail to create a bank whose short name only differs in case from an existing one
    Given the "StoredDomainEvent" entities found by "" are deleted
    And I reset the stats for all doctrine connections
    When I send a POST request to "/backoffice/banks" with body:
    """
    {
      "name": "Some New Bank",
      "shortName": "bbva"
    }
    """
    Then the response status code should be 422
    And the header "Content-Type" should be equal to "application/problem+json"
    And the header "Cache-Control" should contain "no-store"
    And the JSON node "type" should be equal to "validation-failed"
    And the JSON node "title" should be equal to "Validation failed."
    And the JSON node "status" should be equal to the number 422
    And the JSON node "instance" should be a valid UUID
    And the JSON node "correlation-id" should be a valid UUID
    And the JSON node "violations" should have 1 element
    And the JSON node "violations[0]" should have 3 elements
    And the JSON node "violations[0].field" should be equal to "shortName"
    And the JSON node "violations[0].message" should be equal to "This code is already in use."
    And the JSON node "violations[0].code" should be equal to "23bd9dbf-6b9b-41cd-a99e-4844bcf3077f"
    And 2 requests got executed only for doctrine connection "default"
    And there should have 0 "StoredDomainEvent" entities found by "name=erpify.backoffice.bank.created"

  Scenario: Fail to create a bank whose name only differs in diacritics from an existing one
    Given the "StoredDomainEvent" entities found by "" are deleted
    And I reset the stats for all doctrine connections
    When I send a POST request to "/backoffice/banks" with body:
    """
    {
      "name": "Sociedad Anonima",
      "shortName": "SA2"
    }
    """
    Then the response status code should be 422
    And the header "Content-Type" should be equal to "application/problem+json"
    And the header "Cache-Control" should contain "no-store"
    And the JSON node "type" should be equal to "validation-failed"
    And the JSON node "title" should be equal to "Validation failed."
    And the JSON node "status" should be equal to the number 422
    And the JSON node "instance" should be a valid UUID
    And the JSON node "correlation-id" should be a valid UUID
    And the JSON node "violations" should have 1 element
    And the JSON node "violations[0]" should have 3 elements
    And the JSON node "violations[0].field" should be equal to "name"
    And the JSON node "violations[0].message" should be equal to "This bank name is already in use."
    And the JSON node "violations[0].code" should be equal to "23bd9dbf-6b9b-41cd-a99e-4844bcf3077f"
    And 2 requests got executed only for doctrine connection "default"
    And there should have 0 "StoredDomainEvent" entities found by "name=erpify.backoffice.bank.created"

  Scenario Outline: A created bank stores a normalized name distinct from its display form
    When I send a POST request to "/backoffice/banks" with body:
    """
    {
      "name": "<name>",
      "shortName": "<shortName>"
    }
    """
    Then the response status code should be 201
    And the JSON node "data.name" should be equal to "<name>"
    And the JSON node "data.shortName" should be equal to "<storedShortName>"
    And the last inserted "Bank" entity found by "shortName=<storedShortName>" should match:
      | name           | <name>            |
      | nameNormalized | <nameNormalized>  |
      | shortName      | <storedShortName> |

    Examples:
      | variant           | name            | shortName | storedShortName | nameNormalized  |
      | uppercase folds   | MAYÚSCULA Test  | mayu      | MAYU            | mayuscula test  |
      | accents fold      | Crédit Lyonë    | crly      | CRLY            | credit lyone    |
      | eñe and cedilla   | Niño Çedi Bank  | ninoc     | NINOC           | nino cedi bank  |
      | short name uppers | mixed Case bank | sgx       | SGX             | mixed case bank |
