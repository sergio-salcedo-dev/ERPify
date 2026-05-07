Feature: Per-error `instance` UUIDv7 and body↔header correlation-id reconciliation
    As a support engineer
    In order to find the exact log entry for a user-reported failure
    I need every error response body to carry a fresh UUIDv7 `instance` per occurrence
    and the body's `correlation-id` field to equal the X-Correlation-Id response header.

  # The default Behat suite's HttpRequestContext is constructor-bound to baseUrl=/api/v1
  # (see api/tools/behat/behat.yml.dist). Test routes under /api/test/_throw-* are reached
  # via absolute URLs (HttpRequestContext skips the prepend when the URL starts with `http`).

  Background:
    Given I add "Accept" header equal to "application/json"

  Scenario: A 4xx error body carries a fresh UUIDv7 `instance` distinct from `correlation-id`
    When I send a "GET" request to "http://localhost/api/test/_throw-not-found"
    Then the response status code should be 404
    And the header "Content-Type" should be equal to "application/problem+json"
    And the JSON node "instance" should match "/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/"
    And the JSON node "correlation-id" should match "/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/"
    And the JSON node "instance" should not be equal to the JSON node "correlation-id"

  Scenario: A 4xx error body's `correlation-id` equals the X-Correlation-Id response header (no inbound)
    When I send a "GET" request to "http://localhost/api/test/_throw-not-found"
    Then the response status code should be 404
    And the JSON node "correlation-id" should be equal to the response header "X-Correlation-Id"

  Scenario: A 4xx error body's `correlation-id` equals the inbound X-Correlation-Id verbatim
    Given I add "X-Correlation-Id" header equal to "0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1a2c"
    When I send a "GET" request to "http://localhost/api/test/_throw-not-found"
    Then the response status code should be 404
    And the JSON node "correlation-id" should be equal to "0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1a2c"
    And the header "X-Correlation-Id" should be equal to "0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1a2c"
    And the JSON node "instance" should not be equal to "0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1a2c"

  Scenario: A 5xx unhandled-exception body carries a fresh UUIDv7 `instance` and reconciled `correlation-id`
    When I send a "GET" request to "http://localhost/api/test/_throw-runtime"
    Then the response status code should be 500
    And the header "Content-Type" should be equal to "application/problem+json"
    And the JSON node "instance" should match "/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/"
    And the JSON node "correlation-id" should be equal to the response header "X-Correlation-Id"
