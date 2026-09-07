Feature: Audit generic backoffice navigation via the kernel.terminate access-log hook
  As a security investigator
  In order to reconstruct an actor's journey beyond the explicitly instrumented actions
  I need every successful backoffice read recorded in the audit log, generically

  # The access-log hook runs on kernel.terminate (after the response is sent), classifies the
  # interaction through AuditPolicy and writes a route-derived `activity` action via the AuditLogger
  # seam. terminate runs after the request leaves the RequestStack, so the row only proves the hook
  # re-established the request context if its correlation_id matches the correlation id the server returned on the response and its actor
  # is the authenticated user (a real request) rather than `system` (off-request). The bank list has no
  # explicit instrumentation, so the generic ROUTE_* action is the only row it produces.
  Scenario: Listing banks records one generic activity audit row sealed with the request context
    When I send a "GET" request to "/backoffice/banks?limit=10"
    And I execute the SQL query "SELECT action, level, actor_type, actor_id, correlation_id FROM audit_log WHERE action = 'ROUTE_BACKOFFICE_BANK_SEARCH'"
    Then the response status code should be 200
    And the SQL result as JSON should be:
    """
    [
      {
        "action": "ROUTE_BACKOFFICE_BANK_SEARCH",
        "level": "activity",
        "actor_type": "user",
        "actor_id": "0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b",
        "correlation_id": "<correlationId>"
      }
    ]
    """

  # An infrastructure interaction has no place in an actor's timeline. AuditPolicy classifies the health
  # probe as non-business (its route is the `health` module), so the access-log hook writes nothing. This
  # is the behavioural counterpart of the AuditPolicy unit test: it proves the wiring end to end (matched
  # as /api, hook runs, policy excludes), not just the decision, and it guards the route-name heuristic
  # against a regression that would start auditing infra noise.
  Scenario: A health probe is infrastructure and records no audit row
    When I send a "GET" request to "/backoffice/health"
    And I execute the SQL query "SELECT action FROM audit_log WHERE action LIKE 'ROUTE_%HEALTH%'"
    Then the response status code should be 200
    And there should have 0 records in SQL result

  # A read of a single record must answer *which* record. The route declares its resource type on its
  # `defaults` and carries the id as its `{id}` parameter, so the extractor seals (Bank, id) onto the
  # generic activity row that otherwise stored resource_type/resource_id as null. The row is written
  # synchronously on terminate, like every generic activity capture.
  Scenario: Viewing a specific bank records which bank was accessed
    When I send a "GET" request to "/backoffice/banks/11111111-1111-7000-8000-000000000001"
    And I execute the SQL query "SELECT action, level, resource_type, resource_id, correlation_id FROM audit_log WHERE action = 'ROUTE_BACKOFFICE_BANK_GET' AND correlation_id = '<correlationId>'"
    Then the response status code should be 200
    And the SQL result as JSON should be:
    """
    [
      {
        "action": "ROUTE_BACKOFFICE_BANK_GET",
        "level": "activity",
        "resource_type": "Bank",
        "resource_id": "11111111-1111-7000-8000-000000000001",
        "correlation_id": "<correlationId>"
      }
    ]
    """
