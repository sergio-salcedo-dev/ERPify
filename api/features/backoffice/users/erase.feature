Feature: Erase an identity (GDPR right to erasure)
  As an administrator
  In order to honour a data-subject's right to erasure without a developer running a CLI
  I need a gated endpoint that de-identifies the subject and their audit trail as one atomic unit

  # ADMIN-only (users.erase). One DELETE chains, in a single transaction: the identity hard-delete (the module's
  # PII — email and credential hash), the anonymisation of every audit row the subject authored, the hard-delete
  # of the subject's sessions, of the membership that admitted them and of every invitation addressed to them,
  # and the combined compliance self-audit — all attributed to the acting admin, never
  # the subject. Success is a 204. An admin may not erase themselves (409 self-erasure-forbidden) nor a peer who
  # still carries the role (409 administrator-erasure-requires-demotion) — which subsumes the ≥1-active-admin
  # invariant here, since the last active admin necessarily carries it.
  Background:
    Given I add "Accept" header equal to "application/json"

  Scenario: An administrator erases a subject, its audit trail and its sessions in one atomic unit
    # A live session and a past audit row for the subject, seeded on a side connection the query counter ignores.
    Given I execute the SQL query "INSERT INTO iam_session (id, user_id, organization_id, device, ip, expires_at, status, created_at, updated_at) VALUES ('0190f200-0000-7000-8000-00000000ee11', '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5c', '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a50', 'Behat device', '203.0.113.9', '2027-01-01 00:00:00+00', 'ACTIVE', NOW(), NOW())" on connection "seed"
    And I execute the SQL query "INSERT INTO audit_log (id, level, action, actor_type, actor_id, correlation_id, resource_type, resource_id, metadata, actor_erased, resource_erased, occurred_on) VALUES ('0190f200-0000-7000-8000-00000000ee21', 'change', 'BANK_UPDATED', 'user', '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5c', '0190f200-0000-7000-8000-00000000ee31', 'Bank', '0190f200-0000-7000-8000-00000000ee41', '{}', false, false, '2026-03-01 12:00:00+00')" on connection "seed"
    # Three event-store rows, one per column that can hold the identifier, because a control that watches
    # only one reads green while the identifier is alive in the column beside it. Each row carries it in
    # exactly ONE place — the aggregate column, then `payload`, then `metadata` — so removing any single
    # clause from the statement, in its SET or in its WHERE, reddens an assertion below and no other row
    # covers for it. The `payload` occurrence is UPPERCASED and under a key nobody enumerates: both JSON
    # columns are jsonb TEXT, the one place Postgres does not case-fold for us.
    And I execute the SQL query "INSERT INTO event_store (event_id, aggregate_id, aggregate_type, aggregate_version, event_name, event_version, payload, metadata, tenant_id, occurred_on, recorded_on) VALUES ('0190f200-0000-7000-8000-00000000ea01', '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5c', 'Iam.Identity', 1, 'erpify.iam.identity.suspended', 1, '{}', '[]', NULL, '2026-03-01 12:00:00+00', NOW()), ('0190f200-0000-7000-8000-00000000ea02', '0190f200-0000-7000-8000-00000000ea11', 'Iam.Invitation', 1, 'erpify.iam.invitation.created', 1, jsonb_build_object('invitedUserId', '0190A1B2-C3D4-7E5F-8A9B-0C1D2E3F4A5C'), '[]', NULL, '2026-03-01 12:01:00+00', NOW()), ('0190f200-0000-7000-8000-00000000ea03', '0190f200-0000-7000-8000-00000000ea12', 'Iam.Invitation', 2, 'erpify.iam.invitation.accepted', 1, '{}', jsonb_build_object('actor', '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5c'), NULL, '2026-03-01 12:02:00+00', NOW())" on connection "seed"
    # Seeded, not assumed: the test database is migrated but never provisioned, so an insert that matched
    # nothing would leave every assertion below passing over an empty table.
    And I execute the SQL query "SELECT event_id FROM event_store WHERE event_id IN ('0190f200-0000-7000-8000-00000000ea01', '0190f200-0000-7000-8000-00000000ea02', '0190f200-0000-7000-8000-00000000ea03')" on connection "seed"
    And there should have 3 records in SQL result
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
    # The subject's real id survives in NO metadata of any row. Matched over the whole column as text, not by
    # key name: a key holding the id under a different name is the same defect, and `metadata` is `[]` — an
    # ARRAY, not an object — in most rows, so the jsonb key operators would abort or skip them silently.
    # ILIKE, not LIKE: `resource_id` is a `uuid` and Postgres normalises its case on both sides, but `metadata`
    # is jsonb TEXT and does not, while the route hands the client's exact spelling down uncanonicalised — so a
    # mixed-case request id (proven live further down this file) would hide the same leak from a LIKE.
    And I execute the SQL query "SELECT id FROM audit_log WHERE metadata::text ILIKE '%0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5c%'"
    And there should have 0 records in SQL result
    # …and no OTHER person's id is in there either, which the assertion above cannot see: it names one subject,
    # so an id belonging to somebody still alive — the administrator who executed this erasure, say — would
    # pass it. This one declares nothing and reads real rows, so it covers every write path the suite exercises,
    # including the change-data-capture one whose content is a property of the entity model rather than of any
    # reviewed call site. The two are complementary: the erased subject is gone from `identity_user`, so only
    # the query above can catch them, and only this one can catch everybody else.
    And I execute the SQL query "SELECT a.id FROM audit_log a JOIN identity_user u ON a.metadata::text ILIKE '%' || u.id::text || '%'"
    And there should have 0 records in SQL result
    # …and the compliance row is still USABLE evidence: it names the subject as its resource, anonymised in
    # place to the pseudonym, so the record survives the identifier. Scoped by correlation id like the two
    # assertions above it: an exact count over the whole table is a claim about the suite's history rather
    # than about this erasure, and goes red the day a second scenario erases anybody before this one runs.
    # What the pseudonym buys is a LINK, not an attribution — `audit_log` does not answer WHICH person by
    # design (D4 bans the crosswalk), and the link itself is only as long-lived as the trail it points into.
    And I execute the SQL query "SELECT id FROM audit_log WHERE correlation_id = '0190f200-0000-7000-8000-00000000ee51' AND action = 'GDPR_SUBJECT_ERASED' AND resource_type = 'User' AND resource_erased = TRUE AND resource_id <> '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5c'"
    And there should have 1 records in SQL result
    # The evidence is LINKED to the trail it explains: the pseudonym on the compliance row is the same one the
    # subject's anonymised audit row carries. One person, one anonymous identity — which is what makes the
    # record worth keeping at all.
    And I execute the SQL query "SELECT c.id FROM audit_log c JOIN audit_log t ON t.actor_id = c.resource_id WHERE c.action = 'GDPR_SUBJECT_ERASED' AND t.id = '0190f200-0000-7000-8000-00000000ee21'"
    And there should have 1 records in SQL result
    # Neither axis of the business log keeps the subject's identifier. Two assertions, because one statement
    # covering both is exactly what a single-axis assertion would fail to notice.
    And I execute the SQL query "SELECT event_id FROM event_store WHERE aggregate_id = '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5c'"
    And there should have 0 records in SQL result
    And I execute the SQL query "SELECT event_id FROM event_store WHERE payload::text ILIKE '%0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5c%' OR metadata::text ILIKE '%0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5c%'"
    And there should have 0 records in SQL result
    # …and the log survives as a log. The guarantee is not bought by deleting history: each row is still
    # there, under its own event name and stream version, and each still carries the JSON key the identifier
    # was rewritten inside — an implementation that emptied the column instead of rewriting it would satisfy
    # every assertion above while destroying the trace this table exists to keep.
    And I execute the SQL query "SELECT event_id FROM event_store WHERE (event_id = '0190f200-0000-7000-8000-00000000ea01' AND event_name = 'erpify.iam.identity.suspended' AND aggregate_version = 1) OR (event_id = '0190f200-0000-7000-8000-00000000ea02' AND event_name = 'erpify.iam.invitation.created' AND jsonb_exists(payload, 'invitedUserId')) OR (event_id = '0190f200-0000-7000-8000-00000000ea03' AND event_name = 'erpify.iam.invitation.accepted' AND jsonb_exists(metadata, 'actor'))"
    And there should have 3 records in SQL result
    # Budget canary (20 on "default"). In one transaction (+2 BEGIN/COMMIT), in acquisition order: the
    # administrator-role EXISTS probe, the invitation ordered row lock and its delete, the identity find and
    # delete, the reset-token delete, the two-axis row lock, the actor-axis
    # anonymisation UPDATE, the
    # GDPR_SUBJECT_ERASED insert, the resource-axis anonymisation UPDATE, the event-store anonymisation UPDATE,
    # the session delete, the membership delete and the GDPR_ERASURE_EXECUTED insert
    # (= 16). The three table-touching deletes are listed in the order they run because that order is itself an
    # invariant — see ErasureLockOrderTest — so a reader re-deriving the chain from this list gets the lock
    # graph right rather than only the count. The row lock is the one that is pure cost: it rewrites nothing and exists only to fix the order
    # in which the two axis UPDATEs take their rows, so that two concurrent erasures of subjects who acted on
    # each other cannot take the same pair in opposite directions. Paying a round trip per erasure is the
    # price of that, and an erasure is rare enough that the trade is not close.
    # The event-store pass is ONE round trip whatever it matches, which is the cost argument for
    # erasing by value in a single statement rather than per event type — it is not evidence of coverage, since
    # a statement reaching one column or none costs the same +1. What proves the columns are reached are the
    # zero-row assertions above. The order of the middle three
    # is the design and not an accident: the compliance row is written BETWEEN the two UPDATEs, because the
    # actor pass mints the pseudonym the resource pass needs, and the resource pass has to find a row that
    # already exists. Each UPDATE is one round trip regardless of how many rows it matches, and the resource
    # one always matches at least that just-written row — which is how its identifier gets anonymised at all.
    # The remainder is the acting admin and its permissions resolved before the controller, plus the
    # SAVEPOINT/RELEASE pair DBAL emits because EraseIdentitySubject opens a nested transaction — those two
    # go through executeStatement() and are counted like any other round trip. The total is measured; this
    # decomposition is a reading aid, so check it against a real run before trusting any single term. The two reference deletes are one directed DELETE each, which is why they cost
    # exactly one round trip apiece and not one per row. A shift means an added round trip — re-measure,
    # don't just bump the number.
    And 20 requests got executed for doctrine connection "default"

  Scenario: Erasure forgets the subject where the trail NAMES them, not only where they acted
    # The crosswalk row: the subject is both actor and resource, which is what a self-service role change
    # produces. Erasing only the actor axis would leave the real id beside the fresh pseudonym in one row,
    # and `resourceId` is an indexed, queryable filter — so the pseudonym would be re-linkable to the person.
    Given I execute the SQL query "INSERT INTO audit_log (id, level, action, actor_type, actor_id, correlation_id, resource_type, resource_id, metadata, actor_erased, resource_erased, occurred_on) VALUES ('0190f200-0000-7000-8000-00000000ef01', 'security', 'USER_ROLES_CHANGED', 'user', '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5f', '0190f200-0000-7000-8000-00000000ef11', 'User', '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5f', '{}', false, false, '2026-03-02 12:00:00+00')" on connection "seed"
    And I am logged in as an administrator
    When I send a "DELETE" request to "/backoffice/users/0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5f"
    Then the response status code should be 204
    # Neither column names the person any more.
    And I execute the SQL query "SELECT id FROM audit_log WHERE resource_type = 'User' AND resource_id = '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5f'"
    And there should have 0 records in SQL result
    # Both axes collapsed onto the SAME pseudonym — one person must not become two anonymous identities.
    And I execute the SQL query "SELECT id FROM audit_log WHERE id = '0190f200-0000-7000-8000-00000000ef01' AND actor_id = resource_id AND resource_erased = TRUE"
    And there should have 1 records in SQL result

  Scenario: Erasure redacts the request metadata of a row nobody was authenticated to write
    # A self-service path (a throttled recovery, a failed login) names the subject as its resource while no
    # token exists, so the captured ip is the requester's — and the row records no discriminant for whether
    # that requester was the subject or a stranger acting on them. The actor pass cannot reach it either:
    # it matches `actor_id`, which is NULL wherever nobody was authenticated.
    # Its own subject, because the scenarios above erase the fixture ones.
    Given I execute the SQL query "INSERT INTO identity_user (id, email, password_hash, roles, status, failed_attempts, created_at, updated_at) VALUES ('0190f200-0000-7000-8000-00000000fb01', 'throttled@erpify.test', 'x', to_json(ARRAY[]::text[]), 'ACTIVE', 0, NOW(), NOW())" on connection "seed"
    And I execute the SQL query "INSERT INTO audit_log (id, level, action, actor_type, actor_id, correlation_id, resource_type, resource_id, metadata, ip, user_agent, actor_erased, resource_erased, occurred_on) VALUES ('0190f200-0000-7000-8000-00000000fb11', 'security', 'PASSWORD_RECOVERY_THROTTLED', 'anonymous', NULL, '0190f200-0000-7000-8000-00000000fb21', 'User', '0190f200-0000-7000-8000-00000000fb01', '{}', '198.51.100.23', 'ProbeAgent/1.0', false, false, '2026-03-03 12:00:00+00')" on connection "seed"
    # Seeded, not assumed. The negative below is satisfied by a row that was never inserted, and this repo
    # has shipped exactly that defect — so the positive claim is made here, before anything is erased.
    And I execute the SQL query "SELECT id FROM audit_log WHERE id = '0190f200-0000-7000-8000-00000000fb11' AND ip = '198.51.100.23' AND user_agent = 'ProbeAgent/1.0'" on connection "seed"
    And there should have 1 records in SQL result
    And I am logged in as an administrator
    When I send a "DELETE" request to "/backoffice/users/0190f200-0000-7000-8000-00000000fb01"
    Then the response status code should be 204
    # The negative: the captured address is gone from the whole table, not merely from this row.
    And I execute the SQL query "SELECT id FROM audit_log WHERE ip = '198.51.100.23'"
    And there should have 0 records in SQL result
    # The positive, on that exact row id: gone because it was REWRITTEN, not because the row was deleted or
    # never existed. `actor_erased` stays false — this actor was never identified, so it was never erased,
    # and raising the flag would corrupt the one column that answers "was there a person here?".
    And I execute the SQL query "SELECT id FROM audit_log WHERE id = '0190f200-0000-7000-8000-00000000fb11' AND ip = '[REDACTED]' AND user_agent = '[REDACTED]' AND actor_erased = FALSE AND resource_erased = TRUE"
    And there should have 1 records in SQL result
    # …and the row still does not name the person on the axis that named them.
    And I execute the SQL query "SELECT id FROM audit_log WHERE resource_type = 'User' AND resource_id = '0190f200-0000-7000-8000-00000000fb01'"
    And there should have 0 records in SQL result
    # Both halves in ONE statement, which is the claim the two functional tests can only make separately: the
    # erasure writes its own GDPR_SUBJECT_ERASED naming this subject with the ADMIN as actor before the pass
    # runs, so that row is matched by the same UPDATE and must come out with its request metadata intact. The
    # CASE is in the SET and evaluates per row; a guard moved to the WHERE would take both rows or neither.
    And I execute the SQL query "SELECT c.id FROM audit_log c JOIN audit_log t ON t.resource_id = c.resource_id WHERE t.id = '0190f200-0000-7000-8000-00000000fb11' AND c.action = 'GDPR_SUBJECT_ERASED' AND c.actor_type = 'user' AND c.resource_erased = TRUE AND COALESCE(c.ip, '') <> '[REDACTED]' AND COALESCE(c.user_agent, '') <> '[REDACTED]'"
    And there should have 1 records in SQL result

  Scenario: Erasure reaches the references no foreign key would have cascaded
    # membership.user_id and iam_invitation.invited_user_id cross a bounded context, so they are referenced
    # by id. Nothing in the schema references identity_user, so deleting it cascades nowhere and each has to
    # be erased by the context that owns it. A terminal invitation is seeded beside a live one — a retired delivery record
    # still carries the invited person's id, so the lifecycle state buys it no exemption.
    #
    # The subject is seeded here rather than taken from the fixtures because the scenarios above erase theirs,
    # and its membership carries ADMIN while its identity does not — the divergence a demote-then-erase leaves
    # behind, and the only shape in which an orphan ADMIN membership is reachable at all.
    Given I execute the SQL query "INSERT INTO identity_user (id, email, password_hash, roles, status, failed_attempts, created_at, updated_at) VALUES ('0190f200-0000-7000-8000-00000000fa01', 'demoted@erpify.test', 'x', to_json(ARRAY[]::text[]), 'ACTIVE', 0, NOW(), NOW())" on connection "seed"
    And I execute the SQL query "INSERT INTO membership (id, user_id, organization_id, roles, created_at, updated_at) VALUES ('0190f200-0000-7000-8000-00000000fa11', '0190f200-0000-7000-8000-00000000fa01', '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a50', to_json(ARRAY['ADMIN']::text[]), NOW(), NOW())" on connection "seed"
    And I execute the SQL query "INSERT INTO iam_invitation (id, organization_id, invited_user_id, token_hash, expires_at, status, created_at, updated_at) VALUES ('0190f200-0000-7000-8000-00000000fa21', '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a50', '0190f200-0000-7000-8000-00000000fa01', '70f0759d523f100bb31c67201828646721d77adfb44ce75ab59d9c6c5bf99b29', '2099-01-01 00:00:00', 'SENT', NOW(), NOW())" on connection "seed"
    And I execute the SQL query "INSERT INTO iam_invitation (id, organization_id, invited_user_id, token_hash, expires_at, status, created_at, updated_at) VALUES ('0190f200-0000-7000-8000-00000000fa31', '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a50', '0190f200-0000-7000-8000-00000000fa01', '70f0759d523f100bb31c67201828646721d77adfb44ce75ab59d9c6c5bf99b29', '2099-01-01 00:00:00', 'ACCEPTED', NOW(), NOW())" on connection "seed"
    And I am logged in as an administrator
    When I send a "DELETE" request to "/backoffice/users/0190f200-0000-7000-8000-00000000fa01"
    Then the response status code should be 204
    # Neither reference outlives the identity it pointed at.
    And I execute the SQL query "SELECT id FROM membership WHERE user_id = '0190f200-0000-7000-8000-00000000fa01'"
    And there should have 0 records in SQL result
    And I execute the SQL query "SELECT id FROM iam_invitation WHERE invited_user_id = '0190f200-0000-7000-8000-00000000fa01'"
    And there should have 0 records in SQL result
    # Scoped to the subject: the acting administrator's own membership is untouched.
    And I execute the SQL query "SELECT id FROM membership WHERE user_id = '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a66'"
    And there should have 1 records in SQL result

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

  Scenario: Self-erasure is refused even when the admin spells their own id in a different case
    Given I am logged in as an administrator
    When I send a "DELETE" request to "/backoffice/users/0190A1B2-C3D4-7E5F-8A9B-0C1D2E3F4A66"
    Then the response status code should be 409
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

  Scenario: An administrator cannot erase a peer administrator — 409 administrator-erasure-requires-demotion
    # Promote Trent on the side connection rather than seeding a second ADMIN fixture: the ≥1-active-admin
    # scenarios elsewhere assert the seeded admin is the last one, and a second fixture would silently defeat
    # them. Erasure is irreversible and pseudonymises the subject's whole attribution, so it refuses anyone
    # still carrying the role — the demotion has to be recorded first, as its own audited act.
    # `json_build_array` rather than a `["ADMIN"]` literal: the step matcher truncates on double quotes.
    Given I execute the SQL query "UPDATE identity_user SET roles = json_build_array('ADMIN') WHERE id = '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5d'" on connection "seed"
    And I am logged in as an administrator
    When I send a "DELETE" request to "/backoffice/users/0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5d"
    Then the response status code should be 409
    And the header "Content-Type" should be equal to "application/problem+json"
    And the JSON node "type" should be equal to "administrator-erasure-requires-demotion"
    And there should have 1 "Erpify\Iam\Identity\Domain\Entity\User" entities found by "id=0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5d"

  Scenario: Erasing a well-formed but unknown id is a 404 user-not-found
    Given I am logged in as an administrator
    When I send a "DELETE" request to "/backoffice/users/2e6d865c-17b0-476a-85f2-037bf6d3b3dc"
    Then the response status code should be 404
    And the JSON node "type" should be equal to "user-not-found"
