Feature: Change a bank account's status
  As an API consumer
  In order to manage an account's lifecycle separately from its descriptive data
  I need to transition its status through a dedicated endpoint

  Background:
    Given I add "Content-Type" header equal to "application/json"
    And I add "Accept" header equal to "application/json"

  # Seed the account out-of-band via raw SQL on a side connection the query counter ignores.
  Scenario: Successfully transition an account's status
    Given I execute the SQL query "INSERT INTO bank_account (id, bank_id, holder_name, iban, bic, alias, currency, status, created_at, updated_at) VALUES ('acc57a70-0000-7000-8000-000000000001', '11111111-1111-7000-8000-000000000003', 'Lifecycle Holder', 'ES9121000418450200051332', NULL, NULL, 'EUR', 'ACTIVE', NOW(), NOW())" on connection "seed"
    When I send a PATCH request to "/backoffice/bank-accounts/acc57a70-0000-7000-8000-000000000001/status" with body:
    """
    {
      "status": "CLOSED"
    }
    """
    Then the response status code should be 200
    And the JSON node "data" should have 10 elements
    And the JSON node "data.id" should be equal to "acc57a70-0000-7000-8000-000000000001"
    And the JSON node "data.status" should be equal to "CLOSED"
    And the JSON node "data.holderName" should be equal to "Lifecycle Holder"
    And there should be 1 event stored for aggregate "acc57a70-0000-7000-8000-000000000001" named "erpify.backoffice.bankaccount.status_changed"
    And 1 outbox event was created on the queue "async"
    And I got the event number 1 on queue "async" from the outbox
    And The outbox event should be of type "Erpify\Backoffice\BankAccount\Domain\Event\BankAccountStatusChangedDomainEvent"
    And The outbox event property "bankId" should be equal to "11111111-1111-7000-8000-000000000003"
    And The outbox event property "fromStatus" should be equal to "ACTIVE"
    And The outbox event property "toStatus" should be equal to "CLOSED"
    And I consume 1 message from the "async" transport
    And the last run should succeed
    And the last run output should contain "handled successfully (acknowledging to transport)"
    And 0 outbox events were created on the queue "async"
    # The lifecycle transition signals a UI refetch, reusing the bank_account.updated realtime type.
    And 0 notification emails were sent
    And 1 Mercure update was published
    And The Mercure update should have 3 topics
    And The Mercure update topic 1 should be equal to "urn:erpify:backoffice:bankaccounts"
    And The Mercure update topic 2 should be equal to "urn:erpify:backoffice:bank:11111111-1111-7000-8000-000000000003:accounts"
    And The Mercure update topic 3 should be equal to "urn:erpify:backoffice:bankaccount:acc57a70-0000-7000-8000-000000000001"
    And The Mercure update property "type" should be equal to "bank_account.updated"
    And The Mercure update property "id" should be equal to "acc57a70-0000-7000-8000-000000000001"
    And The Mercure update property "bankId" should be equal to "11111111-1111-7000-8000-000000000003"

  Scenario: A redundant transition to the current status is an idempotent no-op with no event
    Given I execute the SQL query "INSERT INTO bank_account (id, bank_id, holder_name, iban, bic, alias, currency, status, created_at, updated_at) VALUES ('acc57a70-0000-7000-8000-000000000002', '11111111-1111-7000-8000-000000000003', 'Steady Holder', 'GB82WEST12345698765432', NULL, NULL, 'EUR', 'ACTIVE', NOW(), NOW())" on connection "seed"
    And the stored events are cleared
    When I send a PATCH request to "/backoffice/bank-accounts/acc57a70-0000-7000-8000-000000000002/status" with body:
    """
    {
      "status": "ACTIVE"
    }
    """
    Then the response status code should be 200
    And the JSON node "data.status" should be equal to "ACTIVE"
    And there should be 0 events stored for aggregate "acc57a70-0000-7000-8000-000000000002" named "erpify.backoffice.bankaccount.status_changed"
    And 0 outbox events were created on the queue "async"

  Scenario: Transition an account that does not exist returns a 404 bank-account-not-found Problem Details body
    Given the stored events are cleared
    When I send a PATCH request to "/backoffice/bank-accounts/2e6d865c-17b0-476a-85f2-037bf6d3b3dc/status" with body:
    """
    {
      "status": "CLOSED"
    }
    """
    Then the response status code should be 404
    And the header "Content-Type" should be equal to "application/problem+json"
    And the JSON node "type" should be equal to "bank-account-not-found"
    And the JSON node "status" should be equal to the number 404
    And there should be 0 events stored for aggregate "2e6d865c-17b0-476a-85f2-037bf6d3b3dc" named "erpify.backoffice.bankaccount.status_changed"

  Scenario Outline: A malformed id returns a 400 invalid-uuid Problem Details body
    When I send a PATCH request to "/backoffice/bank-accounts/<id>/status" with body:
    """
    {
      "status": "CLOSED"
    }
    """
    Then the response status code should be 400
    And the JSON node "type" should be equal to "invalid-uuid"
    And the JSON node "status" should be equal to the number 400

    Examples:
      | id         |
      | not-a-uuid |
      | 123        |

  # The out-of-enum value is rejected at payload mapping, before the account is even looked up, so no
  # seeded row is needed.
  Scenario: An out-of-enum status value returns a 422 validation-failed body
    Given the stored events are cleared
    When I send a PATCH request to "/backoffice/bank-accounts/acc57a70-0000-7000-8000-000000000003/status" with body:
    """
    {
      "status": "FROZEN"
    }
    """
    Then the response status code should be 422
    And the JSON node "type" should be equal to "validation-failed"
    And there should be 0 events stored for aggregate "acc57a70-0000-7000-8000-000000000003" named "erpify.backoffice.bankaccount.status_changed"
