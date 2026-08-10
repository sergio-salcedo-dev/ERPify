Feature: Update a bank account
  As an API consumer
  In order to keep an account's details current
  I need to be able to update a bank account

  # Seed the account out-of-band via raw SQL on a side connection the query counter ignores, so the
  # scenario isolates the update path from the cost of creating the account.
  Scenario: Successfully update a bank account
    Given I add "Content-Type" header equal to "application/json"
    And I add "Accept" header equal to "application/json"
    And I execute the SQL query "INSERT INTO bank_account (id, bank_id, holder_name, iban, bic, alias, currency, status, created_at, updated_at) VALUES ('acc1ed00-0000-7000-8000-000000000001', '11111111-1111-7000-8000-000000000003', 'Original Holder', 'ES9121000418450200051332', NULL, NULL, 'EUR', 'ACTIVE', NOW(), NOW())" on connection "seed"
    When I send a PUT request to "/backoffice/bank-accounts/acc1ed00-0000-7000-8000-000000000001" with body:
    """
    {
      "holderName": "Updated Holder",
      "iban": "ES9121000418450200051332",
      "alias": "Renamed Ops",
      "currency": "EUR"
    }
    """
    Then the response status code should be 200
    And the JSON node "data" should have 10 elements
    And the JSON node "data.id" should be equal to "acc1ed00-0000-7000-8000-000000000001"
    And the JSON node "data.bankId" should be equal to "11111111-1111-7000-8000-000000000003"
    And the JSON node "data.holderName" should be equal to "Updated Holder"
    And the JSON node "data.alias" should be equal to "Renamed Ops"
    # status is untouched by the descriptive update — it only transitions through PATCH /status.
    And the JSON node "data.status" should be equal to "ACTIVE"
    And the JSON node "data.createdAt" should not be null
    And the JSON node "data.updatedAt" should not be null
    And there should be 1 event stored for aggregate "acc1ed00-0000-7000-8000-000000000001" named "erpify.backoffice.bankaccount.updated"
    And 1 outbox event was created on the queue "async"
    And I got the event number 1 on queue "async" from the outbox
    And The outbox event should be of type "Erpify\Backoffice\BankAccount\Domain\Event\BankAccountUpdatedDomainEvent"
    And The outbox event property "status" should be equal to "ACTIVE"
    And I consume 1 message from the "async" transport
    And the last run should succeed
    And the last run output should contain "handled successfully (acknowledging to transport)"
    And 0 outbox events were created on the queue "async"
    And 0 notification emails were sent
    And 1 Mercure update was published
    And The Mercure update should have 3 topics
    And The Mercure update topic 1 should be equal to "urn:erpify:backoffice:bankaccounts"
    And The Mercure update topic 2 should be equal to "urn:erpify:backoffice:bank:11111111-1111-7000-8000-000000000003:accounts"
    And The Mercure update topic 3 should be equal to "urn:erpify:backoffice:bankaccount:acc1ed00-0000-7000-8000-000000000001"
    And The Mercure update property "type" should be equal to "bank_account.updated"
    And The Mercure update property "id" should be equal to "acc1ed00-0000-7000-8000-000000000001"
    And The Mercure update property "bankId" should be equal to "11111111-1111-7000-8000-000000000003"

  Scenario: Update an account that does not exist returns a 404 bank-account-not-found Problem Details body
    Given I add "Content-Type" header equal to "application/json"
    And I add "Accept" header equal to "application/json"
    When I send a PUT request to "/backoffice/bank-accounts/2e6d865c-17b0-476a-85f2-037bf6d3b3dc" with body:
    """
    {
      "holderName": "Ghost Holder",
      "iban": "ES9121000418450200051332",
      "currency": "EUR"
    }
    """
    Then the response status code should be 404
    And the header "Content-Type" should be equal to "application/problem+json"
    And the JSON node "type" should be equal to "bank-account-not-found"
    And the JSON node "status" should be equal to the number 404
    And the JSON node "bankAccountId" should be equal to "2e6d865c-17b0-476a-85f2-037bf6d3b3dc"
    And there should be 0 events stored for aggregate "2e6d865c-17b0-476a-85f2-037bf6d3b3dc" named "erpify.backoffice.bankaccount.updated"
