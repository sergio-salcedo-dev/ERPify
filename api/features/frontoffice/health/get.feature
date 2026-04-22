Feature: Frontoffice application health
    As an API consumer
    In order to know the API is up
    I need a health check endpoint to hit

  Scenario: Health check is reachable
    Given I add "Content-Type" header equal to "application/json"
    And I add "Accept" header equal to "application/json"
    When I send a "GET" request to "/health"
    Then the response status code should be 200
    And the JSON node "data" should have 3 elements
    And the JSON node "data.status" should be equal to "ok"
    And the JSON node "data.service" should be equal to "Front office"
    And the JSON node "data.datetime" should not be null
