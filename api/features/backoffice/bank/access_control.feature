Feature: Restrict the bank routes to the bank permission
  As a backoffice operator
  In order to keep bank data governed by role
  I need every bank route closed to anyone not granted the matching bank permission, with the distinction
  between "log in" (401) and "you may not" (403) preserved

  # The Bank controllers carry #[IsGranted(BankPermission::READ|WRITE|DELETE)], resolved by the custom
  # PermissionVoter over the StaticAuthorizationPolicy. An anonymous caller is stopped by the default-deny
  # firewall (401) before the permission gate; an authenticated caller not granted the permission reaches the
  # gate and is refused (403). read/write/delete are auto-granted by the role tiers, so Bank adds no policy
  # row. Alice (the default session) is a MANAGER, so the granted paths are covered here and by the other bank
  # features; mallory (role-less) is the denied user. Write refusals send a valid body so the permission gate
  # — which runs after payload mapping — is what answers, not a 422.
  Background:
    Given I add "Accept" header equal to "application/json"

  @anonymous
  Scenario: An unauthenticated read of the bank collection is a 401, not a 403
    When I send a "GET" request to "/backoffice/banks"
    Then the response status code should be 401
    And the header "Content-Type" should contain "application/problem+json"
    And the JSON node "type" should be equal to "unauthenticated"

  @anonymous
  Scenario: An unauthenticated create is a 401
    When I send a POST request to "/backoffice/banks" with body:
    """
    {
      "name": "Blocked Bank",
      "shortName": "BLK"
    }
    """
    Then the response status code should be 401
    And the JSON node "type" should be equal to "unauthenticated"

  @anonymous
  Scenario: An unauthenticated delete is a 401
    When I send a "DELETE" request to "/backoffice/banks/0190a001-0000-7000-8000-000000000001"
    Then the response status code should be 401
    And the JSON node "type" should be equal to "unauthenticated"

  Scenario: A role-less authenticated user is refused the bank collection with 403
    Given I am logged in as a user without the audit-reader role
    When I send a "GET" request to "/backoffice/banks"
    Then the response status code should be 403
    And the header "Content-Type" should contain "application/problem+json"
    And the JSON node "type" should be equal to "forbidden"

  Scenario: A role-less authenticated user is refused a create with 403 — the permission gate, not validation
    Given I am logged in as a user without the audit-reader role
    When I send a POST request to "/backoffice/banks" with body:
    """
    {
      "name": "Access Denied Bank",
      "shortName": "ADB"
    }
    """
    Then the response status code should be 403
    And the JSON node "type" should be equal to "forbidden"

  Scenario: A role-less authenticated user is refused a delete with 403
    Given I am logged in as a user without the audit-reader role
    When I send a "DELETE" request to "/backoffice/banks/0190a001-0000-7000-8000-000000000001"
    Then the response status code should be 403
    And the JSON node "type" should be equal to "forbidden"

  Scenario: A granted manager reads the bank collection
    When I send a "GET" request to "/backoffice/banks"
    Then the response status code should be 200
