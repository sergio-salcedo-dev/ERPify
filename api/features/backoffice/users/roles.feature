Feature: Assign an identity's roles
  As an administrator
  In order to adjust what a member can do without re-inviting them
  I need a gated endpoint that replaces an identity's role set, never leaving the organization without an admin

  # ADMIN-only (users.changeRoles). The payload is the complete resulting set, so re-sending the set an identity
  # already holds is a legitimate no-op — unlike the status endpoint, where a same-state PATCH is a 409, because
  # a role set is not unidirectional. A change that actually replaces the set records exactly one identity event
  # and revokes the identity's sessions (an all-revoked event); neither is routed to the async worker, so the
  # outbox stays empty (no realtime consumer). The guard is only consulted when the change would demote an
  # active administrator.
  Background:
    Given I add "Content-Type" header equal to "application/json"
    And I add "Accept" header equal to "application/json"

  # Fixtures are restored per feature, not per scenario, so every mutated target below (trent, sam, dan) is one
  # this feature never logs in as — a committed change revokes that identity's registry session, which would
  # strand a later scenario that needs it. The single exception is the administrator, mutated only by the last
  # scenario for exactly this reason.
  Scenario: An administrator replaces a member's role set
    Given I am logged in as an administrator
    And the stored events are cleared
    When I send a PATCH request to "/backoffice/users/0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5d/roles" with body:
    """
    {
      "roles": ["EDITOR", "AUDIT_READER"]
    }
    """
    Then the response status code should be 200
    And the JSON node "data" should have 6 elements
    And the JSON node "data.id" should be equal to "0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5d"
    And the JSON node "data.email" should be equal to "trent@erpify.test"
    And the JSON node "data.roles" should have 2 elements
    And the JSON node "data.roles[0]" should be equal to "EDITOR"
    And the JSON node "data.roles[1]" should be equal to "AUDIT_READER"
    And there should be 1 event stored for aggregate "0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5d" named "erpify.iam.identity.roles-changed"
    And there should be 1 event stored named "erpify.iam.session.all-revoked"
    And 0 outbox events were created on the queue "async"
    # The compliance record of the change, carrying both role sets and nothing else — `User` stays out of the
    # write-capture CDC (that diff would carry `password_hash`), so this explicit row is the ONLY attributable
    # evidence a role change leaves. The erasure refusal depends on it existing.
    And I execute the SQL query "SELECT id FROM audit_log WHERE action = 'USER_ROLES_CHANGED' AND level = 'security' AND resource_type = 'User' AND resource_id = '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5d' AND actor_id = '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a66'"
    And there should have 1 records in SQL result
    # "Both role sets and nothing else" read at the column, not at the port: jsonb equality against a value
    # built key by key fails on a missing key, a surplus one (a credential, an email, a diff of the aggregate)
    # and on a value that is not the canonical — sorted, deduplicated — set. The held set is trent's fixture
    # `[MANAGER]`; the assigned one is the request's, sorted.
    And I execute the SQL query "SELECT id FROM audit_log WHERE action = 'USER_ROLES_CHANGED' AND resource_id = '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5d' AND metadata = jsonb_build_object('previous_roles', jsonb_build_array('MANAGER'), 'new_roles', jsonb_build_array('AUDIT_READER', 'EDITOR'))"
    And there should have 1 records in SQL result
    # Budget canary: the admission gate read, the wrapped write (+2 BEGIN/COMMIT) and the wrapped session
    # revoke (+2), with NO guard read — the target is not an administrator, so the directory is never asked.
    # One of the writes is the USER_ROLES_CHANGED insert, written synchronously because it is `security`.
    # A shift means an added round trip; re-measure rather than nudging the number.
    And 22 requests got executed for doctrine connection "default"

  # Roles are orthogonal to the identity lifecycle, so a suspended member is re-roled like any other — the new
  # set simply stays inert until the identity can act again.
  Scenario: A suspended member can still be re-roled
    Given I am logged in as an administrator
    And the stored events are cleared
    When I send a PATCH request to "/backoffice/users/0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a61/roles" with body:
    """
    {
      "roles": ["VIEWER"]
    }
    """
    Then the response status code should be 200
    And the JSON node "data.status" should be equal to "SUSPENDED"
    And the JSON node "data.roles[0]" should be equal to "VIEWER"
    And there should be 1 event stored for aggregate "0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a61" named "erpify.iam.identity.roles-changed"

  # Duplicates collapse to the set the identity already holds, so this is the redundant-save path: it answers
  # with the detail but must not write, emit or — the reason it matters — tear down live sessions.
  Scenario: Re-sending the set a member already holds changes nothing
    Given I am logged in as an administrator
    And the stored events are cleared
    When I send a PATCH request to "/backoffice/users/0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a62/roles" with body:
    """
    {
      "roles": ["MANAGER", "MANAGER"]
    }
    """
    Then the response status code should be 200
    And the JSON node "data.roles" should have 1 element
    And the JSON node "data.roles[0]" should be equal to "MANAGER"
    And there should be 0 events stored named "erpify.iam.identity.roles-changed"
    And there should be 0 events stored named "erpify.iam.session.all-revoked"
    # The only path on which "a change that did not happen leaves no compliance record" is observable: this one
    # answers 200 and COMMITS, so a row written before the set was compared would still be here to count. On the
    # refusal path the same query proves nothing — the 409 aborts the transaction the audit write shares, so any
    # row it had written would have rolled back with it.
    And I execute the SQL query "SELECT id FROM audit_log WHERE action = 'USER_ROLES_CHANGED' AND resource_id = '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a62'"
    And there should have 0 records in SQL result
    # Its own budget, far below the change path: the transaction opens and closes around a single read, with
    # nothing written and no revoke behind it.
    And 3 requests got executed for doctrine connection "default"

  @anonymous
  Scenario: An unauthenticated role change is a 401, not a 403
    When I send a PATCH request to "/backoffice/users/0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5e/roles" with body:
    """
    {
      "roles": ["EDITOR"]
    }
    """
    Then the response status code should be 401
    And the JSON node "type" should be equal to "unauthenticated"

  Scenario: A viewer is refused the role change with 403 — users opts out of tier auto-grant
    Given I am logged in as a viewer
    When I send a PATCH request to "/backoffice/users/0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5c/roles" with body:
    """
    {
      "roles": ["EDITOR"]
    }
    """
    Then the response status code should be 403
    And the JSON node "type" should be equal to "forbidden"

  Scenario: The default audit-reader session is refused the role change with 403 — changeRoles is ADMIN-only
    When I send a PATCH request to "/backoffice/users/0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5c/roles" with body:
    """
    {
      "roles": ["EDITOR"]
    }
    """
    Then the response status code should be 403
    And the JSON node "type" should be equal to "forbidden"

  Scenario: Demoting the last active administrator is refused with 409 and nothing changes
    Given I am logged in as an administrator
    And the stored events are cleared
    When I send a PATCH request to "/backoffice/users/0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a66/roles" with body:
    """
    {
      "roles": ["EDITOR"]
    }
    """
    Then the response status code should be 409
    And the header "Content-Type" should be equal to "application/problem+json"
    And the JSON node "type" should be equal to "last-active-administrator-protected"
    And there should be 0 events stored named "erpify.iam.identity.roles-changed"
    And there should be 0 events stored named "erpify.iam.session.all-revoked"

  Scenario Outline: A malformed set is a 422, before the domain is touched
    Given I am logged in as an administrator
    And the stored events are cleared
    When I send a PATCH request to "/backoffice/users/0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5e/roles" with body:
    """
    {
      "roles": <roles>
    }
    """
    Then the response status code should be 422
    And the JSON node "type" should be equal to "validation-failed"
    And there should be 0 events stored named "erpify.iam.identity.roles-changed"

    # The two object bodies carry a legal role under a key. Every member-level constraint accepts them, so only
    # the list check stands between a JSON object and a variadic spread that would read its keys as named
    # arguments — the "userId" key collides with the route id and would answer 500 instead of 422.
    Examples:
      | roles                 |
      | []                    |
      | ["ROOT"]              |
      | ["EDITOR", "ROOT"]    |
      | {"a": "EDITOR"}       |
      | {"userId": "EDITOR"}  |

  Scenario: A body carrying a member the payload does not declare is refused, naming it
    # "status" is a legal instruction on a sibling endpoint, so silently discarding it would tell the caller a
    # status change succeeded when only the roles were replaced.
    Given I am logged in as an administrator
    And the stored events are cleared
    When I send a PATCH request to "/backoffice/users/0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5e/roles" with body:
    """
    {
      "roles": ["EDITOR"],
      "status": "SUSPENDED"
    }
    """
    Then the response status code should be 422
    And the header "Content-Type" should be equal to "application/problem+json"
    And the JSON node "type" should be equal to "validation-failed"
    And the JSON node "violations" should have 1 elements
    And the JSON node "violations[0].field" should be equal to "status"
    And there should be 0 events stored named "erpify.iam.identity.roles-changed"
    And there should be 0 events stored named "erpify.iam.session.all-revoked"

  Scenario: Every surplus member is named, not just the first
    Given I am logged in as an administrator
    And the stored events are cleared
    When I send a PATCH request to "/backoffice/users/0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5e/roles" with body:
    """
    {
      "roles": ["EDITOR"],
      "status": "SUSPENDED",
      "id": "0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a99"
    }
    """
    Then the response status code should be 422
    And the JSON node "type" should be equal to "validation-failed"
    And the JSON node "violations" should have 2 elements
    And there should be 0 events stored named "erpify.iam.identity.roles-changed"

  Scenario Outline: A malformed id returns a 400 invalid-uuid Problem Details body
    Given I am logged in as an administrator
    And the stored events are cleared
    When I send a PATCH request to "/backoffice/users/<id>/roles" with body:
    """
    {
      "roles": ["EDITOR"]
    }
    """
    Then the response status code should be 400
    And the JSON node "type" should be equal to "invalid-uuid"
    And the JSON node "status" should be equal to the number 400
    And there should be 0 events stored named "erpify.iam.identity.roles-changed"
    And there should be 0 events stored named "erpify.iam.session.all-revoked"

    Examples:
      | id         |
      | not-a-uuid |
      | 123        |

  Scenario: Changing the roles of a well-formed but unknown id is a 404 user-not-found
    Given I am logged in as an administrator
    And the stored events are cleared
    When I send a PATCH request to "/backoffice/users/2e6d865c-17b0-476a-85f2-037bf6d3b3dc/roles" with body:
    """
    {
      "roles": ["EDITOR"]
    }
    """
    Then the response status code should be 404
    And the JSON node "type" should be equal to "user-not-found"
    And there should be 0 events stored named "erpify.iam.identity.roles-changed"
    And there should be 0 events stored named "erpify.iam.session.all-revoked"

  # The administrator seeded here is the only ACTIVE one, so an unconditionally-evaluated guard would refuse
  # this with a 409. Keeping ADMIN takes nobody out of the active-admin pool, so the guard is never consulted
  # and the widening is allowed. Kept LAST in the feature: it is the one scenario that mutates the identity
  # every other scenario logs in as, and the commit revokes that identity's registry session.
  Scenario: The sole administrator may widen its own set because the guard does not apply
    Given I am logged in as an administrator
    And the stored events are cleared
    When I send a PATCH request to "/backoffice/users/0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a66/roles" with body:
    """
    {
      "roles": ["ADMIN", "EDITOR"]
    }
    """
    Then the response status code should be 200
    And the JSON node "data.roles" should have 2 elements
    And the JSON node "data.roles[0]" should be equal to "ADMIN"
    And the JSON node "data.roles[1]" should be equal to "EDITOR"
    And there should be 1 event stored for aggregate "0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a66" named "erpify.iam.identity.roles-changed"
