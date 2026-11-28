Feature: File a new version of a document and erase a whole series
  As a bounded context that must keep every original it was given
  In order to replace a document without modifying it
  I need to file a new immutable version linked to the one it replaces, and to erase a series version by version

  # A new version is the declare and the raw-body PUT of `ingest.feature`, with the predecessor in the declare's path.
  # Only one live version may succeed a predecessor: the commit decides that under a per-series lock, so the second of
  # two uploads racing for one slot is aborted with a conflict. The interleavings, the erased-run cascade and the
  # reopen are held by the PHPUnit functional tests; this is the acceptance of what a caller sees. Filing a version
  # needs `document.write` and `document.download`, so only an administrator can.

  # The Documents tables are not part of the per-feature fixture backup, so each scenario starts them empty.
  Background:
    Given I execute the SQL query "TRUNCATE bank_account_evidence, upload_intent, document_custody, document, document_series"
    And I add "Accept" header equal to "application/json"
    And I add "Content-Type" header equal to "application/json"

  Scenario: A new version of a ready document is filed in its series and linked to it
    Given a ready document with id "0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1f01" holding "first"
    And I am logged in as an administrator
    And I send a "POST" request to "/documents/0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1f01/versions" with body:
    """
    {"size": 6, "retentionReason": "technical-file", "legalBasis": "contract"}
    """
    And the response status code should be 201
    And the JSON node "data.state" should be equal to "declared"
    When I add "Content-Type" header equal to "application/octet-stream"
    And I add "Content-Length" header equal to "6"
    And I send a "PUT" request to "/documents/uploads/{value}/content?filename=v2.pdf" using the JSON node "data.id" from the previous response with body:
    """
    second
    """
    Then the response status code should be 201
    And the JSON node "data.state" should be equal to "received"
    And I execute the SQL query "SELECT id FROM document WHERE predecessor_id = '0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1f01' AND series_id = '0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1f01' AND state = 'received'"
    And there should have 1 records in SQL result

  Scenario: The second of two uploads for one slot is aborted with a conflict at its commit
    Given a ready document with id "0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1f02" holding "first"
    And I am logged in as an administrator
    And I send a "POST" request to "/documents/0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1f02/versions" with body:
    """
    {"size": 6, "sha256": "16367aacb67a4a017c8da8ab95682ccb390863780f7114dda0a0e0c55644c7c4", "idempotencyKey": "acceptance-version-a", "retentionReason": "technical-file", "legalBasis": "contract"}
    """
    And the response status code should be 201
    And I send a "POST" request to "/documents/0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1f02/versions" with body:
    """
    {"size": 5, "retentionReason": "technical-file", "legalBasis": "contract"}
    """
    And the response status code should be 201
    And I add "Content-Type" header equal to "application/octet-stream"
    And I add "Content-Length" header equal to "5"
    And I send a "PUT" request to "/documents/uploads/{value}/content?filename=v2b.pdf" using the JSON node "data.id" from the previous response with body:
    """
    other
    """
    And the response status code should be 201
    And I add "Content-Type" header equal to "application/json"
    And I send a "POST" request to "/documents/0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1f02/versions" with body:
    """
    {"size": 6, "sha256": "16367aacb67a4a017c8da8ab95682ccb390863780f7114dda0a0e0c55644c7c4", "idempotencyKey": "acceptance-version-a", "retentionReason": "technical-file", "legalBasis": "contract"}
    """
    And the response status code should be 200
    And the JSON node "data.state" should be equal to "declared"
    When I add "Content-Type" header equal to "application/octet-stream"
    And I add "Content-Length" header equal to "6"
    And I send a "PUT" request to "/documents/uploads/{value}/content?filename=v2a.pdf" using the JSON node "data.id" from the previous response with body:
    """
    second
    """
    Then the response status code should be 409
    And the JSON node "type" should be equal to "document-version-slot-taken"
    And I execute the SQL query "SELECT id FROM document WHERE predecessor_id = '0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1f02'"
    And there should have 1 records in SQL result
    And I execute the SQL query "SELECT id FROM upload_intent WHERE state = 'expired' AND declared_size = 6"
    And there should have 1 records in SQL result

  Scenario: An editor may file evidence but not a version of a document it may not download
    Given a ready document with id "0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1f06" holding "first"
    And I am logged in as an editor
    When I send a "POST" request to "/documents/0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1f06/versions" with body:
    """
    {"size": 6, "retentionReason": "technical-file", "legalBasis": "contract"}
    """
    Then the response status code should be 403
    And I execute the SQL query "SELECT id FROM upload_intent"
    And there should have 0 records in SQL result

  Scenario: A version of a document that is not ready is refused before a byte is sent
    Given a document in state "received" with id "0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1f03"
    And I am logged in as an administrator
    When I send a "POST" request to "/documents/0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1f03/versions" with body:
    """
    {"size": 6, "retentionReason": "technical-file", "legalBasis": "contract"}
    """
    Then the response status code should be 409
    And the JSON node "type" should be equal to "document-predecessor-not-ready"
    And I execute the SQL query "SELECT id FROM upload_intent"
    And there should have 0 records in SQL result

  Scenario: An administrator erases a series newest first, closes it, and is told what remains
    Given a ready document with id "0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1f04" holding "first"
    And I execute the SQL query "INSERT INTO document (id, scope_id, series_id, predecessor_id, uploader_id, state, digest, size_bytes, next_scan_at, created_at, updated_at) VALUES ('0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1f05', gen_random_uuid(), '0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1f04', '0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1f04', gen_random_uuid(), 'ready', repeat('b', 64), 6, NOW(), NOW() + INTERVAL '1 second', NOW())"
    And I am logged in as an administrator
    When I send a "POST" request to "/documents/series/0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1f04/erase" with body:
    """
    {"reason": "retention_expired", "currentPassword": "admin-password"}
    """
    Then the response status code should be 200
    And the JSON node "data.erased[0]" should be equal to "0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1f05"
    And the JSON node "data.erased[1]" should be equal to "0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1f04"
    And the JSON node "data.remaining" should have 0 elements
    And I execute the SQL query "SELECT id FROM document WHERE series_id = '0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1f04' AND state = 'erased'"
    And there should have 2 records in SQL result
    And I execute the SQL query "SELECT id FROM document_series WHERE id = '0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1f04' AND closed_at IS NOT NULL"
    And there should have 1 records in SQL result
