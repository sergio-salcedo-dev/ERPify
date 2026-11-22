Feature: Read the original of a document through an audited route
  As an administrator who must show the original byte
  In order to retrieve evidence that was filed and verified
  I need a route that serves a ready document byte for byte and records that I read it

  # Every refusal and every integrity failure is decided before the first byte, and the one audit row is written only
  # for a read that was granted. The route is ADMIN-only (`document.download`) and answers 404 while
  # `documents.read_enabled` is false. Headers and the byte-level matrix of verification are held by the PHPUnit
  # functional tests; this is the acceptance of what a caller sees.

  # The Documents tables are not part of the per-feature fixture backup, so each scenario starts them empty.
  Background:
    Given I execute the SQL query "TRUNCATE upload_intent, document_custody, document, document_series"
    And I add "Accept" header equal to "application/json"

  Scenario: An administrator reads the original and the read is audited once
    Given a ready document with id "0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1b01" holding "the original byte"
    And I am logged in as an administrator
    When I send a "GET" request to "/documents/0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1b01/content"
    Then the response status code should be 200
    And the response body should be "the original byte"
    And the header "Content-Type" should be equal to "application/octet-stream"
    And the header "Content-Length" should be equal to "17"
    And the header "Content-Disposition" should be equal to "attachment"
    And the header "X-Content-Type-Options" should be equal to "nosniff"
    And the header "Cross-Origin-Resource-Policy" should be equal to "same-origin"
    And the header "Cache-Control" should contain "no-store"
    And the header "ETag" should not exist
    And I execute the SQL query "SELECT level, resource_type, actor_type FROM audit_log WHERE action = 'DOCUMENT_READ' AND resource_id = '0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1b01'"
    And the SQL result as JSON should be:
    """
    [
      {
        "level": "security",
        "resource_type": "Document",
        "actor_type": "user"
      }
    ]
    """
    And I execute the SQL query "SELECT id FROM audit_log WHERE action = 'ROUTE_DOCUMENTS_READ'"
    And there should have 0 records in SQL result

  Scenario: A viewer may not read a document and nothing is recorded
    Given a ready document with id "0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1b11" holding "the original byte"
    And I am logged in as a viewer
    When I send a "GET" request to "/documents/0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1b11/content"
    Then the response status code should be 403
    And I execute the SQL query "SELECT id FROM audit_log WHERE action = 'DOCUMENT_READ' AND resource_id = '0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1b11'"
    And there should have 0 records in SQL result

  Scenario: An editor, who may file evidence, may not read a document and nothing is recorded
    Given a ready document with id "0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1b12" holding "the original byte"
    And I am logged in as an editor
    When I send a "GET" request to "/documents/0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1b12/content"
    Then the response status code should be 403
    And I execute the SQL query "SELECT id FROM audit_log WHERE action = 'DOCUMENT_READ' AND resource_id = '0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1b12'"
    And there should have 0 records in SQL result

  Scenario: A manager, who holds every tier verb, may not read a document and nothing is recorded
    Given a ready document with id "0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1b13" holding "the original byte"
    And I am logged in as a generic-tier user without the audit-reader role
    When I send a "GET" request to "/documents/0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1b13/content"
    Then the response status code should be 403
    And I execute the SQL query "SELECT id FROM audit_log WHERE action = 'DOCUMENT_READ' AND resource_id = '0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1b13'"
    And there should have 0 records in SQL result

  Scenario: While reads are switched off the route answers as if it did not exist
    Given a ready document with id "0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1b03" holding "the original byte"
    And I am logged in as an administrator
    And document reads are switched off
    When I send a "GET" request to "/documents/0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1b03/content"
    Then the response status code should be 404
    And the JSON node "type" should be equal to "not-found"
    And I execute the SQL query "SELECT id FROM audit_log WHERE action = 'DOCUMENT_READ' AND resource_id = '0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1b03'"
    And there should have 0 records in SQL result

  Scenario: A malformed id is a bad request and an unknown id is not found
    Given I am logged in as an administrator
    When I send a "GET" request to "/documents/not-a-uuid/content"
    Then the response status code should be 400
    And the JSON node "type" should be equal to "invalid-uuid"
    And I send a "GET" request to "/documents/0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1b04/content"
    And the response status code should be 404
    And the JSON node "type" should be equal to "document-not-found"

  Scenario Outline: A document that is not ready is refused with a conflict and nothing is recorded
    Given a document in state "<state>" with id "0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1b05"
    And I am logged in as an administrator
    When I send a "GET" request to "/documents/0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1b05/content"
    Then the response status code should be 409
    And the JSON node "type" should be equal to "document-not-readable"
    And I execute the SQL query "SELECT id FROM audit_log WHERE action = 'DOCUMENT_READ' AND resource_id = '0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1b05'"
    And there should have 0 records in SQL result

    Examples:
      | state       |
      | received    |
      | scanning    |
      | quarantined |
      | scan_failed |

  Scenario: An erased document is gone, with nothing recorded
    Given a document in state "erased" with id "0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1b06"
    And I am logged in as an administrator
    When I send a "GET" request to "/documents/0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1b06/content"
    Then the response status code should be 410
    And the JSON node "type" should be equal to "gone"
    And I execute the SQL query "SELECT id FROM audit_log WHERE action = 'DOCUMENT_READ' AND resource_id = '0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1b06'"
    And there should have 0 records in SQL result

  Scenario: A tampered original fails closed before any byte and is not recorded as read
    Given a ready document with id "0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1b07" holding "the original byte"
    And the sealed object of document "0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1b07" is altered
    And I am logged in as an administrator
    When I send a "GET" request to "/documents/0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1b07/content"
    Then the response status code should be 500
    And the JSON node "type" should be equal to "document-integrity-failure"
    And I execute the SQL query "SELECT id FROM audit_log WHERE action = 'DOCUMENT_READ' AND resource_id = '0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1b07'"
    And there should have 0 records in SQL result
