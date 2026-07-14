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

  Scenario: A suspended identity with correct credentials is walled by a specific 403, minting no session
    When I send a POST request to "/backoffice/login" with body:
    """
    {
      "email": "sam@erpify.test",
      "password": "sam-password"
    }
    """
    Then the response status code should be 403
    And the header "Content-Type" should contain "application/problem+json"
    And the JSON node "type" should be equal to "account-suspended"
    And the header "Set-Cookie" should not exist

  Scenario: A deactivated identity with correct credentials is walled by a specific 403, minting no session
    When I send a POST request to "/backoffice/login" with body:
    """
    {
      "email": "dan@erpify.test",
      "password": "dan-password"
    }
    """
    Then the response status code should be 403
    And the header "Content-Type" should contain "application/problem+json"
    And the JSON node "type" should be equal to "account-deactivated"
    And the header "Set-Cookie" should not exist

  Scenario: An invited identity that has not set a password is indistinguishable from an unknown email
    When I send a POST request to "/backoffice/login" with body:
    """
    {
      "email": "ingrid@erpify.test",
      "password": "any-password"
    }
    """
    Then the response status code should be 401
    And the JSON node "type" should be equal to "unauthenticated"
    And the JSON node "title" should be equal to "Invalid credentials."
    And the header "Set-Cookie" should not exist

  Scenario: A locked identity with correct credentials is walled by a specific 403, minting no session
    When I execute the SQL query "UPDATE identity_user SET failed_attempts = 10, locked_until = '2099-01-01 00:00:00' WHERE email = 'leo@erpify.test'"
    And I send a POST request to "/backoffice/login" with body:
    """
    {
      "email": "leo@erpify.test",
      "password": "leo-password"
    }
    """
    Then the response status code should be 403
    And the header "Content-Type" should contain "application/problem+json"
    And the JSON node "type" should be equal to "account-locked"
    And the header "Set-Cookie" should not exist

  Scenario: A wrong password on a locked account stays the uniform 401, never revealing the lock to an anonymous caller
    When I execute the SQL query "UPDATE identity_user SET failed_attempts = 10, locked_until = '2099-01-01 00:00:00' WHERE email = 'leo@erpify.test'"
    And I send a POST request to "/backoffice/login" with body:
    """
    {
      "email": "leo@erpify.test",
      "password": "wrong-password"
    }
    """
    Then the response status code should be 401
    And the JSON node "type" should be equal to "unauthenticated"
    And the JSON node "title" should be equal to "Invalid credentials."
    And the header "Set-Cookie" should not exist

  Scenario: A suspended identity that also tripped the lockout sees the suspended wall, not the locked one
    When I execute the SQL query "UPDATE identity_user SET failed_attempts = 10, locked_until = '2099-01-01 00:00:00' WHERE email = 'sam@erpify.test'"
    And I send a POST request to "/backoffice/login" with body:
    """
    {
      "email": "sam@erpify.test",
      "password": "sam-password"
    }
    """
    Then the response status code should be 403
    And the JSON node "type" should be equal to "account-suspended"
    And the header "Set-Cookie" should not exist

  Scenario: A successful login after a lapsed lock is admitted and clears the counter
    When I execute the SQL query "UPDATE identity_user SET failed_attempts = 10, locked_until = '2000-01-01 00:00:00' WHERE email = 'lena@erpify.test'"
    And I send a POST request to "/backoffice/login" with body:
    """
    {
      "email": "lena@erpify.test",
      "password": "lena-password"
    }
    """
    Then the response status code should be 204
    And the header "Set-Cookie" should contain "httponly"
    And I execute the SQL query "SELECT id FROM identity_user WHERE email = 'lena@erpify.test' AND failed_attempts = 0 AND locked_until IS NULL"
    And there should have 1 records in SQL result

  Scenario: A pre-identity failure on an unknown email records nothing and emits no lockout event
    Given the stored events are cleared
    When I send a POST request to "/backoffice/login" with body:
    """
    {
      "email": "nobody@erpify.test",
      "password": "whatever"
    }
    """
    Then the response status code should be 401
    And there should be 0 events stored named "erpify.iam.identity.locked"

  Scenario: Crossing the threshold on a resolved identity emits one UserLocked event while the anonymous response stays 401
    Given the stored events are cleared
    When I execute the SQL query "UPDATE identity_user SET failed_attempts = 9 WHERE email = 'nora@erpify.test'"
    And I send a POST request to "/backoffice/login" with body:
    """
    {
      "email": "nora@erpify.test",
      "password": "wrong-password"
    }
    """
    Then the response status code should be 401
    And the JSON node "type" should be equal to "unauthenticated"
    And there should be 1 event stored named "erpify.iam.identity.locked"
    And there should be 1 event stored for aggregate "0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a63" named "erpify.iam.identity.locked"
