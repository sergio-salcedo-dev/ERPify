Feature: Server-side session registry and admission gate
  As the session subsystem
  In order that "authenticated" means "has a live, revocable session"
  I need login to mint a registry session, the gate to force re-login once it is revoked or expired, and the
  owner to read and revoke their own sessions

  Background:
    Given I add "Accept" header equal to "application/json"

  Scenario: The signed-in user reads their own identity and real roles
    When I send a "GET" request to "/me"
    Then the response status code should be 200
    And the JSON node "data.id" should be equal to "0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b"
    And the JSON node "data.email" should be equal to "alice@erpify.test"
    And the JSON node "data.roles" should have 2 elements
    And the JSON node "data.roles[0]" should be equal to "AUDIT_READER"
    And the JSON node "data.roles[1]" should be equal to "MANAGER"

  # Permissions are derived from the roles on every read, never stored: MANAGER earns the bank and
  # bankAccount tier verbs, AUDIT_READER adds the audit grant, and bankAccount.changeStatus arrives through
  # an explicit grant rather than a tier verb. Neither role reaches the identity plane, so the set holds no
  # `users.*` — pinned exhaustively, since the count alone would not catch one capability swapped for another.
  Scenario: The signed-in user reads the permissions their roles grant
    When I send a "GET" request to "/me"
    Then the response status code should be 200
    And the JSON node "data.permissions" should have 8 elements
    And the JSON nodes should be equal to:
      | data.permissions[0] | auditTrail.read          |
      | data.permissions[1] | bank.read                |
      | data.permissions[2] | bank.write               |
      | data.permissions[3] | bank.delete              |
      | data.permissions[4] | bankAccount.read         |
      | data.permissions[5] | bankAccount.write        |
      | data.permissions[6] | bankAccount.delete       |
      | data.permissions[7] | bankAccount.changeStatus |

  # Deriving the set is a catalog walk over an in-memory policy: it must cost nothing at the database.
  Scenario: Deriving the permission set adds no query
    When I send a "GET" request to "/me"
    Then the response status code should be 200
    And 1 requests got executed only for doctrine connection "default"

  Scenario: My sessions lists the current device, distinguished
    When I send a "GET" request to "/sessions"
    Then the response status code should be 200
    And the JSON node "data" should have 1 elements
    And the JSON node "data[0].current" should be true
    And the JSON node "data[0].device" should be equal to "Behat test client"

  # Caducity is decided per query rather than by a persisted transition, so nothing ever writes an expired
  # session out of `ACTIVE` — the row stays there and only the read predicate keeps it out of the listing. That
  # makes listing a dead session as live an INFORMATION defect the gate cannot catch: it refuses the request
  # carrying that session, while "my sessions" would still show it as a place the account is signed in. The
  # seeded SELECT is what stops the scenario passing vacuously, since an INSERT that matched nothing would
  # leave the count at 1 for the wrong reason.
  Scenario: A session past its expiry is absent from my sessions though its row still reads ACTIVE
    Given I execute the SQL query "INSERT INTO iam_session (id, user_id, organization_id, status, expires_at, device, created_at, updated_at) VALUES ('0190d1e2-f3a4-7b5c-8d6e-1f2a3b4c5dfe', '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b', '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a50', 'ACTIVE', NOW() - INTERVAL '1 hour', 'A lapsed device', NOW(), NOW())"
    And I execute the SQL query "SELECT id FROM iam_session WHERE id = '0190d1e2-f3a4-7b5c-8d6e-1f2a3b4c5dfe' AND status = 'ACTIVE' AND expires_at < NOW()"
    And there should have 1 records in SQL result
    When I send a "GET" request to "/sessions"
    Then the response status code should be 200
    And the JSON node "data" should have 1 elements
    And the JSON node "data[0].current" should be true
    And the JSON node "data[0].device" should be equal to "Behat test client"

  Scenario: Signing out other devices revokes them but never the current session
    Given I execute the SQL query "INSERT INTO iam_session (id, user_id, organization_id, status, expires_at, device, created_at, updated_at) VALUES ('0190d1e2-f3a4-7b5c-8d6e-1f2a3b4c5dff', '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b', '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a50', 'ACTIVE', NOW() + INTERVAL '1 day', 'A second device', NOW(), NOW())"
    And I send a "GET" request to "/sessions"
    And the JSON node "data" should have 2 elements
    When I send a "POST" request to "/sessions/revoke-others"
    Then the response status code should be 204
    And I send a "GET" request to "/sessions"
    And the response status code should be 200
    And the JSON node "data" should have 1 elements
    And the JSON node "data[0].current" should be true

  Scenario: Signing out this device revokes its session so the cookie is inert
    When I send a "POST" request to "/sessions/revoke-current"
    Then the response status code should be 204
    And I send a "GET" request to "/me"
    And the response status code should be 401

  Scenario: A revoked session is inert on its next request
    # Earlier scenarios in this feature revoke the shared registry session; reload so this one starts ACTIVE.
    Given I reload the fixtures
    And I send a "GET" request to "/me"
    And the response status code should be 200
    When I execute the SQL query "UPDATE iam_session SET status = 'REVOKED' WHERE id = '0190d1e2-f3a4-7b5c-8d6e-1f2a3b4c5d01'"
    And I send a "GET" request to "/me"
    Then the response status code should be 401
    And the header "Content-Type" should contain "application/problem+json"
    And the JSON node "type" should be equal to "session-expired"

  Scenario: A time-expired session is inert on its next request
    # Reload first so the session is genuinely ACTIVE before we age it out, isolating the time-expiry path.
    Given I reload the fixtures
    When I execute the SQL query "UPDATE iam_session SET expires_at = NOW() - INTERVAL '1 hour' WHERE id = '0190d1e2-f3a4-7b5c-8d6e-1f2a3b4c5d01'"
    And I send a "GET" request to "/me"
    Then the response status code should be 401
    And the JSON node "type" should be equal to "session-expired"

  # The failed-attempt lockout is weighed on the login path alone (UserChecker::checkPostAuth); the gate reads
  # session status and expiry and nothing else. So a caller already inside keeps their session for its full TTL
  # while the same identity is barred from signing in again. This is pinned because it is the property that
  # bounds the lockout's blast radius: were the gate ever taught to read the lock, whoever can trip it from
  # outside would eject live sessions instead of merely barring new logins, and the seeded SELECT below is what
  # keeps the scenario from passing vacuously if the lock were never actually set.
  Scenario: A lockout bars the door without ejecting a session already inside
    Given I reload the fixtures
    And I send a "GET" request to "/me"
    And the response status code should be 200
    When I execute the SQL query "UPDATE identity_user SET failed_attempts = 10, locked_until = '2099-01-01 00:00:00' WHERE id = '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b'"
    And I execute the SQL query "SELECT id FROM identity_user WHERE id = '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b' AND locked_until > NOW()"
    And there should have 1 records in SQL result
    And I send a "GET" request to "/me"
    Then the response status code should be 200
    And the JSON node "data.email" should be equal to "alice@erpify.test"

  # Corrupt persisted identity data is unreachable through the type-safe writer, so it arrives by an
  # out-of-band write or — the realistic one — by an enum that evolves incompatibly with stored rows. Both
  # re-hydration sites are read on EVERY authenticated request, so what they do is a property of the whole
  # authenticated surface rather than of one endpoint. The seeded SELECT is what keeps each scenario from
  # passing vacuously if the corrupting UPDATE ever stopped matching.
  Scenario: An unreadable credential denies the identity instead of raising a 500
    Given I reload the fixtures
    And I send a "GET" request to "/me"
    And the response status code should be 200
    When I execute the SQL query "UPDATE identity_user SET password_hash = '' WHERE id = '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b'"
    And I execute the SQL query "SELECT id FROM identity_user WHERE id = '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b' AND password_hash = ''"
    And there should have 1 records in SQL result
    And I send a "GET" request to "/me"
    Then the response status code should be 401
    # A scenario that leaves the credential corrupt poisons the next one, and no opening step can undo it: the
    # session of the following scenario is minted from the database before any of its steps run, so it would
    # carry the corrupt copy into a request its own reload has already repaired. Restoring here is what keeps
    # each credential scenario independent of the order they happen to sit in.
    And I reload the fixtures

  # The credential is re-hydrated at TWO sites and the provider owns only one of them. The firewall reads the
  # copy serialised into the session as well — it compares that copy against the reloaded identity to decide
  # whether the token still stands — and the comparison passes through no provider, so a stored hash the value
  # object refuses escapes from inside the firewall, where it carries no marker and lands as a 500 on every
  # request the cookie is sent with. An unreadable credential cannot be compared, so the session carrying one
  # is equal to nothing and the caller is sent back to the door: the same fail-closed the provider applies.
  #
  # The order of the seeding is the whole scenario. Corrupting BEFORE the session is minted and repairing
  # AFTER is what puts an unreadable copy against a readable row — and the second SELECT is what stops the
  # scenario passing for the wrong reason, since against a still-corrupt row the provider would answer 401 on
  # its own and the assertion below would hold with the session copy never once consulted. That SELECT runs on
  # a named connection because reloading the fixtures re-creates the database from its template, which leaves
  # the connection the earlier queries opened pointing at a database that no longer exists.
  Scenario: A session whose serialised credential is unreadable is refused instead of raising a 500
    Given I reload the fixtures
    And I execute the SQL query "UPDATE identity_user SET password_hash = '' WHERE email = 'victor@erpify.test'"
    And I execute the SQL query "SELECT id FROM identity_user WHERE email = 'victor@erpify.test' AND password_hash = ''"
    And there should have 1 records in SQL result
    And I am logged in as a viewer
    And I reload the fixtures
    And I execute the SQL query "SELECT id FROM identity_user WHERE email = 'victor@erpify.test' AND password_hash <> ''" on connection "after-reload"
    And there should have 1 records in SQL result
    When I send a "GET" request to "/me"
    Then the response status code should be 401
    And the JSON node "type" should be equal to "unauthenticated"

  # Both credential-replacement flows swallow a failed session revoke, and the grounds they state for it is that
  # a credential change de-authenticates the old sessions natively. This is that claim made falsifiable: the
  # firewall compares the credential the session carries against the one it just reloaded, and refuses when they
  # part. Without it, a revoke that failed would leave the previous credential's cookie working — which is the
  # containment those flows exist to perform, swallowed on a guarantee nothing was checking.
  #
  # The UPDATE is deliberately out-of-band so the registry is never told: a revoked row would let the admission
  # gate answer `session-expired` and the scenario would prove nothing about the firewall. The seeded SELECT on
  # the still-ACTIVE registry row is what holds that line.
  Scenario: A credential replaced underneath a live session de-authenticates it natively
    Given I reload the fixtures
    And I send a "GET" request to "/me"
    And the response status code should be 200
    When I execute the SQL query "UPDATE identity_user SET password_hash = 'a-different-hash' WHERE id = '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b'"
    And I execute the SQL query "SELECT id FROM identity_user WHERE id = '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b' AND password_hash = 'a-different-hash'"
    And there should have 1 records in SQL result
    And I execute the SQL query "SELECT id FROM iam_session WHERE id = '0190d1e2-f3a4-7b5c-8d6e-1f2a3b4c5d01' AND status = 'ACTIVE'"
    And there should have 1 records in SQL result
    And I send a "GET" request to "/me"
    Then the response status code should be 401
    And the JSON node "type" should be equal to "unauthenticated"
    # See the restore above: the replaced credential must not reach the next scenario's session.
    And I reload the fixtures

  # The asymmetry with the credential above is the whole decision, and it is not a preference: the permission
  # model is grant-only, so an unrecognised role can concede nothing and dropping it can only narrow. Failing
  # the identity instead would convert a value that grants nothing into a total loss of authentication — and,
  # for an installation whose administrator carried the retired role, into a lockout with no administrative
  # unlock to undo it.
  #
  # The corrupting UPDATE keeps the identity's known roles and only ADDS the unknown one. That is load-bearing,
  # not incidental: the firewall ejects a session whose refreshed role set differs from the one its token
  # carries (the scenario below pins that), so replacing the set would refuse the request before the filter
  # under test ever ran, and this scenario would report the ejection as if it were the filter. The permission
  # count is what proves the discarded value conceded nothing — it is the same 8 the uncorrupted identity holds.
  Scenario: A role the enum no longer knows is discarded without taking the others with it
    Given I reload the fixtures
    When I execute the SQL query "UPDATE identity_user SET roles = to_json(ARRAY['AUDIT_READER','MANAGER','GHOST_ROLE']) WHERE id = '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b'"
    And I execute the SQL query "SELECT id FROM identity_user WHERE id = '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b' AND roles::text LIKE '%GHOST_ROLE%'"
    And there should have 1 records in SQL result
    And I send a "GET" request to "/me"
    Then the response status code should be 200
    And the JSON node "data.roles" should have 2 elements
    And the JSON node "data.roles[0]" should be equal to "AUDIT_READER"
    And the JSON node "data.roles[1]" should be equal to "MANAGER"
    And the JSON node "data.permissions" should have 8 elements
    # See the credential scenarios above: the next scenario's session is minted from the database before any
    # of its steps run, so a corrupt row left here rides into a request its own reload has already repaired.
    And I reload the fixtures

  # A role change ejects that identity's live sessions: the firewall reloads the user on every request and drops
  # the token when the refreshed role set no longer matches the one the token carries. That is the property that
  # makes a revocation bite immediately instead of at the session's TTL, and it is why the scenario above must
  # not replace the role set. The refusal is `unauthenticated` and NOT the registry gate's `session-expired`,
  # and the seeded SELECT on the still-ACTIVE registry row is what proves the distinction: the row the gate
  # reads is untouched, so what refused is the firewall's own refresh.
  Scenario: Changing the roles of a signed-in identity ejects its live session
    Given I reload the fixtures
    And I send a "GET" request to "/me"
    And the response status code should be 200
    When I execute the SQL query "UPDATE identity_user SET roles = to_json(ARRAY['ADMIN']) WHERE id = '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b'"
    And I execute the SQL query "SELECT id FROM identity_user WHERE id = '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b' AND roles::text LIKE '%ADMIN%' AND roles::text NOT LIKE '%MANAGER%'"
    And there should have 1 records in SQL result
    And I execute the SQL query "SELECT id FROM iam_session WHERE id = '0190d1e2-f3a4-7b5c-8d6e-1f2a3b4c5d01' AND status = 'ACTIVE'"
    And there should have 1 records in SQL result
    And I send a "GET" request to "/me"
    Then the response status code should be 401
    And the JSON node "type" should be equal to "unauthenticated"

  @anonymous
  Scenario: An identity whose stored roles hold a retired value can still sign in
    # The ejection above is survivable only if the door still opens afterwards, and that is the whole
    # anti-lockout argument: were the retired value to refuse the identity, the enum change that retired it
    # would bar whoever carried it, with no administrative unlock — the administrator being the likeliest
    # holder of an ADMIN row. Login re-reads the same filtered accessor the session does, so the survivors
    # come back and the retired value is simply absent.
    Given I reload the fixtures
    And I add "Content-Type" header equal to "application/json"
    And I add "Origin" header equal to "http://localhost"
    And I execute the SQL query "UPDATE identity_user SET roles = to_json(ARRAY['ADMIN','GHOST_ROLE']) WHERE id = '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b'"
    And I execute the SQL query "SELECT id FROM identity_user WHERE id = '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b' AND roles::text LIKE '%GHOST_ROLE%'"
    And there should have 1 records in SQL result
    When I send a POST request to "/backoffice/login" with body:
    """
    {
      "email": "alice@erpify.test",
      "password": "alice-password"
    }
    """
    Then the response status code should be 204
    And I send a "GET" request to "/me"
    And the response status code should be 200
    And the JSON node "data.roles" should have 1 elements
    And the JSON node "data.roles[0]" should be equal to "ADMIN"
    And I reload the fixtures

  @anonymous
  Scenario: A successful login mints a live registry session the gate then admits
    # An earlier scenario seals the lockout on this identity to prove the gate ignores it; reload so the login
    # door under test is genuinely open, or this would read a 403 wall as a broken handshake.
    Given I reload the fixtures
    And I add "Content-Type" header equal to "application/json"
    And I add "Origin" header equal to "http://localhost"
    When I send a POST request to "/backoffice/login" with body:
    """
    {
      "email": "alice@erpify.test",
      "password": "alice-password"
    }
    """
    Then the response status code should be 204
    # The minted session carries the request through the gate: without a live registry row the next request
    # would 401, so reaching /me proves login established a real, gate-admissible session.
    And I send a "GET" request to "/me"
    And the response status code should be 200
    And the JSON node "data.email" should be equal to "alice@erpify.test"

  # The provider's fail-closed needs a scenario the session comparison cannot answer for it. Every other
  # corrupt-credential path in this file reaches `SecurityUser::isEqualTo` first, which refuses too — so with
  # the provider's guard deleted they all stay green, and the guard would be free to be removed. Login is the
  # one path that never reaches the comparison: there is no session copy yet, so the only thing standing
  # between a stored hash the value object refuses and a marker-less 500 is `UserProvider` itself.
  @anonymous
  Scenario: An unreadable credential denies the login instead of raising a 500
    Given I reload the fixtures
    And I add "Content-Type" header equal to "application/json"
    And I add "Origin" header equal to "http://localhost"
    And I execute the SQL query "UPDATE identity_user SET password_hash = '' WHERE email = 'alice@erpify.test'"
    And I execute the SQL query "SELECT id FROM identity_user WHERE email = 'alice@erpify.test' AND password_hash = ''"
    And there should have 1 records in SQL result
    When I send a POST request to "/backoffice/login" with body:
    """
    {
      "email": "alice@erpify.test",
      "password": "alice-password"
    }
    """
    # The correct password, so nothing but the unreadable hash can be refusing — and refused as the uniform
    # `unauthenticated`, indistinguishable from an unknown email, rather than leaking the integrity fault.
    Then the response status code should be 401
    And the JSON node "type" should be equal to "unauthenticated"
    And I reload the fixtures
