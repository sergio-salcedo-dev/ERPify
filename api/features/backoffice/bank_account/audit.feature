Feature: Audit the access to a bank's accounts
  As a security investigator
  In order to keep a durable forensic trail of who consulted a bank's accounts
  I need every successful read of a bank's accounts recorded in the audit log

  # BANK_ACCOUNTS_VIEWED is recorded through the AuditLogger seam as an `activity` entry written
  # synchronously on kernel.terminate (after the response is sent), so no client-visible latency is added.
  # The `metadata` column is PII-free (never the IBAN); the bank is identified by its id alone, and the
  # actor is the authenticated user that read the accounts.
  Scenario: Listing a bank's accounts records exactly one forensic audit row
    Given I add "X-Correlation-Id" header equal to "01914e2a-7b3c-7def-8a2b-3c4d5e6f7a8b"
    When I send a "GET" request to "/backoffice/banks/11111111-1111-7000-8000-000000000001/accounts?limit=100"
    And I execute the SQL query "SELECT action, level, actor_type, actor_id, resource_type, resource_id, correlation_id, metadata FROM audit_log WHERE action = 'BANK_ACCOUNTS_VIEWED' AND correlation_id = '01914e2a-7b3c-7def-8a2b-3c4d5e6f7a8b'"
    Then the response status code should be 200
    And the SQL result as JSON should be:
    """
    [
      {
        "action": "BANK_ACCOUNTS_VIEWED",
        "level": "activity",
        "actor_type": "user",
        "actor_id": "0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b",
        "resource_type": "Bank",
        "resource_id": "11111111-1111-7000-8000-000000000001",
        "correlation_id": "01914e2a-7b3c-7def-8a2b-3c4d5e6f7a8b",
        "metadata": "[]"
      }
    ]
    """

  # Reading one account by id has no explicit instrumentation, so it is captured generically as
  # ROUTE_BACKOFFICE_BANK_ACCOUNT_GET. Its route declares the resource type on its `defaults` and carries
  # the id as `{id}`, so the extractor seals (BankAccount, id) onto the otherwise resource-less generic
  # activity row — answering which account was read, not only that some account was.
  Scenario: Reading a single account by id records which account was accessed
    Given I add "X-Correlation-Id" header equal to "01914e2a-7b3c-7def-8a2b-000000000001"
    When I send a "GET" request to "/backoffice/bank-accounts/33333333-3333-7000-8000-000000000001"
    And I execute the SQL query "SELECT action, level, resource_type, resource_id, correlation_id FROM audit_log WHERE action = 'ROUTE_BACKOFFICE_BANK_ACCOUNT_GET' AND correlation_id = '01914e2a-7b3c-7def-8a2b-000000000001'"
    Then the response status code should be 200
    And the SQL result as JSON should be:
    """
    [
      {
        "action": "ROUTE_BACKOFFICE_BANK_ACCOUNT_GET",
        "level": "activity",
        "resource_type": "BankAccount",
        "resource_id": "33333333-3333-7000-8000-000000000001",
        "correlation_id": "01914e2a-7b3c-7def-8a2b-000000000001"
      }
    ]
    """

  # Matching #426's non-logging IBAN lookup records which account was found, exactly like the
  # by-id read above — but over the dedicated POST endpoint, never the GET filters[] vocabulary.
  Scenario: Finding an account by IBAN records which account was accessed
    Given I add "Content-Type" header equal to "application/json"
    And I add "Accept" header equal to "application/json"
    And I add "X-Correlation-Id" header equal to "01914e2a-7b3c-7def-8a2b-000000000002"
    When I send a POST request to "/backoffice/bank-accounts/iban-lookup" with body:
    """
    {
      "iban": "DE89370400440532013000"
    }
    """
    And I execute the SQL query "SELECT action, level, resource_type, resource_id, correlation_id, metadata FROM audit_log WHERE action = 'BANK_ACCOUNT_LOOKED_UP_BY_IBAN' AND correlation_id = '01914e2a-7b3c-7def-8a2b-000000000002'"
    Then the response status code should be 200
    And the SQL result as JSON should be:
    """
    [
      {
        "action": "BANK_ACCOUNT_LOOKED_UP_BY_IBAN",
        "level": "activity",
        "resource_type": "BankAccount",
        "resource_id": "33333333-3333-7000-8000-000000000001",
        "correlation_id": "01914e2a-7b3c-7def-8a2b-000000000002",
        "metadata": "[]"
      }
    ]
    """

  # A miss is itself an auditable access — otherwise a caller holding only `bankAccount.read` could
  # probe arbitrary IBANs with no forensic trace of the misses. No resource (there is no account to
  # key it by) and, like every row here, no trace of the IBAN itself anywhere in the row.
  Scenario: Failing to find an account by IBAN still records the attempt, with no resource and no IBAN
    Given I add "Content-Type" header equal to "application/json"
    And I add "Accept" header equal to "application/json"
    And I add "X-Correlation-Id" header equal to "01914e2a-7b3c-7def-8a2b-000000000003"
    When I send a POST request to "/backoffice/bank-accounts/iban-lookup" with body:
    """
    {
      "iban": "ES9121000418450200051332"
    }
    """
    And I execute the SQL query "SELECT action, level, resource_type, resource_id, correlation_id, metadata FROM audit_log WHERE action = 'BANK_ACCOUNT_IBAN_LOOKUP_MISSED' AND correlation_id = '01914e2a-7b3c-7def-8a2b-000000000003'"
    Then the response status code should be 404
    And the SQL result as JSON should be:
    """
    [
      {
        "action": "BANK_ACCOUNT_IBAN_LOOKUP_MISSED",
        "level": "activity",
        "resource_type": null,
        "resource_id": null,
        "correlation_id": "01914e2a-7b3c-7def-8a2b-000000000003",
        "metadata": "[]"
      }
    ]
    """

  # E2: a write of a BankAccount is captured as a crypto-shredded `change` row. Its personal-data fields
  # (holderName/iban) travel encrypted under a per-subject key, so `jsonb_typeof` sees the sealed
  # `{"__enc__": …}` marker (an object), never a plaintext string; non-PII (bic) stays in clear and the row
  # references its encryption scope. The onFlush capture commits synchronously inside the write transaction.
  Scenario: Creating a bank account records a crypto-shredded BANK_ACCOUNT_CREATED change row
    Given I add "Content-Type" header equal to "application/json"
    And I add "Accept" header equal to "application/json"
    And I add "X-Correlation-Id" header equal to "0190ffff-0000-7abc-8def-00aabbccdd01"
    When I send a POST request to "/backoffice/bank-accounts" with body:
    """
    {
      "bankId": "11111111-1111-7000-8000-000000000003",
      "holderName": "Acme Holdings",
      "iban": "GB82WEST12345698765432",
      "bic": "westgb22xxx",
      "alias": "Acme Ops",
      "currency": "EUR"
    }
    """
    Then the response status code should be 201
    And I execute the SQL query "SELECT action, level, resource_type, split_part(encryption_scope_id, ':', 1) AS scope_type, jsonb_typeof(metadata->'changes'->'holderName'->'new') AS holder_type, jsonb_typeof(metadata->'changes'->'iban'->'new') AS iban_type, metadata->'changes'->'bic'->>'new' AS new_bic FROM audit_log WHERE level = 'change' AND correlation_id = '0190ffff-0000-7abc-8def-00aabbccdd01'"
    And the SQL result as JSON should be:
    """
    [
      {
        "action": "BANK_ACCOUNT_CREATED",
        "level": "change",
        "resource_type": "BankAccount",
        "scope_type": "BankAccount",
        "holder_type": "object",
        "iban_type": "object",
        "new_bic": "WESTGB22XXX"
      }
    ]
    """
