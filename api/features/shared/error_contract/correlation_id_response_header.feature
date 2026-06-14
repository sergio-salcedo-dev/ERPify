Feature: X-Correlation-Id response header on every API response
    As an on-call engineer
    In order to recover the correlation-id from any captured response
    I need every /api/* response (success and error) to carry an X-Correlation-Id header
    echoing the per-request UUIDv7 minted by CorrelationIdListener.

  # The default Behat suite's HttpRequestContext is constructor-bound to baseUrl=/api/v1
  # (see api/tools/behat/behat.yml.dist). Routes under /api/test/_throw-* are reached via
  # absolute URLs (HttpRequestContext skips the prepend when the URL starts with `http`).

  Background:
    Given I add "Accept" header equal to "application/json"

  Scenario: A 2xx response carries a freshly-minted X-Correlation-Id header
    When I send a "GET" request to "/health"
    Then the response status code should be 200
    And the header "X-Correlation-Id" should be a UUID v7
    And 0 requests got executed across all doctrine connections

  Scenario: A 4xx response carries a freshly-minted X-Correlation-Id header
    When I send a "GET" request to "http://localhost/api/test/_throw-not-found"
    Then the response status code should be 404
    And the header "Content-Type" should be equal to "application/problem+json"
    And the header "X-Correlation-Id" should be a UUID v7
    And 0 requests got executed across all doctrine connections

  Scenario: A valid inbound X-Correlation-Id header is echoed verbatim on a 2xx
    Given I add "X-Correlation-Id" header equal to "0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1a2c"
    When I send a "GET" request to "/health"
    Then the response status code should be 200
    And the header "X-Correlation-Id" should be equal to "0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1a2c"
    And 0 requests got executed across all doctrine connections

  Scenario: A valid inbound X-Correlation-Id header is echoed verbatim on a 4xx
    Given I add "X-Correlation-Id" header equal to "0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1a2c"
    When I send a "GET" request to "http://localhost/api/test/_throw-not-found"
    Then the response status code should be 404
    And the header "X-Correlation-Id" should be equal to "0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1a2c"
    And 0 requests got executed across all doctrine connections

  Scenario: A malformed inbound X-Correlation-Id header is replaced with a freshly-minted UUIDv7
    Given I add "X-Correlation-Id" header equal to "not-a-uuid"
    When I send a "GET" request to "/health"
    Then the response status code should be 200
    And the header "X-Correlation-Id" should be a UUID v7
    And the header "X-Correlation-Id" should not be equal to "not-a-uuid"
    And 0 requests got executed across all doctrine connections

  Scenario: An uppercase well-formed UUIDv7 inbound header is replaced with a fresh lowercase UUIDv7
    Given I add "X-Correlation-Id" header equal to "0190E9C2-7B5A-7D40-9C8F-2F9B5D3E1A2C"
    When I send a "GET" request to "/health"
    Then the response status code should be 200
    And the header "X-Correlation-Id" should be a UUID v7
    And the header "X-Correlation-Id" should not be equal to "0190E9C2-7B5A-7D40-9C8F-2F9B5D3E1A2C"
    And 0 requests got executed across all doctrine connections
