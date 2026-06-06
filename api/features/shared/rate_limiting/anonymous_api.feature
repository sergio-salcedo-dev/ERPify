Feature: Anonymous API rate limiting
    As an operator of the public HTTP surface
    In order to throttle abusive clients while keeping legitimate ones informed
    I need /api/* requests to be capped per IP, with the budget surfaced via standard
    RateLimit-* headers on every response and a conforming RFC 9457 429 envelope
    (with Retry-After) returned once the budget is exhausted.

  Scenario: Accepted request stamps both header families and does not emit Retry-After
    Given I add "Accept" header equal to "application/json"
    When I send a "GET" request to "/backoffice/health"
    Then the response status code should be 200
    # The IETF draft RateLimit-* family.
    And the header "RateLimit-Limit" should be equal to "5"
    And the header "RateLimit-Remaining" should be equal to "4"
    And the header "RateLimit-Reset" should exist
    # The legacy X-RateLimit-* family (GitHub/Stripe/Shopify-compatible).
    And the header "X-RateLimit-Limit" should be equal to "5"
    And the header "X-RateLimit-Remaining" should be equal to "4"
    And the header "X-RateLimit-Reset" should exist
    # Retry-After only ships on the rejected path (RFC 9110 §10.2.3).
    And the header "Retry-After" should not exist
    And 0 requests got executed across all doctrine connections

  Scenario: Request past the budget returns RFC 9457 429 with Retry-After
    Given the anonymous API rate-limit budget is exhausted for client "127.0.0.1"
    And I add "Accept" header equal to "application/json"
    When I send a "GET" request to "/backoffice/health"
    Then the response status code should be 429
    And the header "Content-Type" should be equal to "application/problem+json"
    And the header "Cache-Control" should contain "no-store"
    # Retry-After mandatory on the rejected path and must be >= 1s.
    And the header "Retry-After" should exist
    And the header "Retry-After" should match "/^[1-9]\d*$/"
    # Both header families still stamped on the 429 — drained but stable.
    And the header "RateLimit-Limit" should be equal to "5"
    And the header "RateLimit-Remaining" should be equal to "0"
    And the header "X-RateLimit-Limit" should be equal to "5"
    And the header "X-RateLimit-Remaining" should be equal to "0"
    # Body — RFC 9457 Problem Details + the limiter's extension keys.
    And the JSON node "type" should be equal to "rate-limited"
    And the JSON node "title" should be equal to "Rate limit exceeded."
    And the JSON node "status" should be equal to the number 429
    And the JSON node "limit" should be equal to the number 5
    And the JSON node "remaining" should be equal to the number 0
    And the JSON node "retryAfterSeconds" should exist
    And the JSON node "instance" should match "/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[0-9a-f]{4}-[0-9a-f]{12}$/"
    And the JSON node "correlation-id" should match "/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[0-9a-f]{4}-[0-9a-f]{12}$/"
    And 0 requests got executed across all doctrine connections
