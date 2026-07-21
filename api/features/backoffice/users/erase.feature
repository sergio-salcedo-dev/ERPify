Feature: Erase an identity (GDPR right to erasure)
  As an administrator
  In order to honour a data-subject's right to erasure without a developer running a CLI
  I need a gated endpoint that de-identifies the subject and their audit trail as one atomic unit

  # ADMIN-only (users.erase). One DELETE chains, in a single transaction: the identity hard-delete (the module's
  # PII — email and credential hash), the anonymisation of every audit row the subject authored, the hard-delete
  # of the subject's sessions, and the combined compliance self-audit — all attributed to the acting admin, never
  # the subject. Success is a 204. An admin may not erase themselves (409 self-erasure-forbidden); the ≥1-active-
  # admin guard binds off-request (the CLI), unreachable over HTTP once self-erasure is refused.
  Background:
    Given I add "Accept" header equal to "application/json"

  Scenario: An administrator erases a subject, its audit trail and its sessions in one atomic unit
    # A live session and a past audit row for the subject, seeded on a side connection the query counter ignores.
    Given I execute the SQL query "INSERT INTO iam_session (id, user_id, organization_id, device, ip, expires_at, status, created_at, updated_at) VALUES ('0190f200-0000-7000-8000-00000000ee11', '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5c', '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a50', 'Behat device', '203.0.113.9', '2027-01-01 00:00:00+00', 'ACTIVE', NOW(), NOW())" on connection "seed"
    And I execute the SQL query "INSERT INTO audit_log (id, level, action, actor_type, actor_id, correlation_id, resource_type, resource_id, metadata, actor_erased, occurred_on) VALUES ('0190f200-0000-7000-8000-00000000ee21', 'change', 'BANK_UPDATED', 'user', '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5c', '0190f200-0000-7000-8000-00000000ee31', 'Bank', '0190f200-0000-7000-8000-00000000ee41', '{}', false, '2026-03-01 12:00:00+00')" on connection "seed"
    And I am logged in as an administrator
    And I add "X-Correlation-Id" header equal to "0190f200-0000-7000-8000-00000000ee51"
    When I send a "DELETE" request to "/backoffice/users/0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5c"
    Then the response status code should be 204
    # Identity hard-deleted.
    And the "Erpify\Iam\Identity\Domain\Entity\User" entity found by "id=0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5c" does not exist
    # Sessions dropped — no residual ip/device PII behind a subject that no longer exists.
    And I execute the SQL query "SELECT id FROM iam_session WHERE user_id = '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5c'"
    And there should have 0 records in SQL result
    # The subject's audit row is anonymised in place (actor_erased) — never deleted.
    And I execute the SQL query "SELECT id FROM audit_log WHERE actor_id = '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5c'"
    And there should have 0 records in SQL result
    And I execute the SQL query "SELECT id FROM audit_log WHERE id = '0190f200-0000-7000-8000-00000000ee21' AND actor_erased = TRUE"
    And there should have 1 records in SQL result
    # The two compliance rows survive, attributed to the acting admin (0190…a66), never the subject.
    And I execute the SQL query "SELECT id FROM audit_log WHERE correlation_id = '0190f200-0000-7000-8000-00000000ee51' AND action = 'GDPR_SUBJECT_ERASED' AND actor_id = '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a66'"
    And there should have 1 records in SQL result
    And I execute the SQL query "SELECT id FROM audit_log WHERE correlation_id = '0190f200-0000-7000-8000-00000000ee51' AND action = 'GDPR_ERASURE_EXECUTED' AND actor_id = '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a66'"
    And there should have 1 records in SQL result
    # Budget canary: the admission gate read, the ≥1-admin guard EXISTS (FOR UPDATE), the reset-token delete, the
    # identity delete, the GDPR_SUBJECT_ERASED insert, the trail-anonymisation UPDATE, the session delete and the
    # GDPR_ERASURE_EXECUTED insert, wrapped (+2 BEGIN/COMMIT). A shift means an added round trip — re-measure.
    And 14 requests got executed for doctrine connection "default"

  @anonymous
  Scenario: An unauthenticated erase is a 401, not a 403
    When I send a "DELETE" request to "/backoffice/users/0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5d"
    Then the response status code should be 401
    And the JSON node "type" should be equal to "unauthenticated"

  Scenario: A viewer is refused the erase with 403 — users opts out of tier auto-grant
    Given I am logged in as a viewer
    When I send a "DELETE" request to "/backoffice/users/0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5d"
    Then the response status code should be 403
    And the JSON node "type" should be equal to "forbidden"

  Scenario: The default audit-reader session is refused the erase with 403 — erase is ADMIN-only
    When I send a "DELETE" request to "/backoffice/users/0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5d"
    Then the response status code should be 403
    And the JSON node "type" should be equal to "forbidden"

  Scenario: An administrator cannot erase their own identity — 409 self-erasure-forbidden
    Given I am logged in as an administrator
    When I send a "DELETE" request to "/backoffice/users/0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a66"
    Then the response status code should be 409
    And the header "Content-Type" should be equal to "application/problem+json"
    And the JSON node "type" should be equal to "self-erasure-forbidden"
    And there should have 1 "Erpify\Iam\Identity\Domain\Entity\User" entities found by "id=0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a66"

  Scenario Outline: A malformed id returns a 400 invalid-uuid Problem Details body
    Given I am logged in as an administrator
    When I send a "DELETE" request to "/backoffice/users/<id>"
    Then the response status code should be 400
    And the JSON node "type" should be equal to "invalid-uuid"
    And the JSON node "status" should be equal to the number 400

    Examples:
      | id         |
      | not-a-uuid |
      | 123        |

  Scenario: Erasing a well-formed but unknown id is a 404 user-not-found
    Given I am logged in as an administrator
    When I send a "DELETE" request to "/backoffice/users/2e6d865c-17b0-476a-85f2-037bf6d3b3dc"
    Then the response status code should be 404
    And the JSON node "type" should be equal to "user-not-found"
