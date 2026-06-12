Feature: Create a bank
  As an API consumer
  In order to manage banks
  I need to be able to create a bank

  Background:
    Given I add "Content-Type" header equal to "application/json"
    And I add "Accept" header equal to "application/json"

  Scenario: Successfully create a bank
    When I send a POST request to "/backoffice/banks" with body:
    """
    {"name": "Test Bank", "shortName": "TB"}
    """
    Then the response status code should be 201
#    And I remember the JSON field "id" as "bankId"
#    And a domain event named "erpify.backoffice.bank.created" should be recorded for aggregate {bankId}
#    And I process pending async messenger messages
#    And the async messenger transport should be empty
#    And the messenger failed transport should be empty
#    And the last bank created notification email should mention event "erpify.backoffice.bank.created"
    And the response should contain "Test Bank"
    And the response should contain "TB"
    And the JSON node "data.accountCount" should not exist
    And 6 requests got executed only for doctrine connection "default"

  Scenario: Fail to create a bank with missing fields
    When I send a POST request to "/backoffice/banks" with body:
    """
    {"name": "Incomplete Bank"}
    """
    Then the response status code should be 422
    And 0 requests got executed across all doctrine connections

  Scenario: Fail to create a bank whose name only differs in case from an existing one
    When I send a POST request to "/backoffice/banks" with body:
    """
    {"name": "bbva", "shortName": "BB"}
    """
    Then the response status code should be 422
    And the JSON node "type" should be equal to "validation-failed"
    And the JSON node "violations[0].field" should be equal to "name"
    And 2 requests got executed only for doctrine connection "default"

  Scenario: Fail to create a bank whose short name only differs in case from an existing one
    When I send a POST request to "/backoffice/banks" with body:
    """
    {"name": "Some New Bank", "shortName": "bbva"}
    """
    Then the response status code should be 422
    And the JSON node "type" should be equal to "validation-failed"
    And the JSON node "violations[0].field" should be equal to "shortName"
    And 2 requests got executed only for doctrine connection "default"

  Scenario: Fail to create a bank whose name only differs in diacritics from an existing one
    When I send a POST request to "/backoffice/banks" with body:
    """
    {"name": "Sociedad Anonima", "shortName": "SA2"}
    """
    Then the response status code should be 422
    And the JSON node "type" should be equal to "validation-failed"
    And the JSON node "violations[0].field" should be equal to "name"
    And 2 requests got executed only for doctrine connection "default"
