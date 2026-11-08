Feature: File an evidence-class original through a declare step and a raw-body PUT
  As a bounded context that must keep the original byte as proof
  In order to file a document whose bytes are never transformed
  I need to declare what I am about to send, then send it as the raw body of a PUT

  # The original filename travels percent-encoded in the PUT's single `filename` query parameter and is
  # sealed when the document is filed; the declare step never carries it. Every refusal below leaves no
  # document, and a refusal before the claim leaves the upload declared for a corrected retry.

  # The Documents tables are not part of the per-feature fixture backup, so each scenario starts them empty.
  Background:
    Given I execute the SQL query "TRUNCATE upload_intent, document_custody, document"
    And I add "Accept" header equal to "application/json"
    And I add "Content-Type" header equal to "application/json"

  Scenario: A declared upload is filed by its PUT and answers its digest and size
    Given I am logged in as an editor
    And I send a "POST" request to "/documents/uploads" with body:
    """
    {"size": 5, "retentionReason": "technical-file", "legalBasis": "contract"}
    """
    And the response status code should be 201
    And the JSON node "data.state" should be equal to "declared"
    When I add "Content-Type" header equal to "application/octet-stream"
    And I add "Content-Length" header equal to "5"
    And I send a "PUT" request to "/documents/uploads/{value}/content?filename=Acta%20de%20obra.pdf" using the JSON node "data.id" from the previous response with body:
    """
    hello
    """
    Then the response status code should be 201
    And the JSON node "data.state" should be equal to "received"
    And the JSON node "data.digest" should be equal to "2cf24dba5fb0a30e26e83b2ac5b9e29e1b161e5c1fa7425e73043362938b9824"
    And the JSON node "data.sizeBytes" should be equal to the number 5
    And I execute the SQL query "SELECT id FROM upload_intent WHERE state = 'committed' AND document_id IS NOT NULL AND row_to_json(upload_intent)::text NOT LIKE '%Acta%'"
    And there should have 1 records in SQL result
    And I execute the SQL query "SELECT id FROM document WHERE state = 'received' AND filename_ciphertext IS NOT NULL AND filename_ciphertext NOT LIKE '%Acta%'"
    And there should have 1 records in SQL result

  Scenario: A size of zero is refused at the declare step and creates nothing
    Given I am logged in as an editor
    When I send a "POST" request to "/documents/uploads" with body:
    """
    {"size": 0, "retentionReason": "technical-file", "legalBasis": "contract"}
    """
    Then the response status code should be 422
    And the JSON node "type" should be equal to "validation-failed"
    And I execute the SQL query "SELECT id FROM upload_intent"
    And there should have 0 records in SQL result

  Scenario: A replay answers the same upload and a different parameter under the same key conflicts
    Given I am logged in as an editor
    And I send a "POST" request to "/documents/uploads" with body:
    """
    {"size": 5, "sha256": "2cf24dba5fb0a30e26e83b2ac5b9e29e1b161e5c1fa7425e73043362938b9824", "idempotencyKey": "acceptance-replay", "retentionReason": "technical-file", "legalBasis": "contract"}
    """
    And the response status code should be 201
    And I send a "POST" request to "/documents/uploads" with body:
    """
    {"size": 5, "sha256": "2cf24dba5fb0a30e26e83b2ac5b9e29e1b161e5c1fa7425e73043362938b9824", "idempotencyKey": "acceptance-replay", "retentionReason": "technical-file", "legalBasis": "contract"}
    """
    And the response status code should be 200
    And the JSON node "data.state" should be equal to "declared"
    When I send a "POST" request to "/documents/uploads" with body:
    """
    {"size": 6, "sha256": "2cf24dba5fb0a30e26e83b2ac5b9e29e1b161e5c1fa7425e73043362938b9824", "idempotencyKey": "acceptance-replay", "retentionReason": "technical-file", "legalBasis": "contract"}
    """
    Then the response status code should be 409
    And the JSON node "type" should be equal to "idempotency-key-conflict"
    And I execute the SQL query "SELECT id FROM upload_intent"
    And there should have 1 records in SQL result

  Scenario: A replay after the upload was filed answers the document, and a PUT on it is answered alike
    Given I am logged in as an editor
    And I send a "POST" request to "/documents/uploads" with body:
    """
    {"size": 5, "sha256": "2cf24dba5fb0a30e26e83b2ac5b9e29e1b161e5c1fa7425e73043362938b9824", "idempotencyKey": "acceptance-filed", "retentionReason": "technical-file", "legalBasis": "contract"}
    """
    And I add "Content-Type" header equal to "application/octet-stream"
    And I add "Content-Length" header equal to "5"
    And I send a "PUT" request to "/documents/uploads/{value}/content?filename=a.pdf" using the JSON node "data.id" from the previous response with body:
    """
    hello
    """
    And the response status code should be 201
    And I add "Content-Type" header equal to "application/json"
    And I send a "POST" request to "/documents/uploads" with body:
    """
    {"size": 5, "sha256": "2cf24dba5fb0a30e26e83b2ac5b9e29e1b161e5c1fa7425e73043362938b9824", "idempotencyKey": "acceptance-filed", "retentionReason": "technical-file", "legalBasis": "contract"}
    """
    And the response status code should be 200
    And the JSON node "data.state" should be equal to "received"
    And the JSON node "data.documentId" should exist
    When I add "Content-Type" header equal to "application/octet-stream"
    And I send a "PUT" request to "/documents/uploads/{value}/content?filename=ignored.pdf" using the JSON node "data.id" from the previous response with body:
    """
    hello
    """
    Then the response status code should be 200
    And the JSON node "data.state" should be equal to "received"
    And I execute the SQL query "SELECT id FROM document"
    And there should have 1 records in SQL result

  Scenario: A PUT on an expired upload is gone and touches nothing
    Given I am logged in as an editor
    When I send a "POST" request to "/documents/uploads" with body:
    """
    {"size": 5, "retentionReason": "technical-file", "legalBasis": "contract"}
    """
    And I execute the SQL query "UPDATE upload_intent SET state = 'expired', key_hash = NULL"
    And I add "Content-Type" header equal to "application/octet-stream"
    And I add "Content-Length" header equal to "5"
    And I send a "PUT" request to "/documents/uploads/{value}/content?filename=a.pdf" using the JSON node "data.id" from the previous response with body:
    """
    hello
    """
    Then the response status code should be 410
    And the JSON node "type" should be equal to "gone"
    And I execute the SQL query "SELECT id FROM document"
    And there should have 0 records in SQL result

  Scenario: Another actor's upload is gone, exactly as an absent one
    Given I am logged in as an editor
    When I send a "POST" request to "/documents/uploads" with body:
    """
    {"size": 5, "retentionReason": "technical-file", "legalBasis": "contract"}
    """
    And I am logged in as an administrator
    And I add "Content-Type" header equal to "application/octet-stream"
    And I add "Content-Length" header equal to "5"
    And I send a "PUT" request to "/documents/uploads/{value}/content?filename=a.pdf" using the JSON node "data.id" from the previous response with body:
    """
    hello
    """
    Then the response status code should be 410
    And the JSON node "type" should be equal to "gone"
    And I execute the SQL query "SELECT id FROM upload_intent WHERE state = 'declared'"
    And there should have 1 records in SQL result

  Scenario: A body shorter than its declared length aborts the upload
    Given I am logged in as an editor
    When I send a "POST" request to "/documents/uploads" with body:
    """
    {"size": 10, "retentionReason": "technical-file", "legalBasis": "contract"}
    """
    And I add "Content-Type" header equal to "application/octet-stream"
    And I add "Content-Length" header equal to "10"
    And I send a "PUT" request to "/documents/uploads/{value}/content?filename=a.pdf" using the JSON node "data.id" from the previous response with body:
    """
    hello
    """
    Then the response status code should be 422
    And the JSON node "type" should be equal to "aborted-upload"
    And I execute the SQL query "SELECT id FROM upload_intent WHERE state = 'expired' AND key_hash IS NULL AND claimed_at IS NOT NULL"
    And there should have 1 records in SQL result
    And I execute the SQL query "SELECT id FROM document"
    And there should have 0 records in SQL result
    And no expired upload has a staged object left
    And the upload reaper runs
    And the scope of every reaped upload is tombstoned as an aborted upload

  Scenario: A viewer may neither declare nor send an upload, and nothing is created
    Given I am logged in as a viewer
    When I send a "POST" request to "/documents/uploads" with body:
    """
    {"size": 5, "retentionReason": "technical-file", "legalBasis": "contract"}
    """
    Then the response status code should be 403
    And I add "Content-Type" header equal to "application/octet-stream"
    And I add "Content-Length" header equal to "5"
    And I send a "PUT" request to "/documents/uploads/0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1a2c/content?filename=a.pdf" with body:
    """
    hello
    """
    And the response status code should be 403
    And I execute the SQL query "SELECT id FROM upload_intent"
    And there should have 0 records in SQL result
    And I execute the SQL query "SELECT id FROM document"
    And there should have 0 records in SQL result

  Scenario: An invalid filename is refused without being quoted and leaves the upload declared
    Given I am logged in as an editor
    When I send a "POST" request to "/documents/uploads" with body:
    """
    {"size": 5, "retentionReason": "technical-file", "legalBasis": "contract"}
    """
    And I add "Content-Type" header equal to "application/octet-stream"
    And I add "Content-Length" header equal to "5"
    And I send a "PUT" request to "/documents/uploads/{value}/content?filename=invoice%E2%80%AEfdp.exe" using the JSON node "data.id" from the previous response with body:
    """
    hello
    """
    Then the response status code should be 422
    And the JSON node "type" should be equal to "invalid-filename"
    And the JSON node "title" should not contain "fdp.exe"
    And I execute the SQL query "SELECT id FROM upload_intent WHERE state = 'declared'"
    And there should have 1 records in SQL result

  Scenario: While ingest is switched off both routes answer as if they did not exist
    Given I am logged in as an editor
    And evidence ingest is switched off
    And I send a "POST" request to "/documents/uploads" with body:
    """
    {"size": 5, "retentionReason": "technical-file", "legalBasis": "contract"}
    """
    And the response status code should be 404
    When I send a "PUT" request to "/documents/uploads/0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1a2c/content?filename=a.pdf" with body:
    """
    hello
    """
    Then the response status code should be 404
    And I execute the SQL query "SELECT id FROM upload_intent"
    And there should have 0 records in SQL result
