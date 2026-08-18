Feature: Default-deny access control on the API
  As the platform
  In order to keep every protected route closed unless explicitly opened
  I need an unauthenticated request to a protected route to fail with a contract-shaped 401, a public route to
  stay reachable, and an authenticated session to pass through

  Background:
    Given I add "Accept" header equal to "application/json"

  @anonymous
  Scenario: An unauthenticated request to a protected route is a 401 Problem Details
    When I send a "GET" request to "/backoffice/banks"
    Then the response status code should be 401
    And the header "Content-Type" should contain "application/problem+json"
    And the JSON node "type" should be equal to "unauthenticated"
    And the JSON node "title" should be equal to "Authentication required."

  @anonymous
  Scenario: A public route stays reachable without authentication
    When I send a "GET" request to "/health"
    Then the response status code should be 200

  Scenario: An authenticated session reaches a protected route
    When I send a "GET" request to "/backoffice/banks"
    Then the response status code should be 200
