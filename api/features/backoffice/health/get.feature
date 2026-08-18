Feature: Backoffice application health
    As an authenticated back-office operator
    In order to know the back office is up
    I need a health check endpoint that answers me and nobody else

  @anonymous
  Scenario: The back-office liveness route refuses an unauthenticated caller
    Given I add "Accept" header equal to "application/json"
    When I send a "GET" request to "/backoffice/health"
    Then the response status code should be 401
    And the header "Content-Type" should contain "application/problem+json"
    And the JSON node "type" should be equal to "unauthenticated"
    And the JSON node "data" should not exist

  Scenario: Health check is reachable
    Given I add "Content-Type" header equal to "application/json"
    And I add "Accept" header equal to "application/json"
    When I send a "GET" request to "/backoffice/health"
    Then the response status code should be 200
    And the JSON node "data" should have 3 elements
    And the JSON node "data.status" should be equal to "ok"
    And the JSON node "data.service" should be equal to "Back office"
    And the JSON node "data.datetime" should not be null
    And 0 requests got executed across all doctrine connections
