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
    And the last updated "Bank" entity found by "id=ed17ed00-0000-7000-8000-000000000001" should match:
      | name      | Updated Bank |
      | shortName | UB           |
    And there should have 1 "StoredDomainEvent" entities found by "aggregateId=ed17ed00-0000-7000-8000-000000000001&name=erpify.backoffice.bank.updated"
    And 1 outbox event was created on the queue "async"
    And I got the event number 1 on queue "async" from the outbox
    And The outbox event should be of type "Erpify\Backoffice\Bank\Domain\Event\BankUpdatedDomainEvent"
    And The outbox event property "shortName" should be equal to "UB"
    And I consume 1 message from the "async" transport
    And the command should succeed
    And the output should contain "handled successfully (acknowledging to transport)"
    And 0 outbox events were created on the queue "async"
    And 1 notification email was sent
    And The notification email subject should be equal to "[ERPify] Bank updated"
    # Update publishes to both the collection topic and the per-bank topic.
    And 1 Mercure update was published
    And The Mercure update should have 2 topics
    And The Mercure update topic 1 should be equal to "urn:erpify:backoffice:banks"
    And The Mercure update topic 2 should be equal to "urn:erpify:backoffice:bank:ed17ed00-0000-7000-8000-000000000001"
    And The Mercure update property "type" should be equal to "bank.updated"
    And The Mercure update property "bank.shortName" should be equal to "UB"

  Scenario: Update a bank that does not exist returns a 404 bank-not-found Problem Details body
    Given I add "Content-Type" header equal to "application/json"
    And I add "Accept" header equal to "application/json"
    And there should have 0 "Bank" entities found by "id=2e6d865c-17b0-476a-85f2-037bf6d3b3dc"
    When I send a PUT request to "/backoffice/banks/2e6d865c-17b0-476a-85f2-037bf6d3b3dc" with body:
    """
    {"name": "Updated Bank", "shortName": "UB"}
    """
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
    And there should have 0 "StoredDomainEvent" entities found by "aggregateId=2e6d865c-17b0-476a-85f2-037bf6d3b3dc&name=erpify.backoffice.bank.updated"
