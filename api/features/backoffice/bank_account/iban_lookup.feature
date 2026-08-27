Feature: Look up a bank account by its IBAN
  As an API consumer
  In order to find a specific account without leaking its IBAN into a query string
  I need an exact-match lookup over a POST body

  Background:
    Given I add "Content-Type" header equal to "application/json"
    And I add "Accept" header equal to "application/json"

  # The value never travels as a query-string parameter, so it is never written to a proxy/access log
  # nor cached by an intermediary keyed on the URL — the non-logging counterpart to the GET-based
  # `filters[]` vocabulary, which does not carry `iban` at all (see search_collection.feature).
  Scenario: Looking up an account by its exact canonical IBAN returns the cross-bank read projection
    When I send a POST request to "/backoffice/bank-accounts/iban-lookup" with body:
    """
    {
      "iban": "DE89370400440532013000"
    }
    """
    Then the response status code should be 200
    And the JSON node "data.id" should be equal to "33333333-3333-7000-8000-000000000001"
    And the JSON node "data.bankId" should be equal to "11111111-1111-7000-8000-000000000001"
    And the JSON node "data.bankName" should be equal to "JPMorgan Chase"
    And the JSON node "data.bankShortName" should be equal to "JPM"
    And the JSON node "data.holderName" should be equal to "Globex Corporation"
    And the JSON node "data.iban" should be equal to "DE89370400440532013000"
    And the JSON node "data.bic" should be equal to "DEUTDEFFXXX"
    And the JSON node "data.alias" should be equal to "Globex Treasury"
    And the JSON node "data.currency" should be equal to "EUR"
    And the JSON node "data.status" should be equal to "INACTIVE"

  # The lookup canonicalizes the same way the write side does (Iban::canonicalize): a human-grouped,
  # lower-case, non-ASCII-separated IBAN still matches the stored compact form.
  Scenario: A space-formatted lower-case IBAN still matches via canonicalization
    When I send a POST request to "/backoffice/bank-accounts/iban-lookup" with body:
    """
    {
      "iban": "de89 3704 0044 0532 0130 00"
    }
    """
    Then the response status code should be 200
    And the JSON node "data.holderName" should be equal to "Globex Corporation"
    And the JSON node "data.iban" should be equal to "DE89370400440532013000"

  # A well-formed IBAN that matches no account is a 404, not an empty list — this is an exact-match
  # lookup, never a search.
  Scenario: Looking up a well-formed IBAN with no matching account returns 404 bank-account-not-found
    When I send a POST request to "/backoffice/bank-accounts/iban-lookup" with body:
    """
    {
      "iban": "ES9121000418450200051332"
    }
    """
    Then the response status code should be 404
    And the header "Content-Type" should be equal to "application/problem+json"
    And the header "Cache-Control" should contain "no-store"
    And the JSON node "type" should be equal to "bank-account-not-found"
    And the JSON node "title" should be equal to "Bank account with the given IBAN not found."
    And the JSON node "status" should be equal to the number 404
    And the JSON node "instance" should be a valid UUID
    And the JSON node "correlation-id" should be a valid UUID
    # The IBAN is classified PII: unlike the by-id 404 (which echoes the id in `bankAccountId`), the
    # not-found-by-IBAN body carries no trace of the value that was searched for.
    And the response should not contain "ES9121000418450200051332"
    And the response should not contain "iban"

  # A malformed IBAN is a shape/validation problem (422), never reaches the repository, and — like every
  # validation message in this codebase — the message describes the rule, never echoes the rejected value.
  Scenario: Looking up a malformed IBAN returns 422 validation-failed without echoing the value
    When I send a POST request to "/backoffice/bank-accounts/iban-lookup" with body:
    """
    {
      "iban": "not-an-iban"
    }
    """
    Then the response status code should be 422
    And the header "Content-Type" should be equal to "application/problem+json"
    And the JSON node "type" should be equal to "validation-failed"
    And the JSON node "status" should be equal to the number 422
    And the JSON node "violations[0].field" should be equal to "iban"
    And the response should not contain "not-an-iban"
    And 0 requests got executed across all doctrine connections

  Scenario: Looking up an account with a blank IBAN returns 422 validation-failed
    When I send a POST request to "/backoffice/bank-accounts/iban-lookup" with body:
    """
    {
      "iban": ""
    }
    """
    Then the response status code should be 422
    And the JSON node "type" should be equal to "validation-failed"
    And the JSON node "violations[0].field" should be equal to "iban"

  # StrictRequestPayload: an unrecognised member is refused outright rather than silently discarded.
  Scenario: An unknown payload member is refused with 422
    When I send a POST request to "/backoffice/bank-accounts/iban-lookup" with body:
    """
    {
      "iban": "DE89370400440532013000",
      "unexpected": "field"
    }
    """
    Then the response status code should be 422
    And the header "Content-Type" should be equal to "application/problem+json"
