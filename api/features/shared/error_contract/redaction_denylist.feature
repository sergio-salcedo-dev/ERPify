Feature: Redaction denylist strips sensitive context keys from error bodies
    As a security reviewer
    In order to prevent caller-controlled denylist keys (`password`, `token`, `secret`, etc.)
    from leaking through DomainException::context() into the wire body
    I need every /api/* error response to omit those keys at the factory layer.

  # RedactionDenylist::filter is invoked from ProblemDetailsFactory::redactKeys
  # before the JsonSerializable / array whitelist branch, so even a denylisted key whose
  # value is a JsonSerializable cannot smuggle its payload onto the wire.

  Scenario: Denylisted keys in DomainException context are stripped from body extensions
    Given I add "Accept" header equal to "application/json"
    When I send a "GET" request to "http://localhost/api/test/_throw-denylisted-context"
    Then the response status code should be 404
    And the header "Content-Type" should be equal to "application/problem+json"
    And the JSON node "type" should be equal to "not-found"
    And the JSON node "safe_field" should be equal to "kept"
    And the JSON node "password" should not exist
    And the JSON node "token" should not exist
    And the response should not contain "sensitive"
