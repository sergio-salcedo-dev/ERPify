Feature: Investigate the audit timeline
  As a security investigator
  In order to reconstruct an actor's journey
  I need a keyset-paginated, filterable timeline over the audit log

  # audit_log is a raw-DBAL append-only table with no Doctrine entity and no fixtures, so each
  # scenario seeds it directly. Four rows, one minute apart, so the default occurred_on DESC order is
  # the deterministic sequence LOGIN, VIEW, SEARCH, DENIED. The wire row is flat and scalar-only:
  # {id, occurredOn, level, action, actorType, actorId, correlationId, resourceType, resourceId,
  # actorErased}.
  Background:
    Given I execute the SQL query "TRUNCATE audit_log RESTART IDENTITY"
    And I execute the SQL query "INSERT INTO audit_log (id, level, action, actor_type, actor_id, correlation_id, resource_type, resource_id, metadata, ip, user_agent, actor_erased, occurred_on) VALUES ('0190a001-0000-7000-8000-000000000001','security','LOGIN','user','11111111-1111-7111-8111-111111111111','0190c000-0000-7000-8000-000000000000',NULL,NULL,'{}',NULL,NULL,false,'2026-03-01 12:00:00+00'), ('0190a001-0000-7000-8000-000000000002','activity','VIEW','user','11111111-1111-7111-8111-111111111111','0190c000-0000-7000-8000-000000000000',NULL,NULL,'{}',NULL,NULL,false,'2026-03-01 11:59:00+00'), ('0190a001-0000-7000-8000-000000000003','activity','SEARCH','anonymous',NULL,'0190c000-0000-7000-8000-000000000000',NULL,NULL,'{}',NULL,NULL,false,'2026-03-01 11:58:00+00'), ('0190a001-0000-7000-8000-000000000004','security','DENIED','anonymous',NULL,'0190c000-0000-7000-8000-000000000000',NULL,NULL,'{}',NULL,NULL,false,'2026-03-01 11:57:00+00')"

  Scenario: The timeline lists newest-first with the constant cursor-only envelope
    When I send a "GET" request to "/backoffice/audit/timeline"
    Then the response status code should be 200
    And the JSON node "data" should have 4 elements
    And the JSON nodes matching "data[*]" should have 10 children
    And the JSON nodes matching "data[*].id" should exist
    And the JSON nodes matching "data[*].occurredOn" should exist
    And the JSON nodes matching "data[*].level" should exist
    And the JSON nodes matching "data[*].action" should exist
    And the JSON nodes matching "data[*].actorType" should exist
    And the JSON nodes matching "data[*].correlationId" should exist
    And the JSON nodes matching "data[*].actorErased" should exist
    And the JSON node "data[0].action" should be equal to "LOGIN"
    And the JSON node "data[3].action" should be equal to "DENIED"
    And the JSON node "pagination" should have 4 elements
    And the JSON node "pagination.hasNext" should be false
    And the JSON node "pagination.hasPrev" should be false
    And the JSON node "pagination.count" should be null
    And the JSON node "pagination.links.next" should be null
    And the JSON node "pagination.links.prev" should be null

  Scenario: occurredOn is rendered at microsecond precision
    When I send a "GET" request to "/backoffice/audit/timeline"
    Then the response status code should be 200
    And the JSON node "data[0].occurredOn" should be equal to "2026-03-01T12:00:00.000000+00:00"

  Scenario: Following the next link advances the window and exposes a previous affordance
    Given I send a "GET" request to "/backoffice/audit/timeline?limit=2"
    And the JSON node "data[0].action" should be equal to "LOGIN"
    And the JSON node "data[1].action" should be equal to "VIEW"
    And the JSON node "pagination.hasNext" should be true
    And the JSON node "pagination.hasPrev" should be false
    When I follow the "pagination.links.next" link from the previous response
    Then the response status code should be 200
    And the JSON node "data[0].action" should be equal to "SEARCH"
    And the JSON node "data[1].action" should be equal to "DENIED"
    And the JSON node "pagination.hasNext" should be false
    And the JSON node "pagination.hasPrev" should be true

  Scenario: Filtering by actor id returns only that actor's entries
    When I send a "GET" request to "/backoffice/audit/timeline?filters[0][field]=actorId&filters[0][operator]=eq&filters[0][value]=11111111-1111-7111-8111-111111111111"
    Then the response status code should be 200
    And the JSON node "data" should have 2 elements
    And the JSON node "data[0].action" should be equal to "LOGIN"
    And the JSON node "data[1].action" should be equal to "VIEW"

  Scenario: Filtering by level matches the security entries
    When I send a "GET" request to "/backoffice/audit/timeline?filters[0][field]=level&filters[0][operator]=eq&filters[0][value]=security"
    Then the response status code should be 200
    And the JSON node "data" should have 2 elements
    And the JSON node "data[0].action" should be equal to "LOGIN"
    And the JSON node "data[1].action" should be equal to "DENIED"

  Scenario: Filtering action by substring matches case-insensitively
    When I send a "GET" request to "/backoffice/audit/timeline?filters[0][field]=action&filters[0][operator]=contains&filters[0][value]=log"
    Then the response status code should be 200
    And the JSON node "data" should have 1 elements
    And the JSON node "data[0].action" should be equal to "LOGIN"

  Scenario: A tampered cursor is rejected as 422 invalid-cursor
    When I send a "GET" request to "/backoffice/audit/timeline?after=not-a-real-cursor"
    Then the response status code should be 422
    And the header "Content-Type" should be equal to "application/problem+json"
    And the JSON node "type" should be equal to "invalid-cursor"
    And the JSON node "status" should be equal to the number 422

  Scenario: A filter on a field outside the allow-list returns 422 unknown-search-field
    When I send a "GET" request to "/backoffice/audit/timeline?filters[0][field]=metadata&filters[0][operator]=eq&filters[0][value]=x"
    Then the response status code should be 422
    And the header "Content-Type" should be equal to "application/problem+json"
    And the JSON node "type" should be equal to "unknown-search-field"
    And the JSON node "field" should be equal to "metadata"

  Scenario: A malformed uuid on the actorId filter returns 422 invalid-search-value
    When I send a "GET" request to "/backoffice/audit/timeline?filters[0][field]=actorId&filters[0][operator]=eq&filters[0][value]=not-a-uuid"
    Then the response status code should be 422
    And the header "Content-Type" should be equal to "application/problem+json"
    And the JSON node "type" should be equal to "invalid-search-value"
    And the JSON node "field" should be equal to "actorId"
