Feature: Audit generic backoffice navigation via the kernel.terminate access-log hook
  As a security investigator
  In order to reconstruct an actor's journey beyond the explicitly instrumented actions
  I need every successful backoffice read recorded in the audit log, generically

  # The access-log hook runs on kernel.terminate (after the response is sent), classifies the
  # interaction through AuditPolicy and emits a route-derived `activity` action via the AuditLogger
  # seam. terminate runs after the request leaves the RequestStack, so the row only proves the hook
  # re-established the request context if its correlation_id matches the request header and its actor
  # is `anonymous` (a real request) rather than `system` (off-request). The bank list has no explicit
  # instrumentation, so the generic ROUTE_* action is the only row it produces.
  Scenario: Listing banks records one generic activity audit row sealed with the request context
    Given I add "X-Correlation-Id" header equal to "0190abcd-1234-7abc-8def-001122334455"
    When I send a "GET" request to "/backoffice/banks?limit=10"
    And the "audit" transport should hold 1 message
    And I consume 1 message from the "audit" transport
    And I execute the SQL query "SELECT action, level, actor_type, actor_id, correlation_id FROM audit_log WHERE action = 'ROUTE_BACKOFFICE_BANK_SEARCH'"
    Then the response status code should be 200
    And the SQL result as JSON should be:
    """
    [
      {
        "action": "ROUTE_BACKOFFICE_BANK_SEARCH",
        "level": "activity",
        "actor_type": "anonymous",
        "actor_id": null,
        "correlation_id": "0190abcd-1234-7abc-8def-001122334455"
      }
    ]
    """

  # An infrastructure interaction has no place in an actor's timeline. AuditPolicy classifies the health
  # probe as non-business (its route is the `health` module), so the access-log hook enqueues nothing —
  # the audit transport stays empty. This is the behavioural counterpart of the AuditPolicy unit test:
  # it proves the wiring end to end (matched as /api, hook runs, policy excludes), not just the decision,
  # and it guards the route-name heuristic against a regression that would start auditing infra noise.
  Scenario: A health probe is infrastructure and records no audit row
    When I send a "GET" request to "/backoffice/health"
    Then the response status code should be 200
    And the "audit" transport should hold 0 messages

  # A read of a single record must answer *which* record. The route declares its resource type on its
  # `defaults` and carries the id as its `{id}` parameter, so the extractor seals (Bank, id) onto the
  # generic activity row that otherwise stored resource_type/resource_id as null. The entry is async on
  # the `audit` transport, like every generic activity capture.
  Scenario: Viewing a specific bank records which bank was accessed
    Given I add "X-Correlation-Id" header equal to "0190dcba-4321-7abc-8def-554433221100"
    When I send a "GET" request to "/backoffice/banks/11111111-1111-7000-8000-000000000001"
    And the "audit" transport should hold 1 message
    And I consume 1 message from the "audit" transport
    And I execute the SQL query "SELECT action, level, resource_type, resource_id, correlation_id FROM audit_log WHERE action = 'ROUTE_BACKOFFICE_BANK_GET' AND correlation_id = '0190dcba-4321-7abc-8def-554433221100'"
    Then the response status code should be 200
    And the SQL result as JSON should be:
    """
    [
      {
        "action": "ROUTE_BACKOFFICE_BANK_GET",
        "level": "activity",
        "resource_type": "Bank",
        "resource_id": "11111111-1111-7000-8000-000000000001",
        "correlation_id": "0190dcba-4321-7abc-8def-554433221100"
      }
    ]
    """
