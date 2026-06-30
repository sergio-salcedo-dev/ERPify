Feature: Capture every write of an audited aggregate as a regulatory change row
  As a compliance investigator
  In order to reconstruct who changed which field, from what value to what value
  I need every create/update/delete of an audited aggregate recorded with its field-level diff

  # The onFlush change-data-capture listener runs inside the use-case transaction, so the change row
  # commits with the business write. It seals the request's correlation id (like the access-log hook), so
  # scoping the query by correlation id isolates this scenario's row from the append-only table's history.
  # The query reaches into the JSONB metadata to prove the field-level diff travels end to end.
  Scenario: Creating a bank records a BANK_CREATED change row carrying the field diff
    Given I add "Content-Type" header equal to "application/json"
    And I add "Accept" header equal to "application/json"
    And I add "X-Correlation-Id" header equal to "0190ffff-0000-7abc-8def-00aabbccddee"
    When I send a POST request to "/backoffice/banks" with body:
    """
    {"name": "Audited Bank", "shortName": "AUD"}
    """
    Then the response status code should be 201
    And I execute the SQL query "SELECT action, level, actor_type, resource_type, metadata->'changes'->'name'->>'new' AS new_name FROM audit_log WHERE level = 'change' AND correlation_id = '0190ffff-0000-7abc-8def-00aabbccddee'"
    And the SQL result as JSON should be:
    """
    [
      {
        "action": "BANK_CREATED",
        "level": "change",
        "actor_type": "anonymous",
        "resource_type": "Bank",
        "new_name": "Audited Bank"
      }
    ]
    """
