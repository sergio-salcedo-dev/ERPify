Feature: Domain events are recorded on a bank write
  In order to trust the event-driven outbox seam
  As the platform
  I need every bank write to record its domain event and log failures to the error contract

  Background:
    Given I add "Content-Type" header equal to "application/json"
    And I add "Accept" header equal to "application/json"

  Scenario: Creating a bank stores its domain event with the full payload
    When I send a POST request to "/backoffice/banks" with body:
    """
    {"name": "Event Bus Bank", "shortName": "EBB"}
    """
    Then the response status code should be 201
    And 1 domain event named "erpify.backoffice.bank.created" should be stored
    And the stored domain event "erpify.backoffice.bank.created" body "name" should be equal to "Event Bus Bank"
    And the stored domain event "erpify.backoffice.bank.created" body "shortName" should be equal to "EBB"

  Scenario: The created-bank event is enqueued on the async transport
    When I send a POST request to "/backoffice/banks" with body:
    """
    {"name": "Async Bank", "shortName": "ASB"}
    """
    Then the response status code should be 201
    And 1 message was sent to "async"
    And message 1 sent to "async" is instance of "Erpify\Backoffice\Bank\Domain\Event\BankCreatedDomainEvent"
    And message 1 sent to "async" should have field "shortName" with value "ASB"

  Scenario: A rejected write logs the RFC 9457 error line with its SRE context
    When I send a POST request to "/backoffice/banks" with body:
    """
    {}
    """
    Then the response status code should be 422
    And the logger logged a log entry as "warning" with message "API error response built"
    And the logger logged a log entry as "warning" with message "API error response built" and context contains:
      | type   | validation-failed |
      | status | 422               |
