Feature: X-Correlation-Id response header on every API response
    As an on-call engineer
    In order to recover the correlation-id from any captured response
    I need every /api/* response (success and error) to carry an X-Correlation-Id header
    holding the per-request UUIDv7 the server minted — never a value the caller supplied.

  # The default Behat suite's HttpRequestContext is constructor-bound to baseUrl=/api/v1
  # (see api/behat.dist.php). Routes under /api/test/_throw-* are reached via
  # absolute URLs (HttpRequestContext skips the prepend when the URL starts with `http`).

  Background:
    Given I add "Accept" header equal to "application/json"

  Scenario: A 2xx response carries a freshly-minted X-Correlation-Id header
    When I send a "GET" request to "/health"
    Then the response status code should be 200
    And the header "X-Correlation-Id" should be a valid UUID
    And 0 requests got executed across all doctrine connections

  Scenario: A 4xx response carries a freshly-minted X-Correlation-Id header
    When I send a "GET" request to "http://localhost/api/test/_throw-not-found"
    Then the response status code should be 404
    And the header "Content-Type" should be equal to "application/problem+json"
    And the header "X-Correlation-Id" should be a valid UUID
    And 0 requests got executed across all doctrine connections

  # The value a caller sends is dropped whatever its shape, so the canonical one is the only case worth
  # a wire scenario: a malformed value and a well-formed one now take the same path, and the shapes that
  # used to be told apart are pinned as data in CorrelationIdListenerTest. What a caller could otherwise
  # buy with a well-formed value is the id the audit trail groups by.
  Scenario: An inbound X-Correlation-Id header is ignored on a 2xx
    Given I add "X-Correlation-Id" header equal to "0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1a2c"
    When I send a "GET" request to "/health"
    Then the response status code should be 200
    And the header "X-Correlation-Id" should be a valid UUID
    And the header "X-Correlation-Id" should not be equal to "0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1a2c"
    And 0 requests got executed across all doctrine connections

  Scenario: An inbound X-Correlation-Id header is ignored on a 4xx
    Given I add "X-Correlation-Id" header equal to "0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1a2c"
    When I send a "GET" request to "http://localhost/api/test/_throw-not-found"
    Then the response status code should be 404
    And the header "X-Correlation-Id" should be a valid UUID
    And the header "X-Correlation-Id" should not be equal to "0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1a2c"
    And 0 requests got executed across all doctrine connections
