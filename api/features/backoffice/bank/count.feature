Feature: Bank count projection
  As an API consumer
  In order to show how many banks exist without a COUNT(*)
  The total is served from the bank_count read model, derived by replaying the event store

  # Proves reproducibility: the read model tracks +1 on create / -1 on delete as the events are
  # consumed (live), and a rebuild from sequence 0 lands on the same total. The store is cleared and
  # the projection rebuilt first so the starting total is a deterministic 0.
  Scenario: The bank count read model tracks creations and deletions and rebuilds to the same total
    Given I add "Content-Type" header equal to "application/json"
    And I add "Accept" header equal to "application/json"
    And the stored events are cleared
    And the "bank_count" projection is rebuilt
    When I send a "GET" request to "/backoffice/banks/count"
    Then the response status code should be 200
    And the JSON node "total" should be equal to the number 0
    And I send a POST request to "/backoffice/banks" with body:
    """
    {
      "name": "Count Alpha",
      "shortName": "CTA"
    }
    """
    And the response status code should be 201
    And I send a POST request to "/backoffice/banks" with body:
    """
    {
      "name": "Count Beta",
      "shortName": "CTB"
    }
    """
    And the response status code should be 201
    And I consume 2 messages from the "async" transport
    And the command should succeed
    And I send a "GET" request to "/backoffice/banks/count"
    And the JSON node "total" should be equal to the number 2
    And I send a "DELETE" request to "/backoffice/banks/11111111-1111-7000-8000-000000000003"
    And the response status code should be 204
    And I consume 1 message from the "async" transport
    And the command should succeed
    And I send a "GET" request to "/backoffice/banks/count"
    And the JSON node "total" should be equal to the number 1
    And I rebuild the "bank_count" projection
    And I send a "GET" request to "/backoffice/banks/count"
    And the JSON node "total" should be equal to the number 1
