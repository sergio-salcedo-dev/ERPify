Feature: Create a bank account
  As an API consumer
  In order to manage the accounts of a bank
  I need to be able to create a bank account

  Background:
    Given I add "Content-Type" header equal to "application/json"
    And I add "Accept" header equal to "application/json"

  Scenario: Successfully create a bank account for an existing bank
    When I send a POST request to "/backoffice/bank-accounts" with body:
    """
    {
      "bankId": "11111111-1111-7000-8000-000000000003",
      "holderName": "Acme Holdings",
      "iban": "GB82WEST12345698765432",
      "bic": "westgb22xxx",
      "alias": "Acme Ops",
      "currency": "EUR"
    }
    """
    Then the response status code should be 201
    And the JSON node "data" should have 10 elements
    And the JSON node "data.id" should not be null
    And the JSON node "data.bankId" should be equal to "11111111-1111-7000-8000-000000000003"
    And the JSON node "data.holderName" should be equal to "Acme Holdings"
    And the JSON node "data.iban" should be equal to "GB82WEST12345698765432"
    And the JSON node "data.bic" should be equal to "WESTGB22XXX"
    And the JSON node "data.alias" should be equal to "Acme Ops"
    And the JSON node "data.currency" should be equal to "EUR"
    And the JSON node "data.status" should be equal to "ACTIVE"
    And the JSON node "data.createdAt" should not be null
    And the JSON node "data.updatedAt" should not be null
    And there should be 1 event stored named "erpify.backoffice.bankaccount.created"
    And 1 outbox event was created on the queue "async"
    And I got the event number 1 on queue "async" from the outbox
    And The outbox event should be of type "Erpify\Backoffice\BankAccount\Domain\Event\BankAccountCreatedDomainEvent"
    And The outbox event property "bankId" should be equal to "11111111-1111-7000-8000-000000000003"
    And The outbox event property "status" should be equal to "ACTIVE"
    And I consume 1 message from the "async" transport
    And the last run should succeed
    And the last run output should contain "handled successfully (acknowledging to transport)"
    And 0 outbox events were created on the queue "async"
    # The account broadcast never sends email — only the realtime refetch signal is published.
    And 0 notification emails were sent
    And 1 Mercure update was published
    And The Mercure update should have 3 topics
    And The Mercure update topic 1 should be equal to "urn:erpify:backoffice:bankaccounts"
    And The Mercure update topic 2 should be equal to "urn:erpify:backoffice:bank:11111111-1111-7000-8000-000000000003:accounts"
    And The Mercure update property "type" should be equal to "bank_account.created"
    And The Mercure update property "bankId" should be equal to "11111111-1111-7000-8000-000000000003"

  # Grouping is what makes the typed value longer than the value the system keeps: this Maltese IBAN is
  # 31 characters canonical and 38 as a statement prints it, past the ISO 13616 ceiling the aggregate
  # asserts over the canonical form — and the BIC beside it is 11 against 13. The wire contract is
  # therefore whatever the field's own constraint accepts, never what fits the column. Only a direct API
  # client reaches this shape: the PWA compacts both fields before it validates them.
  Scenario: Create an account from the grouped spelling a bank statement carries
    When I send a POST request to "/backoffice/bank-accounts" with body:
    """
    {
      "bankId": "11111111-1111-7000-8000-000000000003",
      "holderName": "Valletta Holdings",
      "iban": "MT84 MALT 0110 0001 2345 MTLC AST0 01S",
      "bic": "VALL MTMT XXX",
      "currency": "EUR"
    }
    """
    Then the response status code should be 201
    And the JSON node "data.iban" should be equal to "MT84MALT011000012345MTLCAST001S"
    And the JSON node "data.bic" should be equal to "VALLMTMTXXX"
    And I execute the SQL query "SELECT iban, bic FROM bank_account WHERE holder_name = 'Valletta Holdings'"
    And the SQL result as JSON should be:
    """
    [
      {
        "iban": "MT84MALT011000012345MTLCAST001S",
        "bic": "VALLMTMTXXX"
      }
    ]
    """

  # The transport declares no width for `iban`, so the guard against a value that is not an IBAN is the
  # field's own constraint — which refuses it at any length, with its own message. What must never
  # happen is the column deciding: that would be a 500 on a request the edge should have refused.
  Scenario: Fail to create an account with a value that is not an IBAN at any length
    Given the stored events are cleared
    When I send a POST request to "/backoffice/bank-accounts" with body:
    """
    {
      "bankId": "11111111-1111-7000-8000-000000000003",
      "holderName": "Overlong Holder",
      "iban": "ES91210004184502000513320000000000000000"
    }
    """
    Then the response status code should be 422
    And the header "Content-Type" should be equal to "application/problem+json"
    And the JSON node "type" should be equal to "validation-failed"
    And the JSON node "violations" should have 1 element
    And the JSON node "violations[0].field" should be equal to "iban"
    And the JSON node "violations[0].message" should be equal to "This is not a valid IBAN."
    And there should be 0 events stored named "erpify.backoffice.bankaccount.created"

  Scenario: It should fail when the payload is empty
    Given the stored events are cleared
    And I reset the stats for all doctrine connections
    When I send a POST request to "/backoffice/bank-accounts" with body:
    """
    {}
    """
    Then the response status code should be 422
    And the header "Content-Type" should be equal to "application/problem+json"
    And the JSON node "type" should be equal to "validation-failed"
    And the JSON node "status" should be equal to the number 422
    And the JSON node "violations" should have 3 elements
    And the JSON node "violations[0].field" should be equal to "bankId"
    And the JSON node "violations[0].message" should be equal to "The bankId field is required."
    And the JSON node "violations[1].field" should be equal to "holderName"
    And the JSON node "violations[1].message" should be equal to "The holderName field is required."
    And the JSON node "violations[2].field" should be equal to "iban"
    And the JSON node "violations[2].message" should be equal to "The iban field is required."
    And 0 requests got executed across all doctrine connections
    And there should be 0 events stored named "erpify.backoffice.bankaccount.created"

  Scenario: Fail to create an account with a malformed bank id
    Given the stored events are cleared
    And I reset the stats for all doctrine connections
    When I send a POST request to "/backoffice/bank-accounts" with body:
    """
    {
      "bankId": "not-a-uuid",
      "holderName": "Acme Holdings",
      "iban": "ES9121000418450200051332"
    }
    """
    Then the response status code should be 422
    And the JSON node "type" should be equal to "validation-failed"
    And the JSON node "violations" should have 1 element
    And the JSON node "violations[0].field" should be equal to "bankId"
    And 0 requests got executed across all doctrine connections
    And there should be 0 events stored named "erpify.backoffice.bankaccount.created"

  Scenario: Fail to create an account with an IBAN already in use
    Given the stored events are cleared
    When I send a POST request to "/backoffice/bank-accounts" with body:
    """
    {
      "bankId": "11111111-1111-7000-8000-000000000003",
      "holderName": "Duplicate Holder",
      "iban": "DE89370400440532013000"
    }
    """
    Then the response status code should be 422
    And the JSON node "type" should be equal to "validation-failed"
    And the JSON node "violations" should have 1 element
    And the JSON node "violations[0].field" should be equal to "iban"
    And the JSON node "violations[0].message" should be equal to "This IBAN is already in use."
    And there should be 0 events stored named "erpify.backoffice.bankaccount.created"

  Scenario: Fail to create an account whose BIC country does not match the IBAN country
    Given the stored events are cleared
    When I send a POST request to "/backoffice/bank-accounts" with body:
    """
    {
      "bankId": "11111111-1111-7000-8000-000000000003",
      "holderName": "Mismatch Holder",
      "iban": "ES9121000418450200051332",
      "bic": "DEUTDEFFXXX"
    }
    """
    Then the response status code should be 422
    And the JSON node "type" should be equal to "validation-failed"
    And the JSON node "violations" should have 1 element
    And the JSON node "violations[0].field" should be equal to "bic"
    And there should be 0 events stored named "erpify.backoffice.bankaccount.created"

  Scenario: Fail to create an account for a bank that does not exist
    Given the stored events are cleared
    When I send a POST request to "/backoffice/bank-accounts" with body:
    """
    {
      "bankId": "2e6d865c-17b0-476a-85f2-037bf6d3b3dc",
      "holderName": "Orphan Holder",
      "iban": "ES9121000418450200051332"
    }
    """
    Then the response status code should be 404
    And the header "Content-Type" should be equal to "application/problem+json"
    And the JSON node "type" should be equal to "bank-not-found"
    And the JSON node "status" should be equal to the number 404
    And the JSON node "bankId" should be equal to "2e6d865c-17b0-476a-85f2-037bf6d3b3dc"
    And there should be 0 events stored named "erpify.backoffice.bankaccount.created"
