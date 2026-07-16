Feature: Restrict the identity register routes to the users.read permission
  As a security officer
  In order to keep the identity register governed by role
  I need the two read routes closed to anyone not granted users.read, with the distinction between "log in"
  (401) and "you may not" (403) preserved

  # The register controllers carry #[IsGranted('users.read')], resolved by the custom PermissionVoter over
  # the StaticAuthorizationPolicy. An anonymous caller is stopped by the default-deny firewall (401) before
  # the permission gate; an authenticated caller not granted the permission reaches the gate and is refused
  # (403). users opts out of tier auto-grant, so even a fully-tiered MANAGER (trent) and the default
  # AUDIT_READER session (alice) are denied — only ADMIN is granted. The granted path runs as the
  # administrator. The 403 users pin the opt-out from several sides: role-less (mallory), read tier (victor),
  # write tier (edith), delete tier (trent), audit-reader (the default alice session).
  Background:
    Given I add "Accept" header equal to "application/json"

  @anonymous
  Scenario: An unauthenticated read of the register is a 401, not a 403
    When I send a "GET" request to "/backoffice/users"
    Then the response status code should be 401
    And the header "Content-Type" should contain "application/problem+json"
    And the JSON node "type" should be equal to "unauthenticated"

  @anonymous
  Scenario: An unauthenticated read of an identity detail is a 401, not a 403
    When I send a "GET" request to "/backoffice/users/0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a66"
    Then the response status code should be 401
    And the JSON node "type" should be equal to "unauthenticated"

  Scenario: A role-less authenticated user is refused the register with 403
    Given I am logged in as a user without the audit-reader role
    When I send a "GET" request to "/backoffice/users"
    Then the response status code should be 403
    And the header "Content-Type" should contain "application/problem+json"
    And the JSON node "type" should be equal to "forbidden"

  Scenario: A viewer is refused the register with 403 — the register opts out of the read tier
    Given I am logged in as a viewer
    When I send a "GET" request to "/backoffice/users"
    Then the response status code should be 403
    And the JSON node "type" should be equal to "forbidden"

  Scenario: An editor is refused the register with 403
    Given I am logged in as an editor
    When I send a "GET" request to "/backoffice/users"
    Then the response status code should be 403
    And the JSON node "type" should be equal to "forbidden"

  Scenario: A generic-tier manager is refused the register with 403 — users opts out of tier auto-grant
    Given I am logged in as a generic-tier user without the audit-reader role
    When I send a "GET" request to "/backoffice/users"
    Then the response status code should be 403
    And the JSON node "type" should be equal to "forbidden"

  Scenario: The default audit-reader session is refused the register with 403 — read is ADMIN-only here
    When I send a "GET" request to "/backoffice/users"
    Then the response status code should be 403
    And the JSON node "type" should be equal to "forbidden"

  Scenario: A generic-tier manager is refused an identity detail with 403
    Given I am logged in as a generic-tier user without the audit-reader role
    When I send a "GET" request to "/backoffice/users/0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a66"
    Then the response status code should be 403
    And the JSON node "type" should be equal to "forbidden"

  Scenario: An administrator reads the register
    Given I am logged in as an administrator
    When I send a "GET" request to "/backoffice/users"
    Then the response status code should be 200

  Scenario: An administrator reads an identity detail
    Given I am logged in as an administrator
    When I send a "GET" request to "/backoffice/users/0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a66"
    Then the response status code should be 200
    And the JSON node "data.email" should be equal to "admin@erpify.test"
