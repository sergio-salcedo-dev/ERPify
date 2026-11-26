Feature: The audit timeline costs the same number of queries whatever the page size
  As a maintainer
  In order to notice an N+1 the day it is introduced
  I need the timeline to answer in a fixed number of queries for one row and for a full page

  # The two scenarios assert ONE number. A query issued per row would make the second larger than the first, so the
  # number is what the page size must not move. It is counted on the same connection the listings of banks and users
  # are counted on, and it includes the security row the read writes about itself.
  Background:
    Given I add "Accept" header equal to "application/json"
    And I execute the SQL query "TRUNCATE audit_log RESTART IDENTITY"
    And I execute the SQL query "INSERT INTO audit_log (id, level, action, actor_type, actor_id, correlation_id, resource_type, resource_id, metadata, ip, user_agent, actor_erased, resource_erased, occurred_on) SELECT gen_random_uuid(), 'activity', 'BANK_ACCOUNTS_VIEWED', 'user', '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b', gen_random_uuid(), 'Bank', gen_random_uuid(), '{}'::jsonb, '203.0.113.9', 'Behat', false, false, now() - (g * interval '1 minute') FROM generate_series(1, 30) AS g" on connection "seed"

  Scenario: A page of one row
    When I send a "GET" request to "/backoffice/audit/timeline?limit=1"
    Then the response status code should be 200
    And the JSON node "data" should have 1 elements
    And 2 requests got executed only for doctrine connection "default"

  Scenario: A page of twenty-five rows costs the same
    When I send a "GET" request to "/backoffice/audit/timeline?limit=25"
    Then the response status code should be 200
    And the JSON node "data" should have 25 elements
    And 2 requests got executed only for doctrine connection "default"
