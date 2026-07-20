Feature: Get a single identity
  As an administrator
  In order to inspect one identity on real data
  I need the detail read-side keyed by id, gated by users.read, with request-target errors distinguished

  # ADMIN-only, like the register list. The detail resource is exactly {id, email, status, roles, createdAt,
  # updatedAt} — no credential, no permissions section (the roles ARE the authorization map). A malformed id
  # is a 400 invalid-uuid raised before any lookup; a well-formed id with no row is a 404 user-not-found.
  Background:
    Given I am logged in as an administrator

  # Fetches a user OTHER than the authenticated administrator, so the finder issues a real SELECT (the
  # admin's own row is already in the identity map from the firewall reload, which would read as zero
  # queries). victor is a VIEWER, ACTIVE fixture.
  Scenario: Get a single identity by id
    When I send a "GET" request to "/backoffice/users/0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5e"
    Then the response status code should be 200
    And the JSON node "data" should have 6 elements
    And the JSON node "data.id" should be equal to "0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5e"
    And the JSON node "data.email" should be equal to "victor@erpify.test"
    And the JSON node "data.status" should be equal to "ACTIVE"
    And the JSON node "data.roles" should have 1 element
    And the JSON node "data.roles[0]" should be equal to "VIEWER"
    And the JSON node "data.createdAt" should not be null
    And the JSON node "data.updatedAt" should not be null
    And the JSON node "data.password_hash" should not exist
    And the JSON node "data.failedAttempts" should not exist
    And the JSON node "data.lockedUntil" should not exist
    And the JSON node "data.permissions" should not exist
    And 2 request got executed only for doctrine connection "default"

  Scenario Outline: Get an identity with a malformed id returns a 400 invalid-uuid Problem Details body
    When I send a "GET" request to "/backoffice/users/<userId>"
    Then the response status code should be 400
    And the header "Content-Type" should be equal to "application/problem+json"
    And the header "Cache-Control" should contain "no-store"
    And the JSON node "type" should be equal to "invalid-uuid"
    And the JSON node "title" should be equal to "The provided value is not a valid UUID."
    And the JSON node "status" should be equal to the number 400
    And the JSON node "violations" should not exist
    And the JSON node "instance" should be a valid UUID
    And the JSON node "correlation-id" should be a valid UUID
    And 0 requests got executed across all doctrine connections
    Examples:
      | userId      |
      | null        |
      | invalidUuid |

  Scenario: Get an identity that does not exist returns a 404 user-not-found Problem Details body
    When I send a "GET" request to "/backoffice/users/2e6d865c-17b0-476a-85f2-037bf6d3b3dc"
    Then the response status code should be 404
    And the header "Content-Type" should be equal to "application/problem+json"
    And the header "Cache-Control" should contain "no-store"
    And the JSON node "type" should be equal to "user-not-found"
    And the JSON node "title" should be equal to "User not found."
    And the JSON node "status" should be equal to the number 404
    And the JSON node "userId" should be equal to "2e6d865c-17b0-476a-85f2-037bf6d3b3dc"
    And the JSON node "instance" should be a valid UUID
    And the JSON node "correlation-id" should be a valid UUID
    And 1 request got executed only for doctrine connection "default"
