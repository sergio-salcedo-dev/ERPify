Feature: Self-audit every authorized read of the audit trail (audit the auditor)
  As a security officer
  In order to make access to the audit log itself auditable (ISO 27001 A.5.18/A.8.15)
  I need every authorized read of the two audit routes to leave one synchronous security row naming who read what

  # Alice (the default session) is granted auditTrail.read through her AUDIT_READER role, so these reads succeed. Each granted read emits a
  # `security` `AUDIT_TRAIL_READ` row through the durable write-before-send path, the same as the `ACCESS_DENIED`
  # denial. The two routes carry `_audit_canonical`, so the generic access-log hook yields and no second, thinner
  # `activity` row is written — proven by the exact-match SQL below returning only the one security row.
  Background:
    Given I add "Accept" header equal to "application/json"

  Scenario: Reading the timeline records one synchronous security row and the generic activity hook yields
    Given I add "X-Correlation-Id" header equal to "0190a1de-0004-7abc-8def-001122334455"
    When I send a "GET" request to "/backoffice/audit/timeline"
    And I execute the SQL query "SELECT action, level, actor_type, actor_id, metadata FROM audit_log WHERE correlation_id = '0190a1de-0004-7abc-8def-001122334455'"
    Then the response status code should be 200
    And the SQL result as JSON should be:
    """
    [
      {
        "action": "AUDIT_TRAIL_READ",
        "level": "security",
        "actor_type": "user",
        "actor_id": "0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b",
        "metadata": "{\"route\": \"backoffice_audit_timeline_search\"}"
      }
    ]
    """

  Scenario: Reading an event by id records the read event id in the security row
    Given I add "X-Correlation-Id" header equal to "0190a1de-0003-7abc-8def-001122334455"
    And I execute the SQL query "INSERT INTO audit_log (id, level, action, actor_type, actor_id, correlation_id, resource_type, resource_id, metadata, actor_erased, occurred_on) VALUES ('0190a002-0000-7000-8000-0000000000bb','change','BANK_UPDATED','user','11111111-1111-7111-8111-111111111111','0190c000-0000-7000-8000-0000000000bb','Bank','22222222-2222-7222-8222-222222222222','{}',false,'2026-03-01 12:00:00+00')"
    When I send a "GET" request to "/backoffice/audit/events/0190a002-0000-7000-8000-0000000000bb"
    And I execute the SQL query "SELECT action, level, actor_type, actor_id, metadata FROM audit_log WHERE correlation_id = '0190a1de-0003-7abc-8def-001122334455'"
    Then the response status code should be 200
    And the SQL result as JSON should be:
    """
    [
      {
        "action": "AUDIT_TRAIL_READ",
        "level": "security",
        "actor_type": "user",
        "actor_id": "0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b",
        "metadata": "{\"route\": \"backoffice_audit_event_detail\", \"auditEventId\": \"0190a002-0000-7000-8000-0000000000bb\"}"
      }
    ]
    """

  Scenario: A 404 read of a non-existent event records no self-audit row
    Given I add "X-Correlation-Id" header equal to "0190a1de-0005-7abc-8def-001122334455"
    When I send a "GET" request to "/backoffice/audit/events/0190a002-0000-7000-8000-0000000000cc"
    And I execute the SQL query "SELECT action FROM audit_log WHERE action = 'AUDIT_TRAIL_READ' AND correlation_id = '0190a1de-0005-7abc-8def-001122334455'"
    Then the response status code should be 404
    And there should have 0 records in SQL result
