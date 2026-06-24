Feature: Audit the access to a bank's accounts
  As a security investigator
  In order to keep a durable forensic trail of who consulted a bank's accounts
  I need every successful read of a bank's accounts recorded in the audit log

  # BANK_ACCOUNTS_VIEWED is recorded through the AuditLogger seam as an async `activity` entry on the
  # dedicated `audit` transport, so the row only materialises after that transport is consumed — the
  # request path itself stays free of audit IO. The `metadata` column is PII-free (never the IBAN); the
  # bank is identified by its id alone, and the actor is `anonymous` until authentication exists.
  Scenario: Listing a bank's accounts records exactly one forensic audit row
    Given I add "X-Correlation-Id" header equal to "01914e2a-7b3c-7def-8a2b-3c4d5e6f7a8b"
    When I send a "GET" request to "/backoffice/banks/11111111-1111-7000-8000-000000000001/accounts?limit=100"
    And I consume 1 message from the "audit" transport
    And I execute the SQL query "SELECT action, level, actor_type, actor_id, resource_type, resource_id, correlation_id, metadata FROM audit_log WHERE action = 'BANK_ACCOUNTS_VIEWED'"
    Then the response status code should be 200
    And the SQL result as JSON should be:
    """
    [
      {
        "action": "BANK_ACCOUNTS_VIEWED",
        "level": "activity",
        "actor_type": "anonymous",
        "actor_id": null,
        "resource_type": "Bank",
        "resource_id": "11111111-1111-7000-8000-000000000001",
        "correlation_id": "01914e2a-7b3c-7def-8a2b-3c4d5e6f7a8b",
        "metadata": "[]"
      }
    ]
    """
