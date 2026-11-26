Feature: The custody trail and a series cost the same number of queries whatever they hold
  As a maintainer
  In order to notice an N+1 (a lookup per custody row, a name opened per version) the day it is introduced
  I need each read to answer in a fixed number of queries for a short trail and for a long one

  # Each pair of scenarios asserts ONE number, so a query issued per row makes the larger read cost more than the
  # smaller one. The number includes the audit row the read writes about itself.
  Background:
    Given I execute the SQL query "TRUNCATE upload_intent, document_custody, document, document_series"
    And I add "Accept" header equal to "application/json"

  Scenario: A trail of two rows
    Given a document with id "0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1e01" named "short.pdf" in state "ready" created 100 seconds ago
    And the document "0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1e01" has 2 custody rows
    And I am logged in as an administrator
    When I send a "GET" request to "/documents/0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1e01/custody"
    Then the response status code should be 200
    And the JSON node "data.entries" should have 2 elements
    And 5 requests got executed only for doctrine connection "default"

  Scenario: A trail of twenty rows costs the same
    Given a document with id "0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1e11" named "long.pdf" in state "ready" created 100 seconds ago
    And the document "0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1e11" has 20 custody rows
    And I am logged in as an administrator
    When I send a "GET" request to "/documents/0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1e11/custody"
    Then the response status code should be 200
    And the JSON node "data.entries" should have 20 elements
    And 5 requests got executed only for doctrine connection "default"

  Scenario: A series of two versions
    Given a document with id "0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1e21" named "v1.pdf" in state "ready" created 200 seconds ago
    And a document with id "0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1e22" named "v2.pdf" in state "ready" created 100 seconds ago
    And I execute the SQL query "UPDATE document SET series_id = '0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1e21', predecessor_id = '0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1e21' WHERE id = '0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1e22'"
    And I am logged in as an administrator
    When I send a "GET" request to "/documents/series/0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1e21"
    Then the response status code should be 200
    And the JSON node "data.versions" should have 2 elements
    And 4 requests got executed only for doctrine connection "default"

  Scenario: A series of five versions costs the same
    Given a document with id "0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1e31" named "v1.pdf" in state "ready" created 500 seconds ago
    And a document with id "0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1e32" named "v2.pdf" in state "ready" created 400 seconds ago
    And a document with id "0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1e33" named "v3.pdf" in state "ready" created 300 seconds ago
    And a document with id "0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1e34" named "v4.pdf" in state "ready" created 200 seconds ago
    And a document with id "0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1e35" named "v5.pdf" in state "ready" created 100 seconds ago
    And I execute the SQL query "UPDATE document SET series_id = '0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1e31', predecessor_id = '0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1e31' WHERE id <> '0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1e31'"
    And I am logged in as an administrator
    When I send a "GET" request to "/documents/series/0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1e31"
    Then the response status code should be 200
    And the JSON node "data.versions" should have 5 elements
    And 4 requests got executed only for doctrine connection "default"
