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
