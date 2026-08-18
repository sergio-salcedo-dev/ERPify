Feature: Frontoffice application health
    As an API consumer
    In order to know the API is up
    I need a health check endpoint to hit

  # The one route deliberately left outside the firewall. Its anonymous callers cannot present a session:
  # the deploy smoke test curls it from a shell (scripts/deploy/deploy.sh), and the public /status page
  # mounts outside RequireAuth. Retiring the exemption breaks both, so the anonymity is pinned here rather
  # than left to be inferred from security.yaml.
  @anonymous
  Scenario: The liveness route answers an unauthenticated caller
    Given I add "Accept" header equal to "application/json"
    When I send a "GET" request to "/health"
    Then the response status code should be 200
    And the JSON node "data.status" should be equal to "ok"
    And 0 requests got executed across all doctrine connections

  Scenario: Health check is reachable
    Given I add "Content-Type" header equal to "application/json"
    And I add "Accept" header equal to "application/json"
    When I send a "GET" request to "/health"
    Then the response status code should be 200
    And the JSON node "data" should have 3 elements
    And the JSON node "data.status" should be equal to "ok"
    And the JSON node "data.service" should be equal to "Front office"
    And the JSON node "data.datetime" should not be null
    And 0 requests got executed across all doctrine connections
