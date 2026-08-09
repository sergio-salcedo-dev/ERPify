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

  # Consumer idempotency under at-least-once delivery: the same event (pinned eventId) is dispatched
  # twice, so the worker receives two messages but the append is a no-op the second time
  # (event_store ON CONFLICT (event_id)). On consume the handlers diverge by design: the non-idempotent
  # email handler claims (eventId, handler) and sends once (SendEmailOnBankChanged), while the realtime
  # handler is deliberately not deduplicated and re-publishes, because clients reconcile by id
  # (RefreshRealtimeOnBankChanged).
  Scenario: Redelivering the same event sends the email once but re-publishes realtime, with one stored row
    When I dispatch the "Erpify\Backoffice\Bank\Domain\Event\BankCreatedDomainEvent" outbox event with:
    """
    {
      "aggregateId": "01970000-0000-7000-8000-000000000009",
      "eventId": "01970000-0000-7000-8000-0000000000a1",
      "occurredOn": "2026-06-16T10:00:00+00:00",
      "payload": {
        "name": "Redelivered Bank",
        "shortName": "RDB",
        "createdAt": "2026-06-16T10:00:00+00:00",
        "updatedAt": "2026-06-16T10:00:00+00:00"
      }
    }
    """
    And I dispatch the "Erpify\Backoffice\Bank\Domain\Event\BankCreatedDomainEvent" outbox event with:
    """
    {
      "aggregateId": "01970000-0000-7000-8000-000000000009",
      "eventId": "01970000-0000-7000-8000-0000000000a1",
      "occurredOn": "2026-06-16T10:00:00+00:00",
      "payload": {
        "name": "Redelivered Bank",
        "shortName": "RDB",
        "createdAt": "2026-06-16T10:00:00+00:00",
        "updatedAt": "2026-06-16T10:00:00+00:00"
      }
    }
    """
    Then 2 outbox events were created on the queue "async"
    And there should be 1 event stored for aggregate "01970000-0000-7000-8000-000000000009" named "erpify.backoffice.bank.created"
    And I consume 2 messages from the "async" transport
    And the command should succeed
    And 0 outbox events were created on the queue "async"
    And 1 notification email was sent
    And The notification email subject should be equal to "[ERPify] Bank created"
    And 2 Mercure updates were published

  # The (eventId, handler) claim lives in the handled_domain_event table, so it has to outlive the
  # delivery that set it. The redelivery scenario above cannot show that: both messages sit in one
  # worker run, so a claim that was merely per-run state would produce an identical count. Here the
  # queue is drained and the messages acknowledged first, and only then are the same handlers re-run
  # over the retained sent envelope — the email handler must still find its claim taken.
  #
  # The Mercure assertion is what stops this passing vacuously: the realtime handler is deliberately
  # not deduplicated, so it MUST publish again. If re-processing ran no handler at all, that count
  # would stay at 1 and this scenario would fail rather than quietly agreeing with itself.
  Scenario: The email claim outlives the consume, so re-processing an acknowledged event adds no email
    When I dispatch the "Erpify\Backoffice\Bank\Domain\Event\BankCreatedDomainEvent" outbox event with:
    """
    {
      "aggregateId": "01970000-0000-7000-8000-000000000011",
      "eventId": "01970000-0000-7000-8000-0000000000b1",
      "occurredOn": "2026-06-16T10:00:00+00:00",
      "payload": {
        "name": "Reprocessed Bank",
        "shortName": "RPB",
        "createdAt": "2026-06-16T10:00:00+00:00",
        "updatedAt": "2026-06-16T10:00:00+00:00"
      }
    }
    """
    Then 1 outbox event was created on the queue "async"
    And I consume 1 message from the "async" transport
    And the command should succeed
    And 0 outbox events were created on the queue "async"
    And 1 notification email was sent
    And 1 Mercure update was published
    And message 1 sent to "async" is processed
    And 1 notification email was sent
    And 2 Mercure updates were published

  # Every count assertion in this suite rests on the outbox being re-read on each step rather than
  # answered from a snapshot taken once. Removing a pending event mid-scenario is what tells the two
  # apart: against a cached reader the count below stays at 2 and every "N outbox events" assertion
  # in the suite is reporting a number from an earlier moment.
  Scenario: A removed event leaves the outbox, and the survivor is still readable
    When I dispatch the "Erpify\Backoffice\Bank\Domain\Event\BankCreatedDomainEvent" outbox event with:
    """
    {
      "aggregateId": "01970000-0000-7000-8000-000000000021",
      "eventId": "01970000-0000-7000-8000-0000000000c1",
      "occurredOn": "2026-06-16T10:00:00+00:00",
      "payload": {
        "name": "Removed Bank",
        "shortName": "RMB",
        "createdAt": "2026-06-16T10:00:00+00:00",
        "updatedAt": "2026-06-16T10:00:00+00:00"
      }
    }
    """
    And I dispatch the "Erpify\Backoffice\Bank\Domain\Event\BankUpdatedDomainEvent" outbox event with:
    """
    {
      "aggregateId": "01970000-0000-7000-8000-000000000022",
      "eventId": "01970000-0000-7000-8000-0000000000c2",
      "occurredOn": "2026-06-16T10:05:00+00:00",
      "payload": {
        "name": "Surviving Bank",
        "shortName": "SVB",
        "createdAt": "2026-06-16T10:00:00+00:00",
        "updatedAt": "2026-06-16T10:05:00+00:00"
      }
    }
    """
    Then 2 outbox events were created on the queue "async"
    And I remove event 1 on the queue "async" from the outbox
    And 1 outbox event was created on the queue "async"
    And I got the event number 1 on queue "async" from the outbox
    And The outbox event should be of type "Erpify\Backoffice\Bank\Domain\Event\BankUpdatedDomainEvent"
    And The outbox event property "shortName" should be equal to "SVB"

  # Removal by type is selective, not a drain: the assertion that matters is the one on what is LEFT.
  # Rejecting every pending event would satisfy a count of 0 just as well, so the survivor's type and
  # payload are what pin that only the named type was taken.
  Scenario: Removing events by type takes only that type out of the outbox
    When I dispatch the "Erpify\Backoffice\Bank\Domain\Event\BankCreatedDomainEvent" outbox event with:
    """
    {
      "aggregateId": "01970000-0000-7000-8000-000000000031",
      "eventId": "01970000-0000-7000-8000-0000000000d1",
      "occurredOn": "2026-06-16T10:00:00+00:00",
      "payload": {
        "name": "Typed Removal Bank",
        "shortName": "TRB",
        "createdAt": "2026-06-16T10:00:00+00:00",
        "updatedAt": "2026-06-16T10:00:00+00:00"
      }
    }
    """
    And I dispatch the "Erpify\Backoffice\Bank\Domain\Event\BankUpdatedDomainEvent" outbox event with:
    """
    {
      "aggregateId": "01970000-0000-7000-8000-000000000032",
      "eventId": "01970000-0000-7000-8000-0000000000d2",
      "occurredOn": "2026-06-16T10:05:00+00:00",
      "payload": {
        "name": "Untouched Bank",
        "shortName": "UTB",
        "createdAt": "2026-06-16T10:00:00+00:00",
        "updatedAt": "2026-06-16T10:05:00+00:00"
      }
    }
    """
    Then 2 outbox events were created on the queue "async"
    And I remove event of type "Erpify\Backoffice\Bank\Domain\Event\BankCreatedDomainEvent" on the queue "async" from the outbox
    And 1 outbox event was created on the queue "async"
    And I got the event number 1 on queue "async" from the outbox
    And The outbox event should be of type "Erpify\Backoffice\Bank\Domain\Event\BankUpdatedDomainEvent"
    And The outbox event property "shortName" should be equal to "UTB"
