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
    And the JSON node "data.accountCount" should not exist
    # The budget includes the regulatory change row the audit listener writes on the same transaction.
    And 10 requests got executed only for doctrine connection "default"
    And the last updated "Bank" entity found by "id=ed17ed00-0000-7000-8000-000000000001" should match:
      | name      | Updated Bank |
      | shortName | UB           |
    And there should be 1 event stored for aggregate "ed17ed00-0000-7000-8000-000000000001" named "erpify.backoffice.bank.updated"
    And 1 outbox event was created on the queue "async"
    And I got the event number 1 on queue "async" from the outbox
    And The outbox event should be of type "Erpify\Backoffice\Bank\Domain\Event\BankUpdatedDomainEvent"
    And The outbox event property "shortName" should be equal to "UB"
    And I consume 1 message from the "async" transport
    And the last run should succeed
    And the last run output should contain "handled successfully (acknowledging to transport)"
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

  # The idempotence lives in the aggregate, so a PUT whose canonical forms match what is stored writes
  # nothing: no UPDATE, no regulatory change row, no stored event, no outbox message — and `updated_at`
  # stands. The payload is deliberately not byte-identical to the stored row (untrimmed name, lower-case
  # short name) so the scenario also pins that equality is decided after canonicalization, never on the
  # raw request body.
  Scenario: A redundant update to the stored values is an idempotent no-op with no event
    Given I add "Content-Type" header equal to "application/json"
    And I add "Accept" header equal to "application/json"
    And I add "X-Correlation-Id" header equal to "0190ffff-0000-7abc-8def-00bb00bb00bb"
    And I execute the SQL query "INSERT INTO bank (id, name, name_normalized, short_name, created_at, updated_at) VALUES ('ed17ed00-0000-7000-8000-000000000002', 'Steady Bank', 'steady bank', 'SB', '2026-01-01 10:00:00', '2026-01-01 10:00:00')" on connection "seed"
    When I send a PUT request to "/backoffice/banks/ed17ed00-0000-7000-8000-000000000002" with body:
    """
    {"name": "  Steady Bank  ", "shortName": "sb"}
    """
    Then the response status code should be 200
    And the JSON node "data.name" should be equal to "Steady Bank"
    And the JSON node "data.shortName" should be equal to "SB"
    And the JSON node "data.updatedAt" should be equal to "2026-01-01T10:00:00+00:00"
    And there should be 0 events stored for aggregate "ed17ed00-0000-7000-8000-000000000002" named "erpify.backoffice.bank.updated"
    And 0 outbox events were created on the queue "async"
    And 0 notification emails were sent
    And I execute the SQL query "SELECT name, name_normalized, short_name, updated_at::text AS updated_at FROM bank WHERE id = 'ed17ed00-0000-7000-8000-000000000002'"
    And the SQL result as JSON should be:
    """
    [
      {
        "name": "Steady Bank",
        "name_normalized": "steady bank",
        "short_name": "SB",
        "updated_at": "2026-01-01 10:00:00"
      }
    ]
    """
    And I execute the SQL query "SELECT action FROM audit_log WHERE level = 'change' AND correlation_id = '0190ffff-0000-7abc-8def-00bb00bb00bb'"
    And the SQL result as JSON should be:
    """
    []
    """

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
    And there should be 0 events stored for aggregate "2e6d865c-17b0-476a-85f2-037bf6d3b3dc" named "erpify.backoffice.bank.updated"
