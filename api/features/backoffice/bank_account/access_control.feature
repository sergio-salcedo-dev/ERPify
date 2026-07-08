Feature: Restrict the bank-account routes to the bank-account permission
  As a backoffice operator
  In order to keep bank-account data governed by role
  I need every bank-account route — the flat collection and the nested per-bank route alike — closed to
  anyone not granted the matching bank-account permission, with "log in" (401) kept distinct from "you may
  not" (403)

  # The BankAccount controllers carry #[IsGranted(BankAccountPermission::READ|WRITE|DELETE|CHANGE_STATUS)],
  # resolved by the custom PermissionVoter over the StaticAuthorizationPolicy. An anonymous caller is
  # stopped by the default-deny firewall (401) before the permission gate; an authenticated caller not
  # granted the permission reaches the gate and is refused (403). read/write/delete tier as usual, so the
  # same bankAccount.read guards both the collection and the nested /banks/{id}/accounts — a resource is
  # governed, not a route. changeStatus is a domain operation, not a tier verb: an EDITOR that holds the
  # write tier is still refused it, pinning that PATCH /status demands the explicit grant, not write. A
  # weakened annotation (a write route dropped to read, or PATCH /status dropped to write) would let a
  # lower tier through, so those refusals must stay 403. Write and status refusals send a valid body so the
  # gate — which runs after payload mapping — is what answers, not a 422. The granted 2xx status path runs
  # as MANAGER in status.feature.
  Background:
    Given I add "Content-Type" header equal to "application/json"
    And I add "Accept" header equal to "application/json"

  @anonymous
  Scenario: An unauthenticated read of the account collection is a 401, not a 403
    When I send a "GET" request to "/backoffice/bank-accounts"
    Then the response status code should be 401
    And the header "Content-Type" should contain "application/problem+json"
    And the JSON node "type" should be equal to "unauthenticated"

  @anonymous
  Scenario: An unauthenticated read of a bank's nested accounts is a 401
    When I send a "GET" request to "/backoffice/banks/11111111-1111-7000-8000-000000000001/accounts"
    Then the response status code should be 401
    And the JSON node "type" should be equal to "unauthenticated"

  @anonymous
  Scenario: An unauthenticated read of a single account is a 401
    When I send a "GET" request to "/backoffice/bank-accounts/33333333-3333-7000-8000-000000000001"
    Then the response status code should be 401
    And the JSON node "type" should be equal to "unauthenticated"

  @anonymous
  Scenario: An unauthenticated realtime-authorize request is a 401
    When I send a "GET" request to "/backoffice/bank-accounts/realtime/authorize"
    Then the response status code should be 401
    And the JSON node "type" should be equal to "unauthenticated"

  @anonymous
  Scenario: An unauthenticated create is a 401
    When I send a POST request to "/backoffice/bank-accounts" with body:
    """
    {
      "bankId": "11111111-1111-7000-8000-000000000003",
      "holderName": "Blocked Holder",
      "iban": "GB82WEST12345698765432",
      "currency": "EUR"
    }
    """
    Then the response status code should be 401
    And the JSON node "type" should be equal to "unauthenticated"

  @anonymous
  Scenario: An unauthenticated update is a 401
    When I send a PUT request to "/backoffice/bank-accounts/33333333-3333-7000-8000-000000000001" with body:
    """
    {
      "holderName": "Blocked Holder",
      "iban": "ES9121000418450200051332",
      "currency": "EUR"
    }
    """
    Then the response status code should be 401
    And the JSON node "type" should be equal to "unauthenticated"

  @anonymous
  Scenario: An unauthenticated delete is a 401
    When I send a "DELETE" request to "/backoffice/bank-accounts/33333333-3333-7000-8000-000000000001"
    Then the response status code should be 401
    And the JSON node "type" should be equal to "unauthenticated"

  @anonymous
  Scenario: An unauthenticated status change is a 401
    When I send a PATCH request to "/backoffice/bank-accounts/33333333-3333-7000-8000-000000000001/status" with body:
    """
    {
      "status": "CLOSED"
    }
    """
    Then the response status code should be 401
    And the JSON node "type" should be equal to "unauthenticated"

  Scenario: A role-less authenticated user is refused the account collection with 403
    Given I am logged in as a user without the audit-reader role
    When I send a "GET" request to "/backoffice/bank-accounts"
    Then the response status code should be 403
    And the header "Content-Type" should contain "application/problem+json"
    And the JSON node "type" should be equal to "forbidden"

  Scenario: A role-less authenticated user is refused a bank's nested accounts with 403
    Given I am logged in as a user without the audit-reader role
    When I send a "GET" request to "/backoffice/banks/11111111-1111-7000-8000-000000000001/accounts"
    Then the response status code should be 403
    And the JSON node "type" should be equal to "forbidden"

  Scenario: A role-less authenticated user is refused a single account with 403
    Given I am logged in as a user without the audit-reader role
    When I send a "GET" request to "/backoffice/bank-accounts/33333333-3333-7000-8000-000000000001"
    Then the response status code should be 403
    And the JSON node "type" should be equal to "forbidden"

  Scenario: A role-less authenticated user is refused a realtime-authorize request with 403
    Given I am logged in as a user without the audit-reader role
    When I send a "GET" request to "/backoffice/bank-accounts/realtime/authorize"
    Then the response status code should be 403
    And the JSON node "type" should be equal to "forbidden"

  Scenario: A role-less authenticated user is refused a create with 403 — the permission gate, not validation
    Given I am logged in as a user without the audit-reader role
    When I send a POST request to "/backoffice/bank-accounts" with body:
    """
    {
      "bankId": "11111111-1111-7000-8000-000000000003",
      "holderName": "Access Denied Holder",
      "iban": "GB82WEST12345698765432",
      "currency": "EUR"
    }
    """
    Then the response status code should be 403
    And the JSON node "type" should be equal to "forbidden"

  Scenario: A role-less authenticated user is refused an update with 403 — the permission gate, not validation
    Given I am logged in as a user without the audit-reader role
    When I send a PUT request to "/backoffice/bank-accounts/33333333-3333-7000-8000-000000000001" with body:
    """
    {
      "holderName": "Access Denied Holder",
      "iban": "ES9121000418450200051332",
      "currency": "EUR"
    }
    """
    Then the response status code should be 403
    And the JSON node "type" should be equal to "forbidden"

  Scenario: A role-less authenticated user is refused a delete with 403
    Given I am logged in as a user without the audit-reader role
    When I send a "DELETE" request to "/backoffice/bank-accounts/33333333-3333-7000-8000-000000000001"
    Then the response status code should be 403
    And the JSON node "type" should be equal to "forbidden"

  Scenario: A role-less authenticated user is refused a status change with 403 — the permission gate, not validation
    Given I am logged in as a user without the audit-reader role
    When I send a PATCH request to "/backoffice/bank-accounts/33333333-3333-7000-8000-000000000001/status" with body:
    """
    {
      "status": "CLOSED"
    }
    """
    Then the response status code should be 403
    And the JSON node "type" should be equal to "forbidden"

  Scenario: A viewer reads the account collection — read is granted at the VIEWER tier
    Given I am logged in as a viewer
    When I send a "GET" request to "/backoffice/bank-accounts"
    Then the response status code should be 200

  Scenario: A viewer reads a bank's nested accounts — the same read permission guards the nested route
    Given I am logged in as a viewer
    When I send a "GET" request to "/backoffice/banks/11111111-1111-7000-8000-000000000001/accounts"
    Then the response status code should be 200

  Scenario: A viewer is refused a create with 403 — write is above the read tier
    Given I am logged in as a viewer
    When I send a POST request to "/backoffice/bank-accounts" with body:
    """
    {
      "bankId": "11111111-1111-7000-8000-000000000003",
      "holderName": "Viewer Denied Holder",
      "iban": "GB82WEST12345698765432",
      "currency": "EUR"
    }
    """
    Then the response status code should be 403
    And the JSON node "type" should be equal to "forbidden"

  Scenario: A viewer is refused an update with 403 — write is above the read tier
    Given I am logged in as a viewer
    When I send a PUT request to "/backoffice/bank-accounts/33333333-3333-7000-8000-000000000001" with body:
    """
    {
      "holderName": "Viewer Denied Holder",
      "iban": "ES9121000418450200051332",
      "currency": "EUR"
    }
    """
    Then the response status code should be 403
    And the JSON node "type" should be equal to "forbidden"

  Scenario: A viewer is refused a delete with 403 — delete is above the read tier
    Given I am logged in as a viewer
    When I send a "DELETE" request to "/backoffice/bank-accounts/33333333-3333-7000-8000-000000000001"
    Then the response status code should be 403
    And the JSON node "type" should be equal to "forbidden"

  Scenario: A viewer is refused a status change with 403 — changeStatus is not a tier verb
    Given I am logged in as a viewer
    When I send a PATCH request to "/backoffice/bank-accounts/33333333-3333-7000-8000-000000000001/status" with body:
    """
    {
      "status": "CLOSED"
    }
    """
    Then the response status code should be 403
    And the JSON node "type" should be equal to "forbidden"

  Scenario: An editor is refused a delete with 403 — delete is above the write tier
    Given I am logged in as an editor
    When I send a "DELETE" request to "/backoffice/bank-accounts/33333333-3333-7000-8000-000000000001"
    Then the response status code should be 403
    And the JSON node "type" should be equal to "forbidden"

  Scenario: An editor is refused a status change with 403 — changeStatus needs the explicit grant, not the write tier
    Given I am logged in as an editor
    When I send a PATCH request to "/backoffice/bank-accounts/33333333-3333-7000-8000-000000000001/status" with body:
    """
    {
      "status": "CLOSED"
    }
    """
    Then the response status code should be 403
    And the JSON node "type" should be equal to "forbidden"

  Scenario: A granted manager reads the account collection
    When I send a "GET" request to "/backoffice/bank-accounts"
    Then the response status code should be 200
