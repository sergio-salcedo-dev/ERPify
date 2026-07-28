Feature: Restrict the bank routes to the bank permission
  As a backoffice operator
  In order to keep bank data governed by role
  I need every bank route closed to anyone not granted the matching bank permission, with the distinction
  between "log in" (401) and "you may not" (403) preserved

  # The Bank controllers carry #[IsGranted(BankPermission::READ|WRITE|DELETE)], resolved by the custom
  # PermissionVoter over the StaticAuthorizationPolicy. An anonymous caller is stopped by the default-deny
  # firewall (401) before the permission gate; an authenticated caller not granted the permission reaches the
  # gate and is refused (403). read/write/delete are auto-granted by the role tiers, so Bank adds no policy
  # row. mallory (role-less) is denied every route; a VIEWER (read only) and an EDITOR (read+write, no delete)
  # pin that each route demands its exact permission — a write route weakened to bank.read would let the VIEWER
  # through and a delete route weakened to bank.write would let the EDITOR through, so those refusals must stay
  # 403. Granted 2xx write/delete paths run as MANAGER in the create/update/delete features. The permission gate
  # dispatches ahead of payload mapping — #[IsGranted] rides ControllerAttributesListener at -10000 on
  # kernel.controller_arguments, RequestPayloadValueResolver maps at -10100 — so the permission verdict wins
  # even over a body that would fail validation; the invalid-body scenario pins that ordering.
  Background:
    Given I add "Accept" header equal to "application/json"

  @anonymous
  Scenario: An unauthenticated read of the bank collection is a 401, not a 403
    When I send a "GET" request to "/backoffice/banks"
    Then the response status code should be 401
    And the header "Content-Type" should contain "application/problem+json"
    And the JSON node "type" should be equal to "unauthenticated"

  @anonymous
  Scenario: An unauthenticated create is a 401
    When I send a POST request to "/backoffice/banks" with body:
    """
    {
      "name": "Blocked Bank",
      "shortName": "BLK"
    }
    """
    Then the response status code should be 401
    And the JSON node "type" should be equal to "unauthenticated"

  @anonymous
  Scenario: An unauthenticated update is a 401
    When I send a PUT request to "/backoffice/banks/0190a001-0000-7000-8000-000000000001" with body:
    """
    {
      "name": "Blocked Update",
      "shortName": "BLU"
    }
    """
    Then the response status code should be 401
    And the JSON node "type" should be equal to "unauthenticated"

  @anonymous
  Scenario: An unauthenticated delete is a 401
    When I send a "DELETE" request to "/backoffice/banks/0190a001-0000-7000-8000-000000000001"
    Then the response status code should be 401
    And the JSON node "type" should be equal to "unauthenticated"

  @anonymous
  Scenario: An unauthenticated realtime-authorize request is a 401
    When I send a "GET" request to "/backoffice/banks/realtime/authorize"
    Then the response status code should be 401
    And the JSON node "type" should be equal to "unauthenticated"

  Scenario: A role-less authenticated user is refused the bank collection with 403
    Given I am logged in as a user without the audit-reader role
    When I send a "GET" request to "/backoffice/banks"
    Then the response status code should be 403
    And the header "Content-Type" should contain "application/problem+json"
    And the JSON node "type" should be equal to "forbidden"

  # The read side maps its query string with #[MapQueryString], the same resolver the write bodies go
  # through, so it needs its own canary: an invalid query from an unauthorized caller must answer 403, not
  # the 422 that would prove mapping ran ahead of the permission gate.
  Scenario: A role-less authenticated user sending an invalid query is still refused with 403
    Given I am logged in as a user without the audit-reader role
    When I send a "GET" request to "/backoffice/banks?paginationMode=nonsense&limit=-5"
    Then the response status code should be 403
    And the JSON node "type" should be equal to "forbidden"
    And the response should not contain "violations"

  # The subscriber cookie is minted behind bank.read — the same permission that gates the data the stream
  # refreshes — so a caller refused here is equally refused the collection. Refusing with 403 rather than a
  # cookie-less 204 keeps the denial auditable. The absent Set-Cookie is the load-bearing half: a refusal
  # that still minted a cookie would hand the hub a subscription the permission gate meant to deny.
  Scenario: A role-less authenticated user is refused a realtime-authorize request with 403
    Given I am logged in as a user without the audit-reader role
    When I send a "GET" request to "/backoffice/banks/realtime/authorize"
    Then the response status code should be 403
    And the header "Content-Type" should contain "application/problem+json"
    And the header "Set-Cookie" should not exist
    And the JSON node "type" should be equal to "forbidden"

  Scenario: A role-less authenticated user is refused a create with 403 — the permission gate, not validation
    Given I am logged in as a user without the audit-reader role
    When I send a POST request to "/backoffice/banks" with body:
    """
    {
      "name": "Access Denied Bank",
      "shortName": "ADB"
    }
    """
    Then the response status code should be 403
    And the JSON node "type" should be equal to "forbidden"

  Scenario: A role-less authenticated user is refused an update with 403 — the permission gate, not validation
    Given I am logged in as a user without the audit-reader role
    When I send a PUT request to "/backoffice/banks/0190a001-0000-7000-8000-000000000001" with body:
    """
    {
      "name": "Access Denied Update",
      "shortName": "ADU"
    }
    """
    Then the response status code should be 403
    And the JSON node "type" should be equal to "forbidden"

  Scenario: A role-less authenticated user is refused a delete with 403
    Given I am logged in as a user without the audit-reader role
    When I send a "DELETE" request to "/backoffice/banks/0190a001-0000-7000-8000-000000000001"
    Then the response status code should be 403
    And the JSON node "type" should be equal to "forbidden"

  Scenario: A viewer reads the bank collection — read is granted at the VIEWER tier
    Given I am logged in as a viewer
    When I send a "GET" request to "/backoffice/banks"
    Then the response status code should be 200

  Scenario: A viewer is refused a create with 403 — write is above the read tier
    Given I am logged in as a viewer
    When I send a POST request to "/backoffice/banks" with body:
    """
    {
      "name": "Viewer Denied Bank",
      "shortName": "VDB"
    }
    """
    Then the response status code should be 403
    And the JSON node "type" should be equal to "forbidden"

  # The one refusal that deliberately sends an INVALID body: it goes red with a 422 if payload mapping ever
  # runs ahead of the permission gate, which would hand an unauthorized caller the validation verdict.
  Scenario: A viewer sending an invalid body is still refused with 403 — authorization precedes validation
    Given I am logged in as a viewer
    When I send a POST request to "/backoffice/banks" with body:
    """
    {
      "name": ""
    }
    """
    Then the response status code should be 403
    And the JSON node "type" should be equal to "forbidden"
    And the response should not contain "violations"

  Scenario: A viewer is refused an update with 403 — write is above the read tier
    Given I am logged in as a viewer
    When I send a PUT request to "/backoffice/banks/0190a001-0000-7000-8000-000000000001" with body:
    """
    {
      "name": "Viewer Denied Update",
      "shortName": "VDU"
    }
    """
    Then the response status code should be 403
    And the JSON node "type" should be equal to "forbidden"

  Scenario: An editor is refused a delete with 403 — delete is above the write tier
    Given I am logged in as an editor
    When I send a "DELETE" request to "/backoffice/banks/0190a001-0000-7000-8000-000000000001"
    Then the response status code should be 403
    And the JSON node "type" should be equal to "forbidden"

  Scenario: A granted manager reads the bank collection
    When I send a "GET" request to "/backoffice/banks"
    Then the response status code should be 200
