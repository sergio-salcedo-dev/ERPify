Feature: Frontoffice application health
    As an API consumer
    In order to know the API is up
    I need a health check endpoint to hit

  Scenario: Health check is reachable
    When I go to "/api/v1/health"
    Then the response status code should be 200
