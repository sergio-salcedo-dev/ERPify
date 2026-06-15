Feature: Delete a bank
  As an API consumer
  In order to manage banks
  I need to be able to delete a bank

  # Seed the bank out-of-band via raw SQL on a side connection the query counter ignores, so the
  # asserted budget isolates the delete path from the cost of creating the bank.
  Scenario: Successfully delete a bank
    Given I execute the SQL query "INSERT INTO bank (id, name, name_normalized, short_name, created_at, updated_at) VALUES ('de1e7e00-0000-7000-8000-000000000001', 'Bank To Delete', 'bank to delete', 'BTD', NOW(), NOW())" on connection "seed"
    When I send a "DELETE" request to "/backoffice/banks/de1e7e00-0000-7000-8000-000000000001"
    Then the response status code should be 204
    And 8 requests got executed only for doctrine connection "default"
    And the "Bank" entity found by "id=de1e7e00-0000-7000-8000-000000000001" does not exist
    And there should have 1 "Erpify\Shared\Infrastructure\Persistence\Entity\StoredDomainEvent" entity found by "aggregateId=de1e7e00-0000-7000-8000-000000000001&name=erpify.backoffice.bank.deleted"

  Scenario: Delete a bank that does not exist returns a 404 bank-not-found Problem Details body
    When I send a "DELETE" request to "/backoffice/banks/2e6d865c-17b0-476a-85f2-037bf6d3b3dc"
    Then the response status code should be 404
    And the header "Content-Type" should be equal to "application/problem+json"
    And the header "Cache-Control" should contain "no-store"
    And the response should be in JSON
    And the JSON node "type" should be equal to "bank-not-found"
    And the JSON node "title" should be equal to "Bank with id <2e6d865c-17b0-476a-85f2-037bf6d3b3dc> not found."
    And the JSON node "status" should be equal to the number 404
    And the JSON node "bankId" should be equal to "2e6d865c-17b0-476a-85f2-037bf6d3b3dc"
    And the JSON node "instance" should be a valid UUID version 7
    And the JSON node "correlation-id" should be a valid UUID version 7
    And 1 request got executed only for doctrine connection "default"

  Scenario: Delete a bank referenced by accounts returns a 409 bank-in-use Problem Details body
    When I send a "DELETE" request to "/backoffice/banks/11111111-1111-7000-8000-000000000001"
    Then the response status code should be 409
    And the header "Content-Type" should be equal to "application/problem+json"
    And the header "Cache-Control" should contain "no-store"
    And the response should be in JSON
    And the JSON node "type" should be equal to "bank-in-use"
    And the JSON node "title" should be equal to "Cannot delete the bank because it still has 1 associated account."
    And the JSON node "status" should be equal to the number 409
    And the JSON node "bankId" should be equal to "11111111-1111-7000-8000-000000000001"
    And the JSON node "accountCount" should be equal to the number 1
    And the JSON node "instance" should be a valid UUID version 7
    And the JSON node "correlation-id" should be a valid UUID version 7
    And 2 requests got executed only for doctrine connection "default"
