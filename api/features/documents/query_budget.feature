Feature: The document listings cost the same number of queries whatever the page holds
  As a maintainer
  In order to notice an N+1 (a keystore read per name, a lookup per row) the day it is introduced
  I need each listing to answer in a fixed number of queries for one document and for several

  # ADR D10 promises one keystore query per page. Each pair of scenarios asserts ONE number, so a query issued per row
  # makes the larger page cost more than the single document.
  Background:
    Given I execute the SQL query "TRUNCATE upload_intent, document_custody, document, document_series"
    And I add "Accept" header equal to "application/json"

  Scenario: The listing of one document
    Given a document with id "0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1d01" named "one.pdf" in state "ready" created 100 seconds ago
    And I am logged in as an administrator
    When I send a "GET" request to "/documents"
    Then the response status code should be 200
    And the JSON node "data" should have 1 element
    And 4 requests got executed only for doctrine connection "default"

  Scenario: The listing of six documents costs the same
    Given a document with id "0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1d11" named "a.pdf" in state "ready" created 600 seconds ago
    And a document with id "0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1d12" named "b.pdf" in state "ready" created 500 seconds ago
    And a document with id "0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1d13" named "c.pdf" in state "quarantined" created 400 seconds ago
    And a document with id "0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1d14" named "d.pdf" in state "ready" created 300 seconds ago
    And a document with id "0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1d15" named "e.pdf" in state "scan_failed" created 200 seconds ago
    And a document with id "0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1d16" named "f.pdf" in state "ready" created 100 seconds ago
    And I am logged in as an administrator
    When I send a "GET" request to "/documents"
    Then the response status code should be 200
    And the JSON node "data" should have 6 elements
    And 4 requests got executed only for doctrine connection "default"
