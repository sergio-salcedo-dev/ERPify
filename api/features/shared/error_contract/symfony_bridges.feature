Feature: Symfony framework exceptions surface through the RFC 9457 error contract
    As a PWA developer
    In order to route errors by `type` alone
    I need Symfony's HttpExceptionInterface, AccessDeniedException, and AuthenticationException
    to produce Problem Details responses with the canonical type and status

  # Routes are wired at /api/test/_throw-* and /api/test/_throw-http-*,
  # /api/test/_throw-security-* . The default Behat suite's HttpRequestContext is
  # constructor-bound to baseUrl=/api/v1 (FriendsOfBehat\SymfonyExtension reuses the same
  # service instance across suites, so a per-suite override does not take effect). To bypass
  # the baseUrl prefix entirely, scenarios use absolute URLs (HttpRequestContext skips the
  # prepend when the URL starts with `http`).

  Background:
    Given I add "Accept" header equal to "application/json"

  Scenario: Symfony AccessDeniedHttpException is mapped to a 403 forbidden Problem Details body
    When I send a "GET" request to "http://localhost/api/test/_throw-http-403"
    Then the response status code should be 403
    And the header "Content-Type" should be equal to "application/problem+json"
    And the header "Cache-Control" should contain "no-store"
    And the response should be in JSON
    And the JSON node "type" should be equal to "forbidden"
    And the JSON node "status" should be equal to the number 403
    And the JSON node "title" should be equal to "Access denied to bank."
    And the JSON node "instance" should be a valid UUID version 7
    And the JSON node "correlation-id" should be a valid UUID version 7
    And 0 requests got executed across all doctrine connections

  Scenario: Symfony UnauthorizedHttpException is mapped to a 401 unauthenticated Problem Details body
    When I send a "GET" request to "http://localhost/api/test/_throw-http-401"
    Then the response status code should be 401
    And the header "Content-Type" should be equal to "application/problem+json"
    And the header "Cache-Control" should contain "no-store"
    And the response should be in JSON
    And the JSON node "type" should be equal to "unauthenticated"
    And the JSON node "status" should be equal to the number 401
    And the JSON node "title" should be equal to "Token expired."
    And the JSON node "instance" should be a valid UUID version 7
    And the JSON node "correlation-id" should be a valid UUID version 7
    And 0 requests got executed across all doctrine connections

  Scenario: Symfony HttpException with an unmapped status code falls back to the generic http-error type
    When I send a "GET" request to "http://localhost/api/test/_throw-http-410"
    Then the response status code should be 410
    And the header "Content-Type" should be equal to "application/problem+json"
    And the response should be in JSON
    And the JSON node "type" should be equal to "http-error"
    And the JSON node "status" should be equal to the number 410
    And the JSON node "title" should be equal to "Resource gone."
    And the JSON node "instance" should be a valid UUID version 7
    And the JSON node "correlation-id" should be a valid UUID version 7
    And 0 requests got executed across all doctrine connections

  Scenario: Security Core AccessDeniedException (unwrapped) is mapped to forbidden
    When I send a "GET" request to "http://localhost/api/test/_throw-security-access-denied"
    Then the response status code should be 403
    And the header "Content-Type" should be equal to "application/problem+json"
    And the response should be in JSON
    And the JSON node "type" should be equal to "forbidden"
    And the JSON node "status" should be equal to the number 403
    And the JSON node "title" should be equal to "Forbidden."
    And the JSON node "instance" should be a valid UUID version 7
    And the JSON node "correlation-id" should be a valid UUID version 7
    And 0 requests got executed across all doctrine connections

  Scenario: Security Core AuthenticationException (unwrapped) is mapped to unauthenticated
    When I send a "GET" request to "http://localhost/api/test/_throw-security-authentication"
    Then the response status code should be 401
    And the header "Content-Type" should be equal to "application/problem+json"
    And the response should be in JSON
    And the JSON node "type" should be equal to "unauthenticated"
    And the JSON node "status" should be equal to the number 401
    And the JSON node "title" should be equal to "Bad credentials."
    And the JSON node "instance" should be a valid UUID version 7
    And the JSON node "correlation-id" should be a valid UUID version 7
    And 0 requests got executed across all doctrine connections

  Scenario: An unhandled RuntimeException still maps to the generic 500 unhandled-exception type
    When I send a "GET" request to "http://localhost/api/test/_throw-runtime"
    Then the response status code should be 500
    And the header "Content-Type" should be equal to "application/problem+json"
    And the response should be in JSON
    And the JSON node "type" should be equal to "unhandled-exception"
    And the JSON node "status" should be equal to the number 500
    And the JSON node "title" should be equal to "boom"
    And the JSON node "instance" should be a valid UUID version 7
    And the JSON node "correlation-id" should be a valid UUID version 7
    And 0 requests got executed across all doctrine connections
