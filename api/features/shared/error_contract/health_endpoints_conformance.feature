Feature: Health endpoints conform to the RFC 9457 contract retroactively
    As a platform reviewer
    In order to prove the Problem Details contract applies to endpoints whose authors
    predate it (FR11), the two `/health` endpoints must produce conforming Problem
    Details responses on failure WITHOUT any change to their controller source.

  # the two HealthController classes throw nothing of their own; a test-only
  # `kernel.controller` subscriber wired in `api/config/services_test.yaml` injects a
  # RuntimeException when `?_force_failure=1` is present on either health path. The
  # listener pipeline then maps that uncaught throwable to the default-deny
  # `unhandled-exception` / 500 branch.

  Scenario: Backoffice health failure path produces a conforming Problem Details 500
    Given I add "Accept" header equal to "application/json"
    When I send a "GET" request to "http://localhost/api/v1/backoffice/health?_force_failure=1"
    Then the response status code should be 500
    And the header "Content-Type" should be equal to "application/problem+json"
    And the JSON node "type" should be equal to "unhandled-exception"
    And the JSON node "status" should be equal to "500"
    And 0 requests got executed across all doctrine connections

  Scenario: Frontoffice health failure path produces a conforming Problem Details 500
    Given I add "Accept" header equal to "application/json"
    When I send a "GET" request to "http://localhost/api/v1/health?_force_failure=1"
    Then the response status code should be 500
    And the header "Content-Type" should be equal to "application/problem+json"
    And the JSON node "type" should be equal to "unhandled-exception"
    And the JSON node "status" should be equal to "500"
    And 0 requests got executed across all doctrine connections

  Scenario: Backoffice health happy path is unchanged
    Given I add "Accept" header equal to "application/json"
    When I send a "GET" request to "http://localhost/api/v1/backoffice/health"
    Then the response status code should be 200
    And the JSON node "data.status" should be equal to "ok"
    And the JSON node "data.service" should be equal to "Back office"
    And 0 requests got executed across all doctrine connections

  Scenario: Frontoffice health happy path is unchanged
    Given I add "Accept" header equal to "application/json"
    When I send a "GET" request to "http://localhost/api/v1/health"
    Then the response status code should be 200
    And the JSON node "data.status" should be equal to "ok"
    And the JSON node "data.service" should be equal to "Front office"
    And 0 requests got executed across all doctrine connections
