Feature: Update a bank
  As an API consumer
  In order to manage banks
  I need to be able to update a bank

  # Seed the bank out-of-band via raw SQL on a side connection the query counter ignores, so the
  # asserted budget isolates the update path from the cost of creating the bank.
  Scenario: Successfully update a bank
    Given I add "Content-Type" header equal to "application/json"
    And I add "Accept" header equal to "application/json"
    And I execute the SQL query "INSERT INTO bank (id, name, name_normalized, short_name, created_at, updated_at) VALUES ('ed17ed00-0000-7000-8000-000000000001', 'Original Bank', 'original bank', 'OB', NOW(), NOW())" on connection "seed"
    When I send a PUT request to "/backoffice/banks/ed17ed00-0000-7000-8000-000000000001" with body:
    """
    {"name": "Updated Bank", "shortName": "UB"}
    """
    Then the response status code should be 200
    And the JSON node "data" should have 5 elements
    And the JSON node "data.id" should be equal to "ed17ed00-0000-7000-8000-000000000001"
    And the JSON node "data.name" should be equal to "Updated Bank"
    And the JSON node "data.shortName" should be equal to "UB"
    And the JSON node "data.createdAt" should not be null
    And the JSON node "data.updatedAt" should not be null
    And the JSON node "data.logoUrl" should not exist
    And the JSON node "data.storedObjectUrl" should not exist
    And the JSON node "data.accountCount" should not exist
    And 9 requests got executed only for doctrine connection "default"
    And the last updated "Erpify\Backoffice\Bank\Domain\Entity\Bank" entity found by "id=ed17ed00-0000-7000-8000-000000000001" should match:
      | name      | Updated Bank |
      | shortName | UB           |
    And there should have 1 "Erpify\Shared\Infrastructure\Persistence\Entity\StoredDomainEvent" entity found by "aggregateId=ed17ed00-0000-7000-8000-000000000001&name=erpify.backoffice.bank.updated"

  Scenario: Update a bank that does not exist returns a 404 bank-not-found Problem Details body
    Given I add "Content-Type" header equal to "application/json"
    And I add "Accept" header equal to "application/json"
    When I send a PUT request to "/backoffice/banks/2e6d865c-17b0-476a-85f2-037bf6d3b3dc" with body:
    """
    {"name": "Updated Bank", "shortName": "UB"}
    """
    Then the response status code should be 404
    And the header "Content-Type" should be equal to "application/problem+json"
    And the header "Cache-Control" should contain "no-store"
    And the response should be in JSON
    And the JSON node "type" should be equal to "bank-not-found"
    And the JSON node "title" should be equal to "Bank with id <2e6d865c-17b0-476a-85f2-037bf6d3b3dc> not found."
    And the JSON node "status" should be equal to the number 404
    And the JSON node "bankId" should be equal to "2e6d865c-17b0-476a-85f2-037bf6d3b3dc"
    And the JSON node "instance" should be a UUID v7
    And the JSON node "correlation-id" should be a UUID v7
    And 1 request got executed only for doctrine connection "default"
