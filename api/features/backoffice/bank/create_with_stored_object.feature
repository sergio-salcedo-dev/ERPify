@wip
Feature: Create a bank with an optional Flysystem stored image (multipart)

  As an API client
  I need to upload a bank stored_object alongside optional logo
  So that large or separately managed assets use object storage instead of BYTEA media

  Scenario: Successfully create a bank with stored_object and serve bytes
    When I send a POST multipart request to "/backoffice/banks" with fields:
      | field          | value              |
      | name           | Behat Stored Bank  |
      | short_name     | BSB                |
      | stored_object  | @minimal-logo.png  |
    Then the response status code should be 201
    And the response should be JSON
    And the JSON field "storedObjectUrl" in the last response should be a stored object URL
    And I remember the JSON field "id" as "bankId"
    And I remember the JSON field "storedObjectUrl" as "storedObjectUrl"
    And a domain event named "erpify.backoffice.bank.created" should be recorded for aggregate {bankId}
    And I GET the URL from the JSON field "storedObjectUrl" in the last response
    And the response status code should be 200
    And the response header "Content-Type" should be "image/png"
    And the response header "Cache-Control" should contain "immutable"
