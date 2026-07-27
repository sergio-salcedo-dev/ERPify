Feature: Restrict the audit trail read routes to the auditTrail.read permission
  As a security officer
  In order to satisfy restricted access to the audit log (ISO 27001 A.5.18)
  I need the two audit read routes closed to anyone not granted auditTrail.read, every refusal recorded, and
  the distinction between "log in" (401) and "you may not" (403) preserved

  # The timeline and event-detail controllers carry #[IsGranted('auditTrail.read')], resolved by the custom
  # PermissionVoter over the StaticAuthorizationPolicy. An anonymous caller is stopped by the default-deny
  # firewall (401) before the permission gate; an authenticated caller not granted the permission reaches the
  # gate and is refused (403). auditTrail opts out of tier auto-grant, so even a fully-tiered MANAGER (trent)
  # is denied — only AUDIT_READER and ADMIN are granted it. Alice (the default session) holds AUDIT_READER, so
  # the granted path is covered by timeline.feature; the denied users here are mallory (role-less) and trent.
  Background:
    Given I add "Accept" header equal to "application/json"

  @anonymous
  Scenario: An unauthenticated request to the audit timeline is a 401, not a 403
    When I send a "GET" request to "/backoffice/audit/timeline"
    Then the response status code should be 401
    And the header "Content-Type" should contain "application/problem+json"
    And the JSON node "type" should be equal to "unauthenticated"

  @anonymous
  Scenario: An unauthenticated request to the audit event detail is a 401, not a 403
    When I send a "GET" request to "/backoffice/audit/events/0190a001-0000-7000-8000-000000000001"
    Then the response status code should be 401
    And the JSON node "type" should be equal to "unauthenticated"

  Scenario: An authenticated user without the audit-reader role is refused the timeline with 403
    Given I am logged in as a user without the audit-reader role
    When I send a "GET" request to "/backoffice/audit/timeline"
    Then the response status code should be 403
    And the header "Content-Type" should contain "application/problem+json"
    And the JSON node "type" should be equal to "forbidden"

  Scenario: A generic-tier user is refused the timeline with 403 — the trail opts out of tier auto-grant
    Given I am logged in as a generic-tier user without the audit-reader role
    When I send a "GET" request to "/backoffice/audit/timeline"
    Then the response status code should be 403
    And the JSON node "type" should be equal to "forbidden"

  Scenario: A generic-tier user is refused the event detail with 403 — the trail opts out of tier auto-grant
    Given I am logged in as a generic-tier user without the audit-reader role
    When I send a "GET" request to "/backoffice/audit/events/0190a001-0000-7000-8000-000000000001"
    Then the response status code should be 403
    And the JSON node "type" should be equal to "forbidden"

  Scenario: An authenticated user without the audit-reader role is refused the event detail with 403
    Given I add "X-Correlation-Id" header equal to "0190a1de-0002-7abc-8def-001122334455"
    And I am logged in as a user without the audit-reader role
    When I send a "GET" request to "/backoffice/audit/events/0190a001-0000-7000-8000-000000000001"
    And I execute the SQL query "SELECT action, level, metadata FROM audit_log WHERE action = 'ACCESS_DENIED' AND correlation_id = '0190a1de-0002-7abc-8def-001122334455'"
    Then the response status code should be 403
    And the JSON node "type" should be equal to "forbidden"
    And the SQL result as JSON should be:
    """
    [
      {
        "action": "ACCESS_DENIED",
        "level": "security",
        "metadata": "{\"route\": \"backoffice_audit_event_detail\"}"
      }
    ]
    """

  Scenario: The refusal is recorded as one synchronous security ACCESS_DENIED row naming the denied route
    Given I add "X-Correlation-Id" header equal to "0190a1de-0001-7abc-8def-001122334455"
    And I am logged in as a user without the audit-reader role
    When I send a "GET" request to "/backoffice/audit/timeline"
    And I execute the SQL query "SELECT action, level, metadata FROM audit_log WHERE action = 'ACCESS_DENIED' AND correlation_id = '0190a1de-0001-7abc-8def-001122334455'"
    Then the response status code should be 403
    And the SQL result as JSON should be:
    """
    [
      {
        "action": "ACCESS_DENIED",
        "level": "security",
        "metadata": "{\"route\": \"backoffice_audit_timeline_search\"}"
      }
    ]
    """

  Scenario: An audit-reader can read an audit event by id
    Given I execute the SQL query "INSERT INTO audit_log (id, level, action, actor_type, actor_id, correlation_id, resource_type, resource_id, metadata, actor_erased, resource_erased, occurred_on) VALUES ('0190a001-0000-7000-8000-0000000000aa','change','BANK_UPDATED','user','11111111-1111-7111-8111-111111111111','0190c000-0000-7000-8000-000000000000','Bank','22222222-2222-7222-8222-222222222222','{}',false,false,'2026-03-01 12:00:00+00')"
    When I send a "GET" request to "/backoffice/audit/events/0190a001-0000-7000-8000-0000000000aa"
    Then the response status code should be 200
    And the JSON node "data.action" should be equal to "BANK_UPDATED"
    And the JSON node "data.actorType" should be equal to "user"
    And the JSON node "data.actorId" should be equal to "11111111-1111-7111-8111-111111111111"
