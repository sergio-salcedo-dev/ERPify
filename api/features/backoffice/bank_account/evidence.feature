Feature: Attach evidence documents to a bank account
  As an administrator who must prove who owns a bank account
  In order to keep the bank's ownership certificate beside the account it proves
  I need to attach a document already filed through the Documents upload, list the account's evidence, and be
  refused removing an account whose evidence is still live

  # Both routes are ADMIN-only (`bankAccount.attachEvidence`, `bankAccount.readEvidence`). The bytes travel through
  # the Documents upload first; these routes carry ids. The kind fixes the retention terms the document must have been
  # filed under (an ownership certificate: contract-execution under legitimate-interests, no review date), checked on
  # the server through the seam Documents publishes. The 410 of the erasure-ledger override is held by the PHPUnit
  # tests; the listing's constant query cost is the last pair of scenarios below.

  # The Documents tables are not part of the per-feature fixture backup, so each scenario starts them empty.
  Background:
    Given I execute the SQL query "TRUNCATE bank_account_evidence, upload_intent, document_custody, document, document_series"
    And I add "Accept" header equal to "application/json"
    And I add "Content-Type" header equal to "application/json"

  Scenario: An administrator attaches an ownership certificate and lists it with its name
    Given a document with id "0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1e01" named "ownership-certificate.pdf" in state "ready" created 100 seconds ago
    And I am logged in as an administrator
    When I send a "POST" request to "/backoffice/bank-accounts/33333333-3333-7000-8000-000000000002/evidence" with body:
    """
    {"documentId": "0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1e01", "kind": "ownership-certificate"}
    """
    Then the response status code should be 201
    And the header "Cache-Control" should contain "no-store"
    And the JSON node "data.id" should be a valid UUID
    And the JSON node "data.documentId" should be equal to "0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1e01"
    And the JSON node "data.kind" should be equal to "ownership-certificate"
    And the JSON node "data.documentState" should be equal to "ready"
    And the JSON node "data.documentName" should be equal to "ownership-certificate.pdf"
    And the JSON node "data.documentSizeBytes" should be equal to the number 5
    And the JSON node "data.attachedBy" should not exist
    And I execute the SQL query "SELECT action FROM audit_log WHERE resource_type = 'BankAccountEvidence' AND action = 'BANK_ACCOUNT_EVIDENCE_CREATED'"
    And there should have 1 records in SQL result
    And I send a "GET" request to "/backoffice/bank-accounts/33333333-3333-7000-8000-000000000002/evidence"
    And the response status code should be 200
    And the header "Cache-Control" should contain "no-store"
    And the JSON node "data" should have 1 element
    And the JSON node "data[0].documentId" should be equal to "0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1e01"
    And the JSON node "data[0].documentName" should be equal to "ownership-certificate.pdf"

  Scenario: A document filed under other retention terms is refused with the terms its kind requires
    Given a document with id "0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1e02" named "invoice.pdf" in state "ready" kept for "tax-and-accounting" under "legal-obligation"
    And I am logged in as an administrator
    When I send a "POST" request to "/backoffice/bank-accounts/33333333-3333-7000-8000-000000000002/evidence" with body:
    """
    {"documentId": "0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1e02", "kind": "ownership-certificate"}
    """
    Then the response status code should be 422
    And the header "Content-Type" should be equal to "application/problem+json"
    And the JSON node "type" should be equal to "bank-account-evidence-retention-mismatch"
    And the JSON node "retentionReason" should be equal to "contract-execution"
    And the JSON node "legalBasis" should be equal to "legitimate-interests"
    And the response should not contain "tax-and-accounting"
    And I send a "GET" request to "/backoffice/bank-accounts/33333333-3333-7000-8000-000000000002/evidence"
    And the JSON node "data" should have 0 elements

  Scenario Outline: A document that cannot serve as evidence is refused alike whatever the cause
    Given a document with id "0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1e03" named "gone.pdf" in state "<state>" created 100 seconds ago
    And I am logged in as an administrator
    When I send a "POST" request to "/backoffice/bank-accounts/33333333-3333-7000-8000-000000000002/evidence" with body:
    """
    {"documentId": "<documentId>", "kind": "ownership-certificate"}
    """
    Then the response status code should be 422
    And the JSON node "type" should be equal to "bank-account-evidence-unavailable"

    Examples:
      | state       | documentId                           |
      | erased      | 0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1e03 |
      | quarantined | 0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1e03 |
      | ready       | 0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1eff |

  Scenario: The same document is attached to an account once
    Given a document with id "0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1e04" named "certificate.pdf" in state "ready" created 100 seconds ago
    And I am logged in as an administrator
    And I send a "POST" request to "/backoffice/bank-accounts/33333333-3333-7000-8000-000000000002/evidence" with body:
    """
    {"documentId": "0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1e04", "kind": "ownership-certificate"}
    """
    And the response status code should be 201
    When I send a "POST" request to "/backoffice/bank-accounts/33333333-3333-7000-8000-000000000002/evidence" with body:
    """
    {"documentId": "0190E9C2-7B5A-7D40-9C8F-2F9B5D3E1E04", "kind": "ownership-certificate"}
    """
    Then the response status code should be 409
    And the JSON node "type" should be equal to "bank-account-evidence-already-attached"

  Scenario Outline: A malformed or incomplete attach is refused before any document is read
    Given I am logged in as an administrator
    When I send a "POST" request to "/backoffice/bank-accounts/<accountId>/evidence" with body:
    """
    <body>
    """
    Then the response status code should be <status>
    And the JSON node "type" should be equal to "<type>"

    Examples:
      | accountId                            | body                                                                              | status | type                   |
      | not-a-uuid                           | {"documentId": "0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1e05", "kind": "ownership-certificate"} | 400    | invalid-uuid           |
      | 2e6d865c-17b0-476a-85f2-037bf6d3b3dc | {"documentId": "0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1e05", "kind": "ownership-certificate"} | 404    | bank-account-not-found |
      | 33333333-3333-7000-8000-000000000002 | {"documentId": "0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1e05"}                                  | 422    | validation-failed      |
      | 33333333-3333-7000-8000-000000000002 | {"documentId": "nope", "kind": "ownership-certificate"}                                 | 422    | validation-failed      |

  Scenario: An editor, who may file a document, may neither attach it nor read an account's evidence
    Given a document with id "0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1e06" named "secret-certificate.pdf" in state "ready" created 100 seconds ago
    And I am logged in as an editor
    When I send a "POST" request to "/backoffice/bank-accounts/33333333-3333-7000-8000-000000000002/evidence" with body:
    """
    {"documentId": "0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1e06", "kind": "ownership-certificate"}
    """
    Then the response status code should be 403
    And I send a "GET" request to "/backoffice/bank-accounts/33333333-3333-7000-8000-000000000002/evidence"
    And the response status code should be 403
    And the response should not contain "secret-certificate.pdf"

  Scenario: A closed account is not deleted while its evidence is live, and is once every document is erased
    Given I execute the SQL query "INSERT INTO bank_account (id, bank_id, holder_name, iban, bic, alias, currency, status, created_at, updated_at) VALUES ('acc1de00-0000-7000-8000-0000000000e1', '11111111-1111-7000-8000-000000000003', 'Evidence Holder', 'FR7630006000011234567890190', NULL, NULL, 'EUR', 'CLOSED', NOW(), NOW())"
    And a document with id "0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1e07" named "kept-certificate.pdf" in state "ready" created 100 seconds ago
    And I am logged in as an administrator
    And I send a "POST" request to "/backoffice/bank-accounts/acc1de00-0000-7000-8000-0000000000e1/evidence" with body:
    """
    {"documentId": "0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1e07", "kind": "ownership-certificate"}
    """
    And the response status code should be 201
    When I send a "DELETE" request to "/backoffice/bank-accounts/acc1de00-0000-7000-8000-0000000000e1"
    Then the response status code should be 409
    And the JSON node "type" should be equal to "bank-account-has-live-evidence"
    And the JSON node "liveDocuments" should be equal to the number 1
    And the response should not contain "kept-certificate.pdf"
    And there should have 1 "Erpify\Backoffice\BankAccount\Domain\Entity\BankAccount" entities found by "id=acc1de00-0000-7000-8000-0000000000e1"
    And I execute the SQL query "UPDATE document SET state = 'erased', filename_ciphertext = NULL WHERE id = '0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1e07'"
    And I send a "GET" request to "/backoffice/bank-accounts/acc1de00-0000-7000-8000-0000000000e1/evidence"
    And the JSON node "data[0].documentState" should be equal to "erased"
    And the JSON node "data[0].documentName" should be null
    And I send a "DELETE" request to "/backoffice/bank-accounts/acc1de00-0000-7000-8000-0000000000e1"
    And the response status code should be 204
    And the "Erpify\Backoffice\BankAccount\Domain\Entity\BankAccount" entity found by "id=acc1de00-0000-7000-8000-0000000000e1" does not exist
    And there should have 0 "Erpify\Backoffice\BankAccount\Domain\Entity\BankAccountEvidence" entities found by "bankAccountId=acc1de00-0000-7000-8000-0000000000e1"
    And I execute the SQL query "SELECT action FROM audit_log WHERE resource_type = 'BankAccountEvidence' AND action = 'BANK_ACCOUNT_EVIDENCE_DELETED'"
    And there should have 1 records in SQL result

  # One keystore query for the whole list (DocumentNameOpener) and one statement for the documents' rows, whatever the
  # account holds: each pair asserts ONE number, so a query issued per link makes the larger list cost more.
  Scenario: The evidence list of one document
    Given a document with id "0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1f01" named "one.pdf" in state "ready" created 100 seconds ago
    And I execute the SQL query "INSERT INTO bank_account_evidence (id, bank_account_id, document_id, kind, attached_at) VALUES ('0190e9c2-7b5a-7d40-9c8f-2f9b5d3e2f01', '33333333-3333-7000-8000-000000000002', '0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1f01', 'ownership-certificate', NOW())" on connection "seed"
    And I am logged in as an administrator
    When I send a "GET" request to "/backoffice/bank-accounts/33333333-3333-7000-8000-000000000002/evidence"
    Then the response status code should be 200
    And the JSON node "data" should have 1 element
    And 6 requests got executed only for doctrine connection "default"

  Scenario: The evidence list of four documents costs the same
    Given a document with id "0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1f11" named "doc-1.pdf" in state "ready" created 400 seconds ago
    And a document with id "0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1f12" named "doc-2.pdf" in state "ready" created 300 seconds ago
    And a document with id "0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1f13" named "doc-3.pdf" in state "ready" created 200 seconds ago
    And a document with id "0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1f14" named "doc-4.pdf" in state "ready" created 100 seconds ago
    And I execute the SQL query "INSERT INTO bank_account_evidence (id, bank_account_id, document_id, kind, attached_at) VALUES ('0190e9c2-7b5a-7d40-9c8f-2f9b5d3e2f11', '33333333-3333-7000-8000-000000000002', '0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1f11', 'ownership-certificate', NOW())" on connection "seed"
    And I execute the SQL query "INSERT INTO bank_account_evidence (id, bank_account_id, document_id, kind, attached_at) VALUES ('0190e9c2-7b5a-7d40-9c8f-2f9b5d3e2f12', '33333333-3333-7000-8000-000000000002', '0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1f12', 'ownership-certificate', NOW())" on connection "seed"
    And I execute the SQL query "INSERT INTO bank_account_evidence (id, bank_account_id, document_id, kind, attached_at) VALUES ('0190e9c2-7b5a-7d40-9c8f-2f9b5d3e2f13', '33333333-3333-7000-8000-000000000002', '0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1f13', 'ownership-certificate', NOW())" on connection "seed"
    And I execute the SQL query "INSERT INTO bank_account_evidence (id, bank_account_id, document_id, kind, attached_at) VALUES ('0190e9c2-7b5a-7d40-9c8f-2f9b5d3e2f14', '33333333-3333-7000-8000-000000000002', '0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1f14', 'ownership-certificate', NOW())" on connection "seed"
    And I am logged in as an administrator
    When I send a "GET" request to "/backoffice/bank-accounts/33333333-3333-7000-8000-000000000002/evidence"
    Then the response status code should be 200
    And the JSON node "data" should have 4 elements
    And 6 requests got executed only for doctrine connection "default"
