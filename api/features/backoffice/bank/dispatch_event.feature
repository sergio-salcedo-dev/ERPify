Feature: Dispatch a bank domain event onto the outbox
  As a developer
  In order to test the consume pipeline in isolation from the HTTP write path
  I need to dispatch a synthetic bank event and assert its observable effects

  # Drives the outbox directly via "I dispatch" (no HTTP), reconstructing the event through its own
  # fromPrimitives() and exercising the full publish -> consume -> ack -> notification + realtime
  # pipeline against a crafted stored-row-shaped payload (aggregateId + envelope + domain payload).
  Scenario: A dispatched bank-created event is consumed and produces the notification and realtime effects
    When I dispatch the "Erpify\Backoffice\Bank\Domain\Event\BankCreatedDomainEvent" outbox event with:
    """
    {
      "aggregateId": "01970000-0000-7000-8000-000000000001",
      "occurredOn": "2026-06-16T10:00:00+00:00",
      "payload": {
        "name": "Dispatched Bank",
        "shortName": "DB",
        "createdAt": "2026-06-16T10:00:00+00:00",
        "updatedAt": "2026-06-16T10:00:00+00:00"
      }
    }
    """
    Then 1 outbox event was created on the queue "async"
    And I got the event number 1 on queue "async" from the outbox
    And The outbox event should be of type "Erpify\Backoffice\Bank\Domain\Event\BankCreatedDomainEvent"
    And The outbox event aggregate id should be equal to "01970000-0000-7000-8000-000000000001"
    And The outbox event property "name" should be equal to "Dispatched Bank"
    And The outbox event property "shortName" should be equal to "DB"
    And I consume 1 message from the "async" transport
    And the command should succeed
    And the output should contain "handled successfully (acknowledging to transport)"
    And 0 outbox events were created on the queue "async"
    And 1 notification email was sent
    And The notification email subject should be equal to "[ERPify] Bank created"
    And The notification email body should contain "Dispatched Bank"
    And 1 Mercure update was published
    And The Mercure update property "type" should be equal to "bank.created"
    And The Mercure update property "bank.shortName" should be equal to "DB"
