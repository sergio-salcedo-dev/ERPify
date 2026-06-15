Feature: Backoffice database health
    As an API consumer
    In order to know the database backing the back office is reachable
    I need a database health check endpoint to hit

  Scenario: Database health check reports the database is reachable
    Given I add "Content-Type" header equal to "application/json"
    And I add "Accept" header equal to "application/json"
    When I send a "GET" request to "/backoffice/health/database"
    Then the response status code should be 200
    And the JSON node "data" should have 3 elements
    And the JSON node "data.status" should be equal to "ok"
    And the JSON node "data.service" should be equal to "Database"
    And the JSON node "data.datetime" should not be null
    And a request contains "SELECT 1" across all doctrine connections
