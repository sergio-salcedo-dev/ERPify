Feature: Backoffice database health
    As an authenticated back-office operator
    In order to know the database backing the back office is reachable
    I need a database health check endpoint that probes it for me and for nobody else

  @anonymous
  Scenario: The database probe refuses an unauthenticated caller
    Given I add "Accept" header equal to "application/json"
    When I send a "GET" request to "/backoffice/health/database"
    Then the response status code should be 401
    And the header "Content-Type" should contain "application/problem+json"
    And the JSON node "type" should be equal to "unauthenticated"

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
