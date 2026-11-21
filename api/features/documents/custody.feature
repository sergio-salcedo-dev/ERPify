Feature: List documents and read a document's custody trail
  As an administrator who must show what happened to a document
  In order to see whether a document is received, ready or quarantined, and who touched it, even after an erasure
  I need to list the documents' metadata and read one document's ordered custody trail

  # Both routes are ADMIN-only (`document.custody`). They serve no bytes, so `documents.read_enabled` does not gate them;
  # the sealed original filename is opened once per document and degrades to `null`, never failing the page. The
  # listing's caps, the cursor walk of a long trail, the query cost and the 410 of the erasure-ledger override are held
  # by the PHPUnit functional tests; this is the acceptance of what a caller sees.

  # The Documents tables are not part of the per-feature fixture backup, so each scenario starts them empty.
  Background:
    Given I execute the SQL query "TRUNCATE upload_intent, document_custody, document, document_series"
    And I add "Accept" header equal to "application/json"

  Scenario: An administrator lists the documents newest first with their names opened
    Given a document with id "0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1c01" named "foundation-plan.pdf" in state "ready" created 300 seconds ago
    And a document with id "0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1c02" named "site-photo.jpg" in state "quarantined" created 100 seconds ago
    And I am logged in as an administrator
    When I send a "GET" request to "/documents"
    Then the response status code should be 200
    And the JSON node "data" should have 2 elements
    And the JSON node "data[0].id" should be equal to "0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1c02"
    And the JSON node "data[0].name" should be equal to "site-photo.jpg"
    And the JSON node "data[0].state" should be equal to "quarantined"
    And the JSON node "data[1].name" should be equal to "foundation-plan.pdf"
    And the JSON node "data[1].state" should be equal to "ready"
    And the JSON node "data[0].uploaderId" should not exist
    And the JSON node "data[0].scopeId" should not exist
    And the JSON node "data[0].filenameCiphertext" should not exist
    And the header "Cache-Control" should contain "no-store"

  Scenario: A destroyed key lists no name and does not fail the page
    Given a document with id "0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1c11" named "kept.pdf" in state "ready" created 200 seconds ago
    And a document with id "0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1c12" named "shredded.pdf" in state "ready" created 100 seconds ago
    And the key of document "0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1c12" is destroyed
    And I am logged in as an administrator
    When I send a "GET" request to "/documents"
    Then the response status code should be 200
    And the JSON node "data[0].id" should be equal to "0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1c12"
    And the JSON node "data[0].name" should be null
    And the JSON node "data[1].name" should be equal to "kept.pdf"

  Scenario: The listing can be narrowed to one state and refuses a page past its cap
    Given a document with id "0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1c21" named "a.pdf" in state "ready" created 200 seconds ago
    And a document with id "0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1c22" named "b.pdf" in state "scan_failed" created 100 seconds ago
    And I am logged in as an administrator
    When I send a "GET" request to "/documents?filters[0][field]=state&filters[0][operator]=eq&filters[0][value]=scan_failed"
    Then the response status code should be 200
    And the JSON node "data" should have 1 element
    And the JSON node "data[0].id" should be equal to "0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1c22"
    And I send a "GET" request to "/documents?limit=500"
    And the response status code should be 422
    And the JSON node "type" should be equal to "validation-failed"

  Scenario: A viewer is refused both routes and nothing is read
    Given a document with id "0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1c31" named "secret.pdf" in state "ready" created 100 seconds ago
    And I am logged in as a viewer
    When I send a "GET" request to "/documents"
    Then the response status code should be 403
    And the response should not contain "secret.pdf"
    And I send a "GET" request to "/documents/0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1c31/custody"
    And the response status code should be 403
    And the response should not contain "secret.pdf"

  Scenario: An editor, who may file evidence, is refused both routes and nothing is read
    Given a document with id "0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1c31" named "secret.pdf" in state "ready" created 100 seconds ago
    And I am logged in as an editor
    When I send a "GET" request to "/documents"
    Then the response status code should be 403
    And the response should not contain "secret.pdf"
    And I send a "GET" request to "/documents/0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1c31/custody"
    And the response status code should be 403
    And the response should not contain "secret.pdf"

  Scenario: A manager, who holds every tier verb, is refused both routes and nothing is read
    Given a document with id "0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1c31" named "secret.pdf" in state "ready" created 100 seconds ago
    And I am logged in as a generic-tier user without the audit-reader role
    When I send a "GET" request to "/documents"
    Then the response status code should be 403
    And the response should not contain "secret.pdf"
    And I send a "GET" request to "/documents/0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1c31/custody"
    And the response status code should be 403
    And the response should not contain "secret.pdf"

  Scenario: The first page of a trail carries the metadata, the opened name and the rows in order
    Given a document with id "0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1c41" named "contract.pdf" in state "ready" created 100 seconds ago
    And the document "0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1c41" has 3 custody rows
    And I am logged in as an administrator
    When I send a "GET" request to "/documents/0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1c41/custody"
    Then the response status code should be 200
    And the JSON node "data.id" should be equal to "0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1c41"
    And the JSON node "data.name" should be equal to "contract.pdf"
    And the JSON node "data.state" should be equal to "ready"
    And the JSON node "data.entries" should have 3 elements
    And the JSON node "data.entries[0].seq" should be equal to the number 1
    And the JSON node "data.entries[0].event" should be equal to "received"
    And the JSON node "data.entries[0].actorId" should not be null
    And the JSON node "data.entries[1].event" should be equal to "scan_claimed"
    And the JSON node "data.entries[1].actorId" should be null
    And the JSON node "data.entries[2].seq" should be equal to the number 3
    And the JSON node "data.nextAfterSeq" should be null
    And the header "Cache-Control" should contain "no-store"

  Scenario: A later page of a trail carries the rows and nothing of the document
    Given a document with id "0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1c51" named "long.pdf" in state "ready" created 100 seconds ago
    And the document "0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1c51" has 5 custody rows
    And I am logged in as an administrator
    When I send a "GET" request to "/documents/0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1c51/custody?limit=2"
    Then the response status code should be 200
    And the JSON node "data.entries" should have 2 elements
    And the JSON node "data.nextAfterSeq" should be equal to the number 2
    And I send a "GET" request to "/documents/0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1c51/custody?limit=2&afterSeq=2"
    And the response status code should be 200
    And the JSON node "data.entries[0].seq" should be equal to the number 3
    And the JSON node "data.entries" should have 2 elements
    And the JSON node "data.nextAfterSeq" should be equal to the number 4
    And the JSON node "data.name" should not exist
    And the JSON node "data.id" should not exist
    And I send a "GET" request to "/documents/0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1c51/custody?limit=2&afterSeq=4"
    And the JSON node "data.entries" should have 1 element
    And the JSON node "data.nextAfterSeq" should be null

  Scenario: The trail of an erased document keeps its rows and the erased-person marker, and has no name
    Given a document with id "0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1c61" named "gone.pdf" in state "erased" created 100 seconds ago
    And the document "0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1c61" has 2 custody rows
    And the people named by the custody rows of document "0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1c61" have been erased
    And I am logged in as an administrator
    When I send a "GET" request to "/documents/0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1c61/custody"
    Then the response status code should be 200
    And the JSON node "data.state" should be equal to "erased"
    And the JSON node "data.name" should be null
    And the JSON node "data.entries" should have 2 elements
    And the JSON node "data.entries[0].actorId" should be equal to "00000000-0000-0000-0000-000000000000"

  Scenario: A malformed or out-of-range cursor is unprocessable
    Given a document with id "0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1c71" named "cursor.pdf" in state "ready" created 100 seconds ago
    And I am logged in as an administrator
    When I send a "GET" request to "/documents/0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1c71/custody?afterSeq=-1"
    Then the response status code should be 422
    And the JSON node "type" should be equal to "validation-failed"
    And I send a "GET" request to "/documents/0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1c71/custody?afterSeq=x"
    And the response status code should be 422
    And I send a "GET" request to "/documents/0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1c71/custody?limit=101"
    And the response status code should be 422

  Scenario: A malformed id is a bad request and an unknown id is not found on every page
    Given I am logged in as an administrator
    When I send a "GET" request to "/documents/not-a-uuid/custody"
    Then the response status code should be 400
    And the JSON node "type" should be equal to "invalid-uuid"
    And I send a "GET" request to "/documents/0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1c81/custody"
    And the response status code should be 404
    And the JSON node "type" should be equal to "document-not-found"
    And I send a "GET" request to "/documents/0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1c81/custody?afterSeq=3"
    And the response status code should be 404
    And the JSON node "type" should be equal to "document-not-found"

  Scenario: Reading a trail leaves one activity row that names the document and the admin and carries no name, digest or custody actor
    Given a document with id "0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1c91" named "audited.pdf" in state "ready" created 100 seconds ago
    And the document "0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1c91" has 2 custody rows
    And I am logged in as an administrator
    When I send a "GET" request to "/documents/0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1c91/custody"
    Then the response status code should be 200
    And I execute the SQL query "SELECT level, resource_type, resource_id FROM audit_log WHERE action = 'ROUTE_DOCUMENTS_CUSTODY' AND resource_id = '0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1c91'"
    And the SQL result as JSON should be:
    """
    [
      {
        "level": "activity",
        "resource_type": "Document",
        "resource_id": "0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1c91"
      }
    ]
    """
    And I execute the SQL query "SELECT id FROM audit_log WHERE action = 'ROUTE_DOCUMENTS_CUSTODY' AND metadata::text LIKE '%audited.pdf%'"
    And there should have 0 records in SQL result
    And I execute the SQL query "SELECT id FROM audit_log WHERE action = 'DOCUMENT_READ'"
    And there should have 0 records in SQL result
