Feature: Audit permission denials as durable security entries
  As a security investigator
  In order to detect access attempts that were refused
  I need every denied request recorded at the security level, synchronously

  # The access-denied listener hooks kernel.exception ahead of the Problem Details responder, finds the
  # Security-Core AccessDeniedException and emits ACCESS_DENIED at the `security` level. Unlike `activity`,
  # `security` is a synchronous write-before-send insert — the row already exists when the response is
  # returned, with no transport to consume. The throw route is reached by an absolute URL to bypass the
  # default /api/v1 base prefix (same as the error-contract suite).
  Scenario: A denied request records exactly one security audit row sealed with the request context
    Given I add "X-Correlation-Id" header equal to "0190dead-beef-7abc-8def-001122334455"
    When I send a "GET" request to "http://localhost/api/test/_throw-security-access-denied"
    And I execute the SQL query "SELECT action, level, actor_type, actor_id, resource_type, resource_id, correlation_id, metadata FROM audit_log WHERE action = 'ACCESS_DENIED'"
    Then the response status code should be 403
    And the SQL result as JSON should be:
    """
    [
      {
        "action": "ACCESS_DENIED",
        "level": "security",
        "actor_type": "user",
        "actor_id": "0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b",
        "resource_type": null,
        "resource_id": null,
        "correlation_id": "0190dead-beef-7abc-8def-001122334455",
        "metadata": "{\"route\": \"test_throw_security_access_denied\"}"
      }
    ]
    """
