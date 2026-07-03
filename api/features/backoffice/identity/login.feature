@anonymous
Feature: Log in through the session firewall
  As the PWA
  In order to reach backoffice endpoints as an authenticated user
  I need to establish an httpOnly session on valid credentials and get a contract-shaped 401 when I fail

  Background:
    Given I add "Content-Type" header equal to "application/json"
    And I add "Accept" header equal to "application/json"
    And I add "Origin" header equal to "http://localhost"

  Scenario: Valid credentials establish a session
    When I send a POST request to "/backoffice/login" with body:
    """
    {
      "email": "alice@erpify.test",
      "password": "alice-password"
    }
    """
    Then the response status code should be 204
    And the response should be empty
    And the header "Set-Cookie" should contain "httponly"
    And the header "Set-Cookie" should contain "samesite=lax"

  Scenario: A wrong password is rejected with a 401 Problem Details, not a framework error body
    When I send a POST request to "/backoffice/login" with body:
    """
    {
      "email": "alice@erpify.test",
      "password": "wrong-password"
    }
    """
    Then the response status code should be 401
    And the header "Content-Type" should contain "application/problem+json"
    And the JSON node "type" should be equal to "unauthenticated"
    And the JSON node "title" should be equal to "Invalid credentials."

  Scenario: An unknown email is indistinguishable from a wrong password
    When I send a POST request to "/backoffice/login" with body:
    """
    {
      "email": "nobody@erpify.test",
      "password": "whatever"
    }
    """
    Then the response status code should be 401
    And the JSON node "type" should be equal to "unauthenticated"
    And the JSON node "title" should be equal to "Invalid credentials."

  Scenario: A blank email is a 401, never a 500
    When I send a POST request to "/backoffice/login" with body:
    """
    {
      "email": "   ",
      "password": "whatever"
    }
    """
    Then the response status code should be 401
    And the JSON node "type" should be equal to "unauthenticated"
    And the JSON node "title" should be equal to "Invalid credentials."

  Scenario: A cross-site login attempt is refused before any credential check, never establishing a session
    Given I add "Origin" header equal to "https://evil.example"
    When I send a POST request to "/backoffice/login" with body:
    """
    {
      "email": "alice@erpify.test",
      "password": "alice-password"
    }
    """
    Then the response status code should be 403
    And the header "Content-Type" should contain "application/problem+json"
    And the JSON node "type" should be equal to "forbidden"

  Scenario: A malformed JSON body is a 400 Problem Details, not a framework error body
    When I send a POST request to "/backoffice/login" with body:
    """
    this is not json
    """
    Then the response status code should be 400
    And the header "Content-Type" should contain "application/problem+json"
    And the JSON node "type" should be equal to "invalid-input"
