Feature: Reopen a document whose scan failed
  As an administrator who has fixed what stopped a scan
  In order to get a failed document scanned again without touching the database
  I need to ask for a re-scan with a reason from a closed list

  # Only a ScanFailed document is reopened: Ready is never rescanned, Quarantined is a verdict and the other states are
  # mid-flight or terminal. The permission is carried by the ADMIN wildcard alone, so an editor who may file evidence
  # may not reopen it. The scan itself is not run here; the operator decision is.

  Background:
    Given I execute the SQL query "TRUNCATE upload_intent, document_custody, document"
    And I add "Accept" header equal to "application/json"
    And I add "Content-Type" header equal to "application/json"

  Scenario: An administrator reopens a failed scan and the decision is recorded under their name
    Given a document in state "scan_failed" with id "0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1a01"
    And I am logged in as an administrator
    When I send a "POST" request to "/documents/0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1a01/rescan" with body:
    """
    {"reason": "scanner_outage_resolved"}
    """
    Then the response status code should be 200
    And the JSON node "data.state" should be equal to "received"
    And the JSON node "data.id" should be equal to "0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1a01"
    And I execute the SQL query "SELECT id FROM document WHERE id = '0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1a01' AND state = 'received' AND scan_attempts = 0 AND scan_outage_returns = 0 AND lease_epoch = 1 AND first_claim_recorded = FALSE AND next_scan_at <= NOW()"
    And there should have 1 records in SQL result
    And I execute the SQL query "SELECT id FROM document_custody WHERE document_id = '0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1a01' AND event = 'rescan_requested' AND actor_id IS NOT NULL"
    And there should have 1 records in SQL result

  Scenario: An editor may not reopen a failed scan and nothing changes
    Given a document in state "scan_failed" with id "0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1a02"
    And I am logged in as an editor
    When I send a "POST" request to "/documents/0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1a02/rescan" with body:
    """
    {"reason": "scanner_outage_resolved"}
    """
    Then the response status code should be 403
    And I execute the SQL query "SELECT id FROM document WHERE state = 'scan_failed'"
    And there should have 1 records in SQL result
    And I execute the SQL query "SELECT id FROM document_custody"
    And there should have 0 records in SQL result

  Scenario Outline: A document that is not in ScanFailed is refused with a conflict and nothing changes
    Given a document in state "<state>" with id "0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1a03"
    And I am logged in as an administrator
    When I send a "POST" request to "/documents/0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1a03/rescan" with body:
    """
    {"reason": "failure_investigated"}
    """
    Then the response status code should be 409
    And the JSON node "type" should be equal to "document-not-rescannable"
    And I execute the SQL query "SELECT id FROM document WHERE state = '<state>' AND lease_epoch = 0"
    And there should have 1 records in SQL result
    And I execute the SQL query "SELECT id FROM document_custody"
    And there should have 0 records in SQL result

    Examples:
      | state       |
      | received    |
      | ready       |
      | quarantined |
      | erased      |

  Scenario Outline: A reason outside the closed list, or none, is refused before the document is touched
    Given a document in state "scan_failed" with id "0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1a04"
    And I am logged in as an administrator
    When I send a "POST" request to "/documents/0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1a04/rescan" with body:
    """
    <body>
    """
    Then the response status code should be 422
    And the JSON node "type" should be equal to "validation-failed"
    And I execute the SQL query "SELECT id FROM document WHERE state = 'scan_failed'"
    And there should have 1 records in SQL result

    Examples:
      | body                                                 |
      | {"reason": "because I said so"}                      |
      | {}                                                   |
      | {"reason": "failure_investigated", "state": "ready"} |

  Scenario: A document that does not exist is not found
    Given I am logged in as an administrator
    When I send a "POST" request to "/documents/0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1aff/rescan" with body:
    """
    {"reason": "failure_investigated"}
    """
    Then the response status code should be 404
    And the JSON node "type" should be equal to "document-not-found"

  Scenario: A malformed document id is a bad request before any lookup
    Given I am logged in as an administrator
    When I send a "POST" request to "/documents/not-a-uuid/rescan" with body:
    """
    {"reason": "failure_investigated"}
    """
    Then the response status code should be 400
    And the JSON node "type" should be equal to "invalid-uuid"
