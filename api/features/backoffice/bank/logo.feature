Feature: Set, replace and remove the logo of a bank
  As a back-office operator with the bank write permission
  In order to recognise a bank at a glance
  I need to upload, replace and remove its logo, and to have what I replaced or deleted stop being retrievable

  # The logo is the raw body of a PUT on the bank's own resource, never a multipart form and never an id handed in
  # by the caller: the module mints the image and the bank holds only its opaque id. A logo change is a decision, not
  # a business event, so no domain event is recorded and nothing is published to the realtime hub; the one trace is
  # the audit diff of the bank row. The release of what a change replaces is a queued request, so an image stops
  # being retrievable once the queue drains — never at the moment of the response.
  #
  # The banks are seeded on the side connection, and the images through the container's own storage, so what a
  # scenario reads back is what the stack stored.
  #
  # The database is restored once per FEATURE, not per scenario, so every scenario clears the image table first and the
  # banks insert with `ON CONFLICT DO NOTHING` (an outline inserts its bank once per example).
  Background:
    Given I execute the SQL query "DELETE FROM image" on connection "seed"
    And I add "Accept" header equal to "application/json"

  Scenario: An editor sets a logo, and the bank, the list and the image route all show it
    Given I execute the SQL query "INSERT INTO bank (id, name, name_normalized, short_name, created_at, updated_at) VALUES ('10901090-0000-7000-8000-000000000001', 'Logo Bank', 'logo bank', 'LGB', NOW(), NOW()) ON CONFLICT DO NOTHING" on connection "seed"
    And I am logged in as an editor
    And I add "Content-Type" header equal to "image/png"
    When I send a "PUT" request to "/backoffice/banks/10901090-0000-7000-8000-000000000001/logo" with the bytes of the image fixture "valid.png"
    Then the response status code should be 200
    And the JSON node "imageId" should be a valid UUID
    And I execute the SQL query "SELECT id FROM bank WHERE id = '10901090-0000-7000-8000-000000000001' AND logo_image_id IS NOT NULL"
    And there should have 1 records in SQL result
    And I execute the SQL query "SELECT id FROM image"
    And there should have 1 records in SQL result
    # A decision, not an event: nothing recorded, nothing queued, and the one trace is the audit diff.
    And there should be 0 events stored for aggregate "10901090-0000-7000-8000-000000000001" named "erpify.backoffice.bank.updated"
    And 0 outbox events were created on the queue "async"
    And I execute the SQL query "SELECT id FROM audit_log WHERE resource_id = '10901090-0000-7000-8000-000000000001' AND action = 'BANK_UPDATED' AND level = 'change'"
    And there should have 1 records in SQL result
    And I send a "GET" request to "/images/{value}" using the JSON node "imageId" from the previous response
    And the response status code should be 200
    And the header "Content-Type" should contain "image/"
    And I send a "GET" request to "/backoffice/banks/10901090-0000-7000-8000-000000000001"
    And the response status code should be 200
    And the JSON node "data.logoImageId" should be a valid UUID
    And I send a "GET" request to "/backoffice/banks?filters[0][field]=id&filters[0][operator]=eq&filters[0][value]=10901090-0000-7000-8000-000000000001"
    And the response status code should be 200
    And the JSON node "data[0].logoImageId" should be a valid UUID

  Scenario: A bank without a logo answers a null logo id on the detail and the list
    Given I execute the SQL query "INSERT INTO bank (id, name, name_normalized, short_name, created_at, updated_at) VALUES ('10901090-0000-7000-8000-000000000002', 'Plain Bank', 'plain bank', 'PLB', NOW(), NOW()) ON CONFLICT DO NOTHING" on connection "seed"
    And I am logged in as a viewer
    When I send a "GET" request to "/backoffice/banks/10901090-0000-7000-8000-000000000002"
    Then the response status code should be 200
    And the JSON node "data.logoImageId" should be null
    And I send a "GET" request to "/backoffice/banks?filters[0][field]=id&filters[0][operator]=eq&filters[0][value]=10901090-0000-7000-8000-000000000002"
    And the response status code should be 200
    And the JSON node "data[0].logoImageId" should be null

  Scenario: Replacing a logo queues exactly one release of the old image, which stops being retrievable once the queue drains
    Given I execute the SQL query "INSERT INTO bank (id, name, name_normalized, short_name, created_at, updated_at) VALUES ('10901090-0000-7000-8000-000000000003', 'Replaced Bank', 'replaced bank', 'RPB', NOW(), NOW()) ON CONFLICT DO NOTHING" on connection "seed"
    And there is a stored image with its canonical bytes
    And the bank "10901090-0000-7000-8000-000000000003" holds that image as its logo
    And I am logged in as an editor
    When I send a "GET" request for that image
    Then the response status code should be 200
    And I add "Content-Type" header equal to "image/png"
    And I send a "PUT" request to "/backoffice/banks/10901090-0000-7000-8000-000000000003/logo" with the bytes of the image fixture "valid.png"
    And the response status code should be 200
    And the JSON node "imageId" should be a valid UUID
    And 1 outbox event was created on the queue "async"
    And I got the event number 1 on queue "async" from the outbox
    And The outbox event should be of type "Erpify\Shared\Images\Domain\Event\ImageDeletionRequested"
    And I consume 1 message from the "async" transport
    And the last run should succeed
    And I send a "GET" request for that image
    And the response status code should be 404
    And I execute the SQL query "SELECT id FROM image"
    And there should have 1 records in SQL result

  Scenario: A second upload replaces the first and queues the release of the one it replaces
    Given I execute the SQL query "INSERT INTO bank (id, name, name_normalized, short_name, created_at, updated_at) VALUES ('10901090-0000-7000-8000-000000000004', 'Twice Bank', 'twice bank', 'TWB', NOW(), NOW()) ON CONFLICT DO NOTHING" on connection "seed"
    And I am logged in as an editor
    And I add "Content-Type" header equal to "image/png"
    When I send a "PUT" request to "/backoffice/banks/10901090-0000-7000-8000-000000000004/logo" with the bytes of the image fixture "valid.png"
    And I send a "PUT" request to "/backoffice/banks/10901090-0000-7000-8000-000000000004/logo" with the bytes of the image fixture "valid.png"
    Then the response status code should be 200
    And 1 outbox event was created on the queue "async"
    And I got the event number 1 on queue "async" from the outbox
    And The outbox event should be of type "Erpify\Shared\Images\Domain\Event\ImageDeletionRequested"
    And I execute the SQL query "SELECT id FROM audit_log WHERE resource_id = '10901090-0000-7000-8000-000000000004' AND action = 'BANK_UPDATED' AND level = 'change'"
    And there should have 2 records in SQL result

  Scenario: Two uploads of different sizes in one scenario each declare their own length
    Given I execute the SQL query "INSERT INTO bank (id, name, name_normalized, short_name, created_at, updated_at) VALUES ('10901090-0000-7000-8000-000000000011', 'Sizes Bank', 'sizes bank', 'SZB', NOW(), NOW()) ON CONFLICT DO NOTHING" on connection "seed"
    And I am logged in as an editor
    And I add "Content-Type" header equal to "image/png"
    When I send a "PUT" request to "/backoffice/banks/10901090-0000-7000-8000-000000000011/logo" with the bytes of the image fixture "valid.png"
    Then the response status code should be 200
    And I add "Content-Type" header equal to "image/jpeg"
    And I send a "PUT" request to "/backoffice/banks/10901090-0000-7000-8000-000000000011/logo" with the bytes of the image fixture "valid.jpg"
    And the response status code should be 200
    And 1 outbox event was created on the queue "async"

  Scenario: Removing a logo is a 204, clears the bank, queues one release, and removing again is still a 204 that queues nothing
    Given I execute the SQL query "INSERT INTO bank (id, name, name_normalized, short_name, created_at, updated_at) VALUES ('10901090-0000-7000-8000-000000000005', 'Removed Bank', 'removed bank', 'RMB', NOW(), NOW()) ON CONFLICT DO NOTHING" on connection "seed"
    And there is a stored image with its canonical bytes
    And the bank "10901090-0000-7000-8000-000000000005" holds that image as its logo
    And I am logged in as an editor
    When I send a "DELETE" request to "/backoffice/banks/10901090-0000-7000-8000-000000000005/logo"
    Then the response status code should be 204
    And I execute the SQL query "SELECT id FROM bank WHERE id = '10901090-0000-7000-8000-000000000005' AND logo_image_id IS NULL"
    And there should have 1 records in SQL result
    And 1 outbox event was created on the queue "async"
    And I consume 1 message from the "async" transport
    And the last run should succeed
    And I send a "GET" request for that image
    And the response status code should be 404
    And I send a "DELETE" request to "/backoffice/banks/10901090-0000-7000-8000-000000000005/logo"
    And the response status code should be 204
    And 0 outbox events were created on the queue "async"

  Scenario: Deleting a bank queues the release of its logo, which stops being retrievable once the queue drains
    Given I execute the SQL query "INSERT INTO bank (id, name, name_normalized, short_name, created_at, updated_at) VALUES ('10901090-0000-7000-8000-000000000006', 'Doomed Bank', 'doomed bank', 'DMB', NOW(), NOW()) ON CONFLICT DO NOTHING" on connection "seed"
    And there is a stored image with its canonical bytes
    And the bank "10901090-0000-7000-8000-000000000006" holds that image as its logo
    And I am logged in as an administrator
    When I send a "GET" request for that image
    Then the response status code should be 200
    And I send a "DELETE" request to "/backoffice/banks/10901090-0000-7000-8000-000000000006"
    And the response status code should be 204
    And 2 outbox events were created on the queue "async"
    And I consume 2 messages from the "async" transport
    And the last run should succeed
    And the "Bank" entity found by "id=10901090-0000-7000-8000-000000000006" does not exist
    And I send a "GET" request for that image
    And the response status code should be 404

  Scenario: A bank that holds a logo and still has accounts stays refused with a 409 and releases nothing
    Given there is a stored image with its canonical bytes
    And the bank "11111111-1111-7000-8000-000000000001" holds that image as its logo
    And I am logged in as an administrator
    When I send a "DELETE" request to "/backoffice/banks/11111111-1111-7000-8000-000000000001"
    Then the response status code should be 409
    And the JSON node "type" should be equal to "bank-in-use"
    And 0 outbox events were created on the queue "async"
    And I execute the SQL query "SELECT id FROM bank WHERE id = '11111111-1111-7000-8000-000000000001' AND logo_image_id IS NOT NULL"
    And there should have 1 records in SQL result
    And I send a "GET" request for that image
    And the response status code should be 200

  Scenario: Removing the logo of a bank that does not exist is the bank's own 404
    Given I am logged in as an editor
    When I send a "DELETE" request to "/backoffice/banks/2e6d865c-17b0-476a-85f2-037bf6d3b3dc/logo"
    Then the response status code should be 404
    And the JSON node "type" should be equal to "bank-not-found"

  Scenario: A logo for a bank that does not exist is refused before the body is read, and stores nothing
    Given I am logged in as an editor
    And I add "Content-Type" header equal to "image/png"
    When I send a "PUT" request to "/backoffice/banks/2e6d865c-17b0-476a-85f2-037bf6d3b3dc/logo" with the bytes of the image fixture "valid.png"
    Then the response status code should be 404
    And the JSON node "type" should be equal to "bank-not-found"
    And I execute the SQL query "SELECT id FROM image"
    And there should have 0 records in SQL result

  Scenario: A malformed bank id is a 400 invalid-uuid and stores nothing
    Given I am logged in as an editor
    And I add "Content-Type" header equal to "image/png"
    When I send a "PUT" request to "/backoffice/banks/not-a-uuid/logo" with the bytes of the image fixture "valid.png"
    Then the response status code should be 400
    And the JSON node "type" should be equal to "invalid-uuid"
    And I execute the SQL query "SELECT id FROM image"
    And there should have 0 records in SQL result

  @anonymous
  Scenario Outline: A caller with no session is refused on both verbs and nothing is stored
    Given I add "Content-Type" header equal to "image/png"
    When I send a "<method>" request to "/backoffice/banks/10901090-0000-7000-8000-000000000001/logo" with the bytes of the image fixture "valid.png"
    Then the response status code should be 401
    And the JSON node "type" should be equal to "unauthenticated"
    And I execute the SQL query "SELECT id FROM image"
    And there should have 0 records in SQL result

    Examples:
      | method |
      | PUT    |
      | DELETE |

  Scenario Outline: A reader without the bank write permission is refused on both verbs and nothing is stored
    Given I execute the SQL query "INSERT INTO bank (id, name, name_normalized, short_name, created_at, updated_at) VALUES ('10901090-0000-7000-8000-000000000007', 'Guarded Bank', 'guarded bank', 'GDB', NOW(), NOW()) ON CONFLICT DO NOTHING" on connection "seed"
    And I am logged in as a viewer
    And I add "Content-Type" header equal to "image/png"
    When I send a "<method>" request to "/backoffice/banks/10901090-0000-7000-8000-000000000007/logo" with the bytes of the image fixture "valid.png"
    Then the response status code should be 403
    And the JSON node "type" should be equal to "forbidden"
    And I execute the SQL query "SELECT id FROM image"
    And there should have 0 records in SQL result

    Examples:
      | method |
      | PUT    |
      | DELETE |

  Scenario Outline: A body that is not an acceptable image is a Problem Details refusal that stores nothing
    Given I execute the SQL query "INSERT INTO bank (id, name, name_normalized, short_name, created_at, updated_at) VALUES ('10901090-0000-7000-8000-000000000008', 'Refusing Bank', 'refusing bank', 'RFB', NOW(), NOW()) ON CONFLICT DO NOTHING" on connection "seed"
    And I am logged in as an editor
    And I add "Content-Type" header equal to "<contentType>"
    And I add "Content-Length" header equal to "<length>"
    When I send a "PUT" request to "/backoffice/banks/10901090-0000-7000-8000-000000000008/logo" with the bytes of the image fixture "<fixture>"
    Then the response status code should be <status>
    And the header "Content-Type" should contain "application/problem+json"
    And the JSON node "type" should be equal to "<type>"
    And I execute the SQL query "SELECT id FROM image"
    And there should have 0 records in SQL result
    And I execute the SQL query "SELECT id FROM bank WHERE id = '10901090-0000-7000-8000-000000000008' AND logo_image_id IS NULL"
    And there should have 1 records in SQL result

    Examples:
      | contentType | length   | fixture   | status | type                   |
      | image/png   | 5        | valid.png | 411    | image-length-required  |
      | image/png   | 99999999 | valid.png | 413    | image-too-large        |
      | image/png   | 0        | empty.bin | 422    | image-invalid          |
      | image/png   | 60       | truncated.png | 422 | image-invalid       |
      | image/png   | 12       | text.bin  | 415    | image-unsupported-type |

  Scenario: A declared media type that contradicts the bytes is a 415 and stores nothing
    Given I execute the SQL query "INSERT INTO bank (id, name, name_normalized, short_name, created_at, updated_at) VALUES ('10901090-0000-7000-8000-000000000010', 'Mislabelled Bank', 'mislabelled bank', 'MLB', NOW(), NOW()) ON CONFLICT DO NOTHING" on connection "seed"
    And I am logged in as an editor
    And I add "Content-Type" header equal to "image/jpeg"
    When I send a "PUT" request to "/backoffice/banks/10901090-0000-7000-8000-000000000010/logo" with the bytes of the image fixture "valid.png"
    Then the response status code should be 415
    And the JSON node "type" should be equal to "image-unsupported-type"
    And I execute the SQL query "SELECT id FROM image"
    And there should have 0 records in SQL result

  Scenario: A body sent with no Content-Length at all is a 411 and stores nothing
    Given I execute the SQL query "INSERT INTO bank (id, name, name_normalized, short_name, created_at, updated_at) VALUES ('10901090-0000-7000-8000-000000000009', 'Unmeasured Bank', 'unmeasured bank', 'UMB', NOW(), NOW()) ON CONFLICT DO NOTHING" on connection "seed"
    And I am logged in as an editor
    And I add "Content-Type" header equal to "image/png"
    When I send a "PUT" request to "/backoffice/banks/10901090-0000-7000-8000-000000000009/logo" with the bytes of the image fixture "valid.png" and no Content-Length
    Then the response status code should be 411
    And the JSON node "type" should be equal to "image-length-required"
    And I execute the SQL query "SELECT id FROM image"
    And there should have 0 records in SQL result
