@anonymous
Feature: Stored identity integrity inspection
  In order to find out that a stored identity row drifted out of what the code can represent
  As an operator
  I need "identity:integrity:inspect" to read the columns the authentication path handles silently

  # The runtime is deliberately tolerant of both shapes: an unknown role is discarded so authorization can only
  # narrow, and an unreadable credential is refused with the same 401 an unknown email gets. Neither raises and
  # neither logs a finding, so the drift is invisible to every gate — which is the whole reason this command
  # exists, and the reason each scenario below seeds the corruption itself rather than trusting a clean run.
  # Nothing schedules the command today — these scenarios are the only thing that runs it unattended.

  Scenario: A clean table reports the columns it checked, and succeeds
    Given I reload the fixtures
    When I run the "identity:integrity:inspect" command
    Then the last run should succeed
    And the last run output should contain "identity_user.roles"
    And the last run output should contain "identity_user.password_hash"
    # A clean run writes nothing: a trail that gains a row every time a scheduled check finds nothing buries
    # the runs that found something.
    And I execute the SQL query "SELECT id FROM audit_log WHERE action = 'STORED_IDENTITY_DRIFT_DETECTED'"
    And there should have 0 records in SQL result

  # ONE row per run that finds something, whatever the finding's size. The refusals cannot carry it: both are
  # re-evaluated on every authenticated request, so a row at the point of refusal would be bounded by nothing
  # but the traffic of a broken account. Two drifted identities and a single row is the assertion that says so.
  Scenario: A run that finds drift records exactly one security entry, whatever the finding's size
    Given I reload the fixtures
    And I execute the SQL query "UPDATE identity_user SET roles = to_json(ARRAY['MANAGER','GHOST_ROLE']) WHERE email = 'alice@erpify.test'"
    And I execute the SQL query "UPDATE identity_user SET password_hash = '' WHERE email = 'victor@erpify.test'"
    And I execute the SQL query "SELECT id FROM identity_user WHERE roles::text LIKE '%GHOST_ROLE%' OR password_hash = ''"
    And there should have 2 records in SQL result
    When I run the "identity:integrity:inspect" command
    Then the last run should fail
    And I execute the SQL query "SELECT id FROM audit_log WHERE action = 'STORED_IDENTITY_DRIFT_DETECTED'"
    And there should have 1 records in SQL result
    And I execute the SQL query "SELECT id FROM audit_log WHERE action = 'STORED_IDENTITY_DRIFT_DETECTED' AND level = 'security'"
    And there should have 1 records in SQL result
    # No request is in flight, so the actor is the truth rather than a fallback — and the drifted identities
    # are exactly the person ids this axis would otherwise have to erase.
    And I execute the SQL query "SELECT id FROM audit_log WHERE action = 'STORED_IDENTITY_DRIFT_DETECTED' AND actor_type = 'system' AND resource_id IS NULL"
    And there should have 1 records in SQL result
    And I reload the fixtures

  Scenario: A role value the enum no longer knows is reported by name
    Given I reload the fixtures
    And I execute the SQL query "UPDATE identity_user SET roles = to_json(ARRAY['MANAGER','GHOST_ROLE']) WHERE email = 'alice@erpify.test'"
    And I execute the SQL query "SELECT id FROM identity_user WHERE email = 'alice@erpify.test' AND roles::text LIKE '%GHOST_ROLE%'"
    And there should have 1 records in SQL result
    When I run the "identity:integrity:inspect" command
    Then the last run should fail
    And the last run output should contain "GHOST_ROLE"
    # The live cases must never be reported as drift, or every run would be a finding and the control would be
    # ignored within a week. MANAGER sits in the very same column as the orphan above.
    And the last run output should not contain "MANAGER"
    And I reload the fixtures

  # The count is reported and the identities are not, and that is a contract rather than a convenience: an
  # identity id is a person reference, and this output reaches operator terminals and the logs of anything
  # that comes to run it unattended. Asserting the id's ABSENCE is the only thing that keeps the decision from decaying
  # into a listing the next person adds for convenience.
  Scenario: An unreadable credential is counted without naming the identity
    Given I reload the fixtures
    And I execute the SQL query "UPDATE identity_user SET password_hash = '' WHERE email = 'alice@erpify.test'"
    And I execute the SQL query "SELECT id FROM identity_user WHERE email = 'alice@erpify.test' AND password_hash = ''"
    And there should have 1 records in SQL result
    When I run the "identity:integrity:inspect" command
    Then the last run should fail
    # `should fail` accepts INVALID too, and the no-verdict message happens to carry the column name — so
    # without a discriminator this scenario would stay green over a probe that never ran. The count line is
    # printed on the finding path alone.
    And the last run output should not contain "NOT a finding"
    And the last run output should contain "1 identity(ies)."
    And the last run output should contain "identity_user.password_hash"
    And the last run output should not contain "0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b"
    And I reload the fixtures

  # A credential-less INVITED identity is a lifecycle state, not corruption: counting it would make the control
  # fire on every installation that has an outstanding invitation, which is the fastest way to get a detective
  # control switched off.
  Scenario: An identity that has not set a credential yet is not a finding
    Given I reload the fixtures
    And I execute the SQL query "UPDATE identity_user SET password_hash = NULL, status = 'INVITED' WHERE email = 'alice@erpify.test'"
    And I execute the SQL query "SELECT id FROM identity_user WHERE email = 'alice@erpify.test' AND password_hash IS NULL"
    And there should have 1 records in SQL result
    When I run the "identity:integrity:inspect" command
    Then the last run should succeed
    And I reload the fixtures

  # A JSON `null` element expands to SQL NULL, and `NULL NOT IN (…)` is NULL rather than TRUE — so the row is
  # dropped by the very predicate written to catch it. The control reported clean over the corruption it exists
  # to find, which is the one failure a detective control may not have.
  Scenario: A null element inside the role array is reported, not silently dropped
    Given I reload the fixtures
    And I execute the SQL query "UPDATE identity_user SET roles = '[null]'::json WHERE email = 'alice@erpify.test'"
    And I execute the SQL query "SELECT id FROM identity_user WHERE email = 'alice@erpify.test' AND roles::text = '[null]'"
    And there should have 1 records in SQL result
    When I run the "identity:integrity:inspect" command
    Then the last run should fail
    And the last run output should not contain "NOT a finding"
    And the last run output should contain "null"
    And I reload the fixtures

  # A `roles` column that is not an array at all aborts the expander, and a set-returning function in FROM is
  # evaluated before any WHERE could exclude the row. Left unhandled it turned one corrupt cell into INVALID
  # for the whole table — telling the operator NOT to repair the only row that needed it, and hiding the
  # credential column, which is read after.
  Scenario: A roles column that is not a JSON array is a finding, not a failed read
    Given I reload the fixtures
    And I execute the SQL query "UPDATE identity_user SET roles = '{}'::json WHERE email = 'alice@erpify.test'"
    And I execute the SQL query "UPDATE identity_user SET password_hash = '' WHERE email = 'victor@erpify.test'"
    And I execute the SQL query "SELECT id FROM identity_user WHERE json_typeof(roles) <> 'array'"
    And there should have 1 records in SQL result
    When I run the "identity:integrity:inspect" command
    Then the last run should fail
    And the last run output should not contain "NOT a finding"
    And the last run output should contain "not a JSON array"
    # The credential column is still reported: one malformed cell must not cost every other finding.
    And the last run output should contain "credentials the value object refuses"
    And I reload the fixtures

  # The quietest shape of all: nothing raises, because the value object is never asked. The firewall reads a
  # null password as "cannot authenticate" and answers the same uniform 401 a wrong password gets, so an
  # ACTIVE identity is locked out with no signal at all — and every other control stays green.
  Scenario: An admitted identity holding no credential is a finding
    Given I reload the fixtures
    And I execute the SQL query "UPDATE identity_user SET password_hash = NULL WHERE email = 'alice@erpify.test'"
    And I execute the SQL query "SELECT id FROM identity_user WHERE email = 'alice@erpify.test' AND password_hash IS NULL AND status = 'ACTIVE'"
    And there should have 1 records in SQL result
    When I run the "identity:integrity:inspect" command
    Then the last run should fail
    And the last run output should not contain "NOT a finding"
    And the last run output should contain "admitted identities holding no credential"
    And I reload the fixtures
