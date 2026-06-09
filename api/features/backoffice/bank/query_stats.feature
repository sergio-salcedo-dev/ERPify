Feature: Doctrine query stats on bank CRUD
  As a developer
  In order to keep an eye on database access in the bank module
  I need to assert that bank endpoints touch only the default connection
  and emit the expected statement types

  Scenario: GET single bank issues a SELECT only on the default connection
    When I send a "GET" request to "/backoffice/banks/11111111-1111-7000-8000-000000000001"
    Then the response status code should be 200
    And a request contains "SELECT" for doctrine connection "default"
    And 1 request got executed only for doctrine connection "default"

  Scenario: Listing banks issues a SELECT on the default connection
    When I send a "GET" request to "/backoffice/banks"
    Then the response status code should be 200
    And a request contains "FROM bank" across all doctrine connections
    And 2 requests got executed only for doctrine connection "default"

  Scenario: Listing banks with a name filter still hits a single connection
    When I send a "GET" request to "/backoffice/banks?names[]=BBVA"
    Then the response status code should be 200
    And a request contains "SELECT" for doctrine connection "default"
    And 2 requests got executed only for doctrine connection "default"

  Scenario: GET for an unknown id still queries only the default connection
    When I send a "GET" request to "/backoffice/banks/2e6d865c-17b0-476a-85f2-037bf6d3b3dc"
    Then the response status code should be 404
    And a request contains "SELECT" across all doctrine connections
    And 1 request got executed only for doctrine connection "default"

  Scenario: Malformed id rejection emits no Doctrine queries
    When I send a "GET" request to "/backoffice/banks/invalidUuid"
    Then the response status code should be 400
    And 0 requests got executed across all doctrine connections

  Scenario: POST a new bank emits an INSERT on the default connection
    Given I add "Content-Type" header equal to "application/json"
    And I add "Accept" header equal to "application/json"
    When I send a POST request to "/backoffice/banks" with body:
    """
    {"name": "Doctrine Stats Bank", "shortName": "DSB"}
    """
    Then the response status code should be 201
    And a request contains "INSERT" for doctrine connection "default"
    And 6 requests got executed only for doctrine connection "default"
