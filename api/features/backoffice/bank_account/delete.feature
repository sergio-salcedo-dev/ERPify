Feature: Delete a bank account
  As an API consumer
  In order to retire a closed account
  I need to be able to delete a bank account

  # Seed a CLOSED account out-of-band via raw SQL on a side connection the query counter ignores, so the
  # scenario isolates the delete path from the cost of creating the account. Only a CLOSED account is
  # deletable; the precondition lives in the aggregate.
  Scenario: Successfully delete a CLOSED bank account
    Given I execute the SQL query "INSERT INTO bank_account (id, bank_id, holder_name, iban, bic, alias, currency, status, created_at, updated_at) VALUES ('acc1de00-0000-7000-8000-000000000001', '11111111-1111-7000-8000-000000000003', 'Closing Holder', 'FR7630006000011234567890189', NULL, NULL, 'EUR', 'CLOSED', NOW(), NOW())" on connection "seed"
    And there should have 1 "Erpify\Backoffice\BankAccount\Domain\Entity\BankAccount" entities found by "id=acc1de00-0000-7000-8000-000000000001"
    When I send a "DELETE" request to "/backoffice/bank-accounts/acc1de00-0000-7000-8000-000000000001"
    Then the response status code should be 204
    And the "Erpify\Backoffice\BankAccount\Domain\Entity\BankAccount" entity found by "id=acc1de00-0000-7000-8000-000000000001" does not exist
    And there should be 1 event stored for aggregate "acc1de00-0000-7000-8000-000000000001" named "erpify.backoffice.bankaccount.deleted"
    And 1 outbox event was created on the queue "async"
    And I got the event number 1 on queue "async" from the outbox
    And The outbox event should be of type "Erpify\Backoffice\BankAccount\Domain\Event\BankAccountDeletedDomainEvent"
    And The outbox event aggregate id should be equal to "acc1de00-0000-7000-8000-000000000001"
    And The outbox event property "bankId" should be equal to "11111111-1111-7000-8000-000000000003"
    And I consume 1 message from the "async" transport
    And the command should succeed
    And the output should contain "handled successfully (acknowledging to transport)"
    And 0 outbox events were created on the queue "async"
    And 0 notification emails were sent
    And 1 Mercure update was published
    And The Mercure update should have 3 topics
    And The Mercure update topic 1 should be equal to "urn:erpify:backoffice:bankaccounts"
    And The Mercure update topic 2 should be equal to "urn:erpify:backoffice:bank:11111111-1111-7000-8000-000000000003:accounts"
    And The Mercure update topic 3 should be equal to "urn:erpify:backoffice:bankaccount:acc1de00-0000-7000-8000-000000000001"
    And The Mercure update property "type" should be equal to "bank_account.deleted"
    And The Mercure update property "id" should be equal to "acc1de00-0000-7000-8000-000000000001"
    And The Mercure update property "bankId" should be equal to "11111111-1111-7000-8000-000000000003"

  Scenario: Deleting a non-CLOSED account returns a 409 bank-account-not-closed Problem Details body
    Given the stored events are cleared
    And there should have 1 "Erpify\Backoffice\BankAccount\Domain\Entity\BankAccount" entities found by "id=33333333-3333-7000-8000-000000000002"
    When I send a "DELETE" request to "/backoffice/bank-accounts/33333333-3333-7000-8000-000000000002"
    Then the response status code should be 409
    And the header "Content-Type" should be equal to "application/problem+json"
    And the header "Cache-Control" should contain "no-store"
    And the JSON node "type" should be equal to "bank-account-not-closed"
    And the JSON node "title" should be equal to "Cannot delete the bank account because it is not closed."
    And the JSON node "status" should be equal to the number 409
    And the JSON node "bankAccountId" should be equal to "33333333-3333-7000-8000-000000000002"
    And the JSON node "instance" should be a valid UUID
    And the JSON node "correlation-id" should be a valid UUID
    And there should have 1 "Erpify\Backoffice\BankAccount\Domain\Entity\BankAccount" entities found by "id=33333333-3333-7000-8000-000000000002"
    And there should be 0 events stored for aggregate "33333333-3333-7000-8000-000000000002" named "erpify.backoffice.bankaccount.deleted"

  Scenario: Delete an account that does not exist returns a 404 bank-account-not-found Problem Details body
    When I send a "DELETE" request to "/backoffice/bank-accounts/2e6d865c-17b0-476a-85f2-037bf6d3b3dc"
    Then the response status code should be 404
    And the header "Content-Type" should be equal to "application/problem+json"
    And the JSON node "type" should be equal to "bank-account-not-found"
    And the JSON node "status" should be equal to the number 404
    And the JSON node "bankAccountId" should be equal to "2e6d865c-17b0-476a-85f2-037bf6d3b3dc"

  Scenario Outline: Delete an account with a malformed id returns a 400 invalid-uuid Problem Details body
    When I send a "DELETE" request to "/backoffice/bank-accounts/<accountId>"
    Then the response status code should be 400
    And the JSON node "type" should be equal to "invalid-uuid"
    And the JSON node "status" should be equal to the number 400
    And 0 requests got executed across all doctrine connections
    Examples:
      | accountId   |
      | null        |
      | invalidUuid |
