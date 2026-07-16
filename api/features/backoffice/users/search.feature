Feature: Search the identity register
  As an administrator
  In order to administer identities on real data
  I need to page the user register through the read-side, filtered and sorted, with a stable wire envelope

  # The register read is ADMIN-only (the users resource opts out of tiering), so every scenario runs as the
  # administrator. The list is a keyset page with the cursor-only envelope {hasNext, hasPrev, count, links}:
  # the opaque cursor lives INSIDE links.next/links.prev, there is no page number and no exposed cursor
  # scalar. `limit` defaults to 25, ceiling 100. status/roles travel as the enum identity value (ACTIVE,
  # ["ADMIN"]), never a translated label. The page is one query (no N+1): the projection selects explicit
  # columns off a single FROM, and there is no read-time enricher.
  Background:
    Given I am logged in as an administrator

  Scenario: The register returns the read projection with the cursor-only envelope
    When I send a "GET" request to "/backoffice/users?limit=100"
    Then the response status code should be 200
    And the JSON nodes matching "data[*]" should have 6 children
    And the JSON nodes matching "data[*].id" should exist
    And the JSON nodes matching "data[*].email" should exist
    And the JSON nodes matching "data[*].status" should exist
    And the JSON nodes matching "data[*].roles" should exist
    And the JSON nodes matching "data[*].createdAt" should exist
    And the JSON nodes matching "data[*].updatedAt" should exist
    And the JSON node "pagination" should have 4 elements
    And the JSON node "pagination.hasNext" should be false
    And the JSON node "pagination.hasPrev" should be false
    And the JSON node "pagination.count" should be null
    And the JSON node "pagination.links.next" should be null
    And the JSON node "pagination.links.prev" should be null
    And a request contains "FROM identity_user" across all doctrine connections
    And 1 request got executed only for doctrine connection "default"

  # The projection never selects the credential columns: the row shape is exactly the six wire fields, so
  # password_hash / failed_attempts / locked_until can never appear.
  Scenario: The register row carries the wire fields and never the credential
    When I send a "GET" request to "/backoffice/users?filters[0][field]=email&filters[0][operator]=eq&filters[0][value]=admin@erpify.test"
    Then the response status code should be 200
    And the JSON node "data" should have 1 element
    And the JSON node "data[0].email" should be equal to "admin@erpify.test"
    And the JSON node "data[0].status" should be equal to "ACTIVE"
    And the JSON node "data[0].roles" should have 1 element
    And the JSON node "data[0].roles[0]" should be equal to "ADMIN"
    And the JSON node "data[0].createdAt" should not be null
    And the JSON node "data[0].updatedAt" should not be null
    And the JSON node "data[0].password_hash" should not exist
    And the JSON node "data[0].failedAttempts" should not exist
    And the JSON node "data[0].permissions" should not exist

  # status is an exact enum filter: eq over the enum-backed column selects only that lifecycle state. The
  # two INVITED fixtures (ingrid, iris) pin both the filter and the enum hydration through the projection.
  Scenario: Filtering by status selects only that lifecycle state
    When I send a "GET" request to "/backoffice/users?filters[0][field]=status&filters[0][operator]=eq&filters[0][value]=INVITED&limit=100"
    Then the response status code should be 200
    And the JSON node "data" should have 2 elements
    And the JSON nodes matching "data[*].status" should "be equal to" value "INVITED"

  # roles is deliberately NOT in the field map (no JSONB containment operator, and it is display-only on the
  # wire), so filtering by it is a 422 unknown-search-field rather than a silent no-op.
  Scenario: Filtering by roles is rejected — the register is not role-filterable
    When I send a "GET" request to "/backoffice/users?filters[0][field]=roles&filters[0][operator]=contains&filters[0][value]=ADMIN"
    Then the response status code should be 422
    And the header "Content-Type" should be equal to "application/problem+json"
    And the JSON node "type" should be equal to "unknown-search-field"

  # Sort is an allow-list of four fields; email asc orders the register alphabetically, with the id tie-break
  # applied by the engine. admin@erpify.test sorts first.
  Scenario: Sorting by email ascending orders the register
    When I send a "GET" request to "/backoffice/users?sort=email&direction=ASC&limit=100"
    Then the response status code should be 200
    And the JSON node "data[0].email" should be equal to "admin@erpify.test"

  Scenario: Sorting by a field outside the allow-list returns 422
    When I send a "GET" request to "/backoffice/users?sort=password"
    Then the response status code should be 422
    And the header "Content-Type" should be equal to "application/problem+json"
    And the JSON node "type" should be equal to "unknown-sort-field"

  # Default limit is 25; the register fits in one page, so a first page is forward/back closed.
  Scenario: The default page closes both affordances when the register fits in one page
    When I send a "GET" request to "/backoffice/users"
    Then the response status code should be 200
    And the JSON node "pagination.hasNext" should be false
    And the JSON node "pagination.hasPrev" should be false
    And the JSON node "pagination.links.next" should be null
    And the JSON node "pagination.links.prev" should be null

  Scenario: An opaque garbage cursor is rejected as invalid
    When I send a "GET" request to "/backoffice/users?after=not-a-real-cursor"
    Then the response status code should be 422
    And the header "Content-Type" should be equal to "application/problem+json"
    And the JSON node "type" should be equal to "invalid-cursor"
