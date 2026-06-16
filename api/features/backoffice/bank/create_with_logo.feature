# @gherkinlint-disable-rule no-empty-file
#Feature: Create a bank with an optional logo (multipart)
#  As an API consumer
#  I need to upload a bank logo with multipart/form-data
#  And fetch the normalized image by content hash
#
#  Scenario: Successfully create a bank with a logo and serve bytes
#    When I send a POST multipart request to "/backoffice/banks" with fields:
#      | field      | value              |
#      | name       | Behat Logo Bank    |
#      | shortName  | BLB                |
#      | image      | @minimal-logo.png  |
#    Then the response status code should be 201
#    And the JSON field "logoUrl" in the last response should match "#api/v1/media/[a-f0-9]{64}#"
#    And I remember the JSON field "id" as "bankId"
#    And I remember the JSON field "logoUrl" as "logoUrl"
#    And I send a GET request to the URL stored as "logoUrl"
#    And the response status code should be 200
#    And the response header "Content-Type" should be "image/png"
#    And the response header "Cache-Control" should contain "immutable"
#    And the response header "ETag" should match "#^[a-f0-9]{64}$#"
#
#  Scenario: Logo GET returns 304 when If-None-Match matches ETag
#    When I send a POST multipart request to "/backoffice/banks" with fields:
#      | field      | value              |
#      | name       | Behat Etag Bank    |
#      | shortName  | BEB                |
#      | image      | @minimal-logo.png  |
#    Then the response status code should be 201
#    And I remember the JSON field "logoUrl" as "logoUrl"
#
#    And I send a GET request to the URL stored as "logoUrl"
#    And the response status code should be 200
#    And I remember the response header "ETag" as "logoEtag"
#    And I send a GET request to the URL stored as "logoUrl" with headers:
#      | If-None-Match | {logoEtag} |
#    And the response status code should be 304
