Feature: Erase a document by destroying its key
  As an administrator who must erase a document
  In order to make its bytes permanently unreadable while keeping what proves it existed
  I need to erase it with a reason from a closed list, recorded in the ledger before the key is destroyed

  # Only the ADMIN wildcard carries `document.erase`, and the caller re-proves their own password first (an erasure cannot be
  # undone even from a backup): a wrong one writes nothing, not even a ledger entry. The route accepts every non-terminal state and answers a repeat as the
  # first call did. The entry reaches the erasure ledger BEFORE the transaction that destroys the key, so a ledger that cannot
  # make it durable leaves the document untouched. The crash points, the lock order, the sweeps and the restore replay are
  # held by the PHPUnit functional tests; this is the acceptance of what a caller sees.

  # The Documents tables are not part of the per-feature fixture backup, so each scenario starts them empty.
  Background:
    Given I execute the SQL query "TRUNCATE upload_intent, document_custody, document, document_series"
    And I add "Accept" header equal to "application/json"
    And I add "Content-Type" header equal to "application/json"

  Scenario Outline: An administrator erases a document from any non-terminal state
    Given a document with id "0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1e01" named "plan.pdf" in state "<state>" created 100 seconds ago
    And I am logged in as an administrator
    When I send a "POST" request to "/documents/0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1e01/erase" with body:
    """
    {"reason": "data_subject_request", "currentPassword": "admin-password"}
    """
    Then the response status code should be 200
    And the JSON node "data.state" should be equal to "erased"
    And the JSON node "data.id" should be equal to "0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1e01"
    And the erasure ledger holds the scope of document "0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1e01"
    And the key of document "0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1e01" is tombstoned as an erasure
    And I execute the SQL query "SELECT id FROM document WHERE id = '0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1e01' AND state = 'erased' AND filename_ciphertext IS NULL"
    And there should have 1 records in SQL result
    And I execute the SQL query "SELECT id FROM document_custody WHERE document_id = '0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1e01' AND event = 'erased' AND actor_id IS NOT NULL AND reason = 'data_subject_request'"
    And there should have 1 records in SQL result

    Examples:
      | state       |
      | received    |
      | scanning    |
      | ready       |
      | quarantined |
      | scan_failed |

  Scenario: A repeat answers as the first call did and writes nothing
    Given a document with id "0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1e02" named "plan.pdf" in state "ready" created 100 seconds ago
    And I am logged in as an administrator
    When I send a "POST" request to "/documents/0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1e02/erase" with body:
    """
    {"reason": "uploaded_in_error", "currentPassword": "admin-password"}
    """
    Then the response status code should be 200
    And I send a "POST" request to "/documents/0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1e02/erase" with body:
    """
    {"reason": "legal_obligation", "currentPassword": "admin-password"}
    """
    And the response status code should be 200
    And the JSON node "data.state" should be equal to "erased"
    And I execute the SQL query "SELECT id FROM document_custody WHERE document_id = '0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1e02' AND event = 'erased'"
    And there should have 1 records in SQL result

  Scenario: An editor may not erase and nothing changes
    Given a document with id "0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1e03" named "plan.pdf" in state "ready" created 100 seconds ago
    And I am logged in as an editor
    When I send a "POST" request to "/documents/0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1e03/erase" with body:
    """
    {"reason": "data_subject_request", "currentPassword": "admin-password"}
    """
    Then the response status code should be 403
    And the erasure ledger holds no entry
    And the key of document "0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1e03" is not tombstoned
    And I execute the SQL query "SELECT id FROM document WHERE state = 'ready'"
    And there should have 1 records in SQL result

  Scenario Outline: A reason outside the closed list, a system reason, or a missing password is refused before anything is written
    Given a document with id "0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1e04" named "plan.pdf" in state "ready" created 100 seconds ago
    And I am logged in as an administrator
    When I send a "POST" request to "/documents/0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1e04/erase" with body:
    """
    <body>
    """
    Then the response status code should be 422
    And the JSON node "type" should be equal to "validation-failed"
    And the erasure ledger holds no entry
    And the key of document "0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1e04" is not tombstoned

    Examples:
      | body                                                          |
      | {"reason": "ledger-replay", "currentPassword": "admin-password"} |
      | {"reason": "ledger-completion", "currentPassword": "admin-password"} |
      | {"reason": "because I said so", "currentPassword": "admin-password"} |
      | {"reason": "data_subject_request"}                            |
      | {"reason": "data_subject_request", "currentPassword": ""}     |
      | {}                                                            |
      | {"reason": "data_subject_request", "currentPassword": "admin-password", "state": "erased"}         |

  Scenario: A wrong current password is refused before anything is written, the ledger included
    Given a document with id "0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1e08" named "plan.pdf" in state "ready" created 100 seconds ago
    And I am logged in as an administrator
    When I send a "POST" request to "/documents/0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1e08/erase" with body:
    """
    {"reason": "data_subject_request", "currentPassword": "not-the-admin-password"}
    """
    Then the response status code should be 403
    And the JSON node "type" should be equal to "invalid-current-password"
    And the erasure ledger holds no entry
    And the key of document "0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1e08" is not tombstoned
    And I execute the SQL query "SELECT id FROM document WHERE state = 'ready' AND filename_ciphertext IS NOT NULL"
    And there should have 1 records in SQL result
    And I execute the SQL query "SELECT id FROM document_custody"
    And there should have 0 records in SQL result

  @anonymous
  Scenario: An unauthenticated request is refused and nothing is written
    Given a document with id "0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1e09" named "plan.pdf" in state "ready" created 100 seconds ago
    When I send a "POST" request to "/documents/0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1e09/erase" with body:
    """
    {"reason": "data_subject_request", "currentPassword": "admin-password"}
    """
    Then the response status code should be 401
    And the erasure ledger holds no entry
    And the key of document "0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1e09" is not tombstoned

  Scenario: A ledger that cannot make the entry durable leaves the document untouched and the request retryable
    Given a document with id "0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1e05" named "plan.pdf" in state "ready" created 100 seconds ago
    And the erasure ledger cannot make an entry durable
    And I am logged in as an administrator
    When I send a "POST" request to "/documents/0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1e05/erase" with body:
    """
    {"reason": "data_subject_request", "currentPassword": "admin-password"}
    """
    Then the response status code should be 503
    And the JSON node "type" should be equal to "erasure-ledger-unavailable"
    And the key of document "0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1e05" is not tombstoned
    And I execute the SQL query "SELECT id FROM document WHERE state = 'ready' AND filename_ciphertext IS NOT NULL"
    And there should have 1 records in SQL result
    And I execute the SQL query "SELECT id FROM document_custody"
    And there should have 0 records in SQL result

  Scenario: A write that times out says the erasure may still complete and erases nothing itself
    Given a document with id "0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1e06" named "plan.pdf" in state "ready" created 100 seconds ago
    And the erasure ledger write times out after the entry landed
    And I am logged in as an administrator
    When I send a "POST" request to "/documents/0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1e06/erase" with body:
    """
    {"reason": "data_subject_request", "currentPassword": "admin-password"}
    """
    Then the response status code should be 503
    And the JSON node "type" should be equal to "erasure-outcome-unknown"
    And the erasure ledger holds the scope of document "0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1e06"
    And the key of document "0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1e06" is not tombstoned
    And I execute the SQL query "SELECT id FROM document WHERE state = 'ready'"
    And there should have 1 records in SQL result

  Scenario: A document that does not exist is not found and nothing is written to the ledger
    Given I am logged in as an administrator
    When I send a "POST" request to "/documents/0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1eff/erase" with body:
    """
    {"reason": "data_subject_request", "currentPassword": "admin-password"}
    """
    Then the response status code should be 404
    And the JSON node "type" should be equal to "document-not-found"
    And the erasure ledger holds no entry

  Scenario: A malformed document id is a bad request before any lookup
    Given I am logged in as an administrator
    When I send a "POST" request to "/documents/not-a-uuid/erase" with body:
    """
    {"reason": "data_subject_request", "currentPassword": "admin-password"}
    """
    Then the response status code should be 400
    And the JSON node "type" should be equal to "invalid-uuid"

  Scenario: While the ledger override is set the listing answers gone for any well-formed id
    Given a document with id "0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1e07" named "plan.pdf" in state "ready" created 100 seconds ago
    And the erasure ledger override is set
    And I am logged in as an administrator
    When I send a "GET" request to "/documents"
    Then the response status code should be 410
    And the JSON node "type" should be equal to "gone"
    And I send a "GET" request to "/documents/0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1e99/custody"
    And the response status code should be 410
