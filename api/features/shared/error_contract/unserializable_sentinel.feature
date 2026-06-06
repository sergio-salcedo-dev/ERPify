Feature: Unserializable sentinel substitutes non-whitelisted context values
    As a security reviewer
    In order to prevent class names, file paths, and structural metadata from leaking
    through DomainException::context() into the wire body when a value is not
    JSON-serializable, the factory must substitute the literal `[unserializable]` token
    while keeping diagnostic detail in logs only.

  # applyUnserializableSentinel substitutes any non-whitelisted top-level
  # context value (closure, resource, stdClass, anonymous proxy) with the literal
  # `'[unserializable]'` and emits one PSR-3 notice per replacement. Body sentinel is
  # type-uniform: no `'[resource]'` / `'[closure]'` variants, no class name, no NUL byte.

  Scenario: Non-whitelisted context values are substituted with the literal sentinel
    Given I add "Accept" header equal to "application/json"
    When I send a "GET" request to "http://localhost/api/test/_throw-unserializable-context"
    Then the response status code should be 404
    And the header "Content-Type" should be equal to "application/problem+json"
    And the JSON node "type" should be equal to "not-found"
    And the JSON node "proxy" should be equal to "[unserializable]"
    And the JSON node "cb" should be equal to "[unserializable]"
    And the JSON node "safe_field" should be equal to "kept"
    And the response should not contain "stdClass"
    And 0 requests got executed across all doctrine connections
