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

  Scenario: Fail to create a bank with missing fields
    When I send a POST request to "/backoffice/banks" with body:
    """
    {"name": "Incomplete Bank"}
    """
    Then the response status code should be 400
