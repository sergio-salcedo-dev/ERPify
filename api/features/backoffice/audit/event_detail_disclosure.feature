Feature: The audit event detail serves a change diff only for the fields it can classify
  As a security officer
  In order to read the write trail without it outliving the erasure of the people it names
  I need the detail route to withhold any field of a change diff that is not a sealed value or a classified clear field

  # Nothing on the erasure path rewrites `audit_log.metadata`, so what the route serves in clear outlives the person it
  # names. A row inserted straight into the table — or written before sealing existed — never passed through the
  # sealer, which is the case these scenarios plant: the route must not take the row's level as proof that its values
  # are safe. Alice, the default session, holds auditTrail.read.
  Background:
    Given I add "Accept" header equal to "application/json"

  Scenario: A personal field that was never sealed is withheld, and a classified field is served
    Given I execute the SQL query "INSERT INTO audit_log (id, level, action, actor_type, actor_id, correlation_id, resource_type, resource_id, metadata, actor_erased, resource_erased, occurred_on) VALUES ('0190a003-0000-7000-8000-000000000001', 'change', 'BANK_ACCOUNT_UPDATED', 'system', NULL, '0190a003-0000-7000-8000-0000000000c1', 'BankAccount', '0190a003-0000-7000-8000-0000000000b1', jsonb_build_object('operation', 'UPDATED', 'changes', jsonb_build_object('iban', jsonb_build_object('old', 'ES9121000418450200051332', 'new', 'ES7921000813610123456789'), 'bic', jsonb_build_object('old', 'CAIXESBB', 'new', 'BSCHESMM'))), false, false, '2026-03-01 12:00:00+00')" on connection "seed"
    When I send a "GET" request to "/backoffice/audit/events/0190a003-0000-7000-8000-000000000001"
    Then the response status code should be 200
    And the JSON node "data.metadata.changes.iban.old" should be equal to "[unsealed value withheld]"
    And the JSON node "data.metadata.changes.iban.new" should be equal to "[unsealed value withheld]"
    And the JSON node "data.metadata.changes.bic.old" should be equal to "CAIXESBB"
    And the JSON node "data.metadata.changes.bic.new" should be equal to "BSCHESMM"

  Scenario: A field the registry does not know for the resource type is withheld whatever it holds
    Given I execute the SQL query "INSERT INTO audit_log (id, level, action, actor_type, actor_id, correlation_id, resource_type, resource_id, metadata, actor_erased, resource_erased, occurred_on) VALUES ('0190a003-0000-7000-8000-000000000002', 'change', 'BANK_UPDATED', 'system', NULL, '0190a003-0000-7000-8000-0000000000c2', 'Bank', '0190a003-0000-7000-8000-0000000000b2', jsonb_build_object('operation', 'UPDATED', 'changes', jsonb_build_object('contactEmail', jsonb_build_object('old', 'jane.doe@example.test', 'new', 'jane.roe@example.test'), 'name', jsonb_build_object('old', 'Acme', 'new', 'Acme Bank'))), false, false, '2026-03-01 12:01:00+00')" on connection "seed"
    When I send a "GET" request to "/backoffice/audit/events/0190a003-0000-7000-8000-000000000002"
    Then the response status code should be 200
    And the JSON node "data.metadata.changes.contactEmail.old" should be equal to "[unsealed value withheld]"
    And the JSON node "data.metadata.changes.contactEmail.new" should be equal to "[unsealed value withheld]"
    And the JSON node "data.metadata.changes.name.new" should be equal to "Acme Bank"
