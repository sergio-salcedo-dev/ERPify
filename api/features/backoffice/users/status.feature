Feature: Change an identity's status (suspend / deactivate)
  As an administrator
  In order to block a member's access while preserving their history
  I need a gated endpoint that transitions an ACTIVE identity, never leaving the organization without an admin

  # ADMIN-only (users.changeStatus). Only an ACTIVE identity can transition — a same-state PATCH is a 409, not
  # the idempotent no-op a bank account allows, because the identity lifecycle is unidirectional. A successful
  # transition records exactly one identity event and revokes the identity's sessions (an all-revoked event);
  # neither is routed to the async worker, so the outbox stays empty (no realtime consumer).
  Background:
    Given I add "Content-Type" header equal to "application/json"
    And I add "Accept" header equal to "application/json"

  # The mutated targets (mallory, trent) are never used as a login in this feature, so a committed transition
  # never poisons a later scenario's session — Behat restores fixtures per feature, not per scenario.
  Scenario: An administrator suspends an active member
    Given I am logged in as an administrator
    And the stored events are cleared
    When I send a PATCH request to "/backoffice/users/0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5c/status" with body:
    """
    {
      "status": "SUSPENDED"
    }
    """
    Then the response status code should be 200
    And the JSON node "data" should have 6 elements
    And the JSON node "data.id" should be equal to "0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5c"
    And the JSON node "data.email" should be equal to "mallory@erpify.test"
    And the JSON node "data.status" should be equal to "SUSPENDED"
    And there should be 1 event stored for aggregate "0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5c" named "erpify.iam.identity.suspended"
    And there should be 1 event stored named "erpify.iam.session.all-revoked"
    And 0 outbox events were created on the queue "async"
    # Budget canary: the admission gate read, the active-admin set lock, the guard's own set read, the wrapped write
    # (+2 BEGIN/COMMIT) and the wrapped session revoke (+2). The lock and the guard are two round trips over
    # one statement: the second re-reads under a lock the first already holds, so it costs a read and never a
    # second acquisition. A shift means an added round trip (e.g. an N+1 in the guard) — re-measure.
    And 23 requests got executed for doctrine connection "default"

  Scenario: An administrator deactivates an active member
    Given I am logged in as an administrator
    And the stored events are cleared
    When I send a PATCH request to "/backoffice/users/0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5d/status" with body:
    """
    {
      "status": "DEACTIVATED"
    }
    """
    Then the response status code should be 200
    And the JSON node "data.status" should be equal to "DEACTIVATED"
    And there should be 1 event stored for aggregate "0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5d" named "erpify.iam.identity.deactivated"
    And there should be 1 event stored named "erpify.iam.session.all-revoked"
    And 0 outbox events were created on the queue "async"

  @anonymous
  Scenario: An unauthenticated status change is a 401, not a 403
    When I send a PATCH request to "/backoffice/users/0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5e/status" with body:
    """
    {
      "status": "SUSPENDED"
    }
    """
    Then the response status code should be 401
    And the JSON node "type" should be equal to "unauthenticated"

  Scenario: A viewer is refused the status change with 403 — users opts out of tier auto-grant
    Given I am logged in as a viewer
    When I send a PATCH request to "/backoffice/users/0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5f/status" with body:
    """
    {
      "status": "SUSPENDED"
    }
    """
    Then the response status code should be 403
    And the JSON node "type" should be equal to "forbidden"

  Scenario: The default audit-reader session is refused the status change with 403 — changeStatus is ADMIN-only
    When I send a PATCH request to "/backoffice/users/0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5f/status" with body:
    """
    {
      "status": "SUSPENDED"
    }
    """
    Then the response status code should be 403
    And the JSON node "type" should be equal to "forbidden"

  Scenario: Suspending the last active administrator is refused with 409 and nothing changes
    Given I am logged in as an administrator
    And the stored events are cleared
    When I send a PATCH request to "/backoffice/users/0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a66/status" with body:
    """
    {
      "status": "SUSPENDED"
    }
    """
    Then the response status code should be 409
    And the header "Content-Type" should be equal to "application/problem+json"
    And the JSON node "type" should be equal to "last-active-administrator-protected"
    And there should be 0 events stored named "erpify.iam.identity.suspended"
    And there should be 0 events stored named "erpify.iam.session.all-revoked"

  Scenario: Re-suspending an already-suspended identity is a 409 invalid-identity-transition (not idempotent)
    Given I am logged in as an administrator
    And the stored events are cleared
    When I send a PATCH request to "/backoffice/users/0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a61/status" with body:
    """
    {
      "status": "SUSPENDED"
    }
    """
    Then the response status code should be 409
    And the JSON node "type" should be equal to "invalid-identity-transition"
    And there should be 0 events stored named "erpify.iam.identity.suspended"
    And there should be 0 events stored named "erpify.iam.session.all-revoked"

  # Deactivating a SUSPENDED identity hits the same `guardTransitionTo(requiredFrom: ACTIVE)` branch as
  # re-suspend: only an ACTIVE identity may be deactivated, so a post-active wall is never crossed a second
  # time. The last-active-admin guard passes first (sam is a MANAGER, and an ACTIVE ADMIN remains), so the
  # 409 is the transition wall, not the admin-protection one.
  Scenario: Deactivating an already-suspended identity is a 409 invalid-identity-transition (not idempotent)
    Given I am logged in as an administrator
    And the stored events are cleared
    When I send a PATCH request to "/backoffice/users/0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a61/status" with body:
    """
    {
      "status": "DEACTIVATED"
    }
    """
    Then the response status code should be 409
    And the JSON node "type" should be equal to "invalid-identity-transition"
    And there should be 0 events stored named "erpify.iam.identity.deactivated"
    And there should be 0 events stored named "erpify.iam.session.all-revoked"

  Scenario Outline: A target outside the two legal transitions is a 422, before the domain is touched
    Given I am logged in as an administrator
    And the stored events are cleared
    When I send a PATCH request to "/backoffice/users/0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5e/status" with body:
    """
    {
      "status": "<status>"
    }
    """
    Then the response status code should be 422
    And the JSON node "type" should be equal to "validation-failed"
    And there should be 0 events stored named "erpify.iam.identity.suspended"

    Examples:
      | status  |
      | FROZEN  |
      | ACTIVE  |
      | INVITED |

  # A payload that never resolves to a valid IdentityStatus target — key missing, null, empty, or wrong-case —
  # is refused at the HTTP mapping boundary as the same 422 validation-failed, before any domain method runs, so
  # no transition event is recorded. Distinct from the outline above, whose values ARE valid enum cases yet fall
  # outside the two offered transitions; here the value is not even a well-typed IdentityStatus.
  Scenario Outline: A malformed status payload is a 422 validation-failed, before the domain is touched
    Given I am logged in as an administrator
    And the stored events are cleared
    When I send a PATCH request to "/backoffice/users/0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5e/status" with body:
    """
    <body>
    """
    Then the response status code should be 422
    And the JSON node "type" should be equal to "validation-failed"
    And there should be 0 events stored named "erpify.iam.identity.suspended"

    Examples:
      | body                    |
      | {}                      |
      | {"status": null}        |
      | {"status": ""}          |
      | {"status": "suspended"} |

  Scenario Outline: A malformed id returns a 400 invalid-uuid Problem Details body
    Given I am logged in as an administrator
    When I send a PATCH request to "/backoffice/users/<id>/status" with body:
    """
    {
      "status": "SUSPENDED"
    }
    """
    Then the response status code should be 400
    And the JSON node "type" should be equal to "invalid-uuid"
    And the JSON node "status" should be equal to the number 400

    Examples:
      | id         |
      | not-a-uuid |
      | 123        |

  Scenario: Changing the status of a well-formed but unknown id is a 404 user-not-found
    Given I am logged in as an administrator
    When I send a PATCH request to "/backoffice/users/2e6d865c-17b0-476a-85f2-037bf6d3b3dc/status" with body:
    """
    {
      "status": "SUSPENDED"
    }
    """
    Then the response status code should be 404
    And the JSON node "type" should be equal to "user-not-found"
