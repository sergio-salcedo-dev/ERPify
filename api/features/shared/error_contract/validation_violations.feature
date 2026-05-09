Feature: ValidationFailedException surfaces as a 400 Problem Details with a structured violations[] extension
    As a PWA developer
    In order to render per-field errors without parsing strings
    I need ValidationFailedException to produce a Problem Details body whose violations
    extension is a JSON array of objects with the keys field, message, code

  # Routes are wired at /api/test/_throw-validation* (Story 1.6). The default Behat suite's
  # HttpRequestContext is constructor-bound to baseUrl=/api/v1 (FriendsOfBehat\SymfonyExtension
  # reuses the same service instance across suites, so a per-suite override does not take effect).
  # To bypass the baseUrl prefix entirely, scenarios use absolute URLs (HttpRequestContext skips
  # the prepend when the URL starts with `http`).

  Background:
    Given I add "Accept" header equal to "application/json"

  Scenario: ValidationFailedException with three field violations is mapped to a 400 validation-failed Problem Details body with structured violations
    When I send a "GET" request to "http://localhost/api/test/_throw-validation"
    Then the response status code should be 400
    And the header "Content-Type" should be equal to "application/problem+json"
    And the header "Cache-Control" should contain "no-store"
    And the response should be in JSON
    And the JSON node "type" should be equal to "validation-failed"
    And the JSON node "status" should be equal to the number 400
    And the JSON node "title" should be equal to "Validation failed."
    And the JSON node "violations" should have 3 elements
    And the JSON node "violations[0].field" should be equal to "name"
    And the JSON node "violations[0].message" should be equal to "This value should not be blank."
    And the JSON node "violations[0].code" should be equal to "c1051bb4-d103-4f74-8988-acbcafc7fdc3"
    And the JSON node "violations[1].field" should be equal to "email"
    And the JSON node "violations[1].message" should be equal to "This value is not a valid email address."
    And the JSON node "violations[1].code" should be equal to "bd79c0ab-ddba-46cc-a703-a7a4b08de310"
    And the JSON node "violations[2].field" should be equal to "age"
    And the JSON node "violations[2].message" should be equal to "This value should be greater than or equal to 18."
    And the JSON node "violations[2].code" should be equal to "ea4e51d1-3342-48bd-87f1-9e672cd90cad"
    And the JSON node "instance" should match "/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[0-9a-f]{4}-[0-9a-f]{12}$/"
    And the JSON node "correlation-id" should match "/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[0-9a-f]{4}-[0-9a-f]{12}$/"

  Scenario: ValidationFailedException with no violations still produces a conforming 400 body with an empty violations array
    When I send a "GET" request to "http://localhost/api/test/_throw-validation-empty"
    Then the response status code should be 400
    And the header "Content-Type" should be equal to "application/problem+json"
    And the header "Cache-Control" should contain "no-store"
    And the response should be in JSON
    And the JSON node "type" should be equal to "validation-failed"
    And the JSON node "status" should be equal to the number 400
    And the JSON node "title" should be equal to "Validation failed."
    And the JSON node "violations" should have 0 elements
    # Note: JSON-array-vs-object form for empty violations is pinned at the unit-test layer
    # (testValidationFailedExceptionWithEmptyListProducesEmptyViolationsArray) — Behat 3.31
    # cannot embed double-quotes in step args, so a raw `"violations":[]` substring assertion
    # is not expressible here. The unit test layer covers the JSON-array shape end-to-end.
    And the JSON node "instance" should match "/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[0-9a-f]{4}-[0-9a-f]{12}$/"
    And the JSON node "correlation-id" should match "/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[0-9a-f]{4}-[0-9a-f]{12}$/"

  Scenario: ValidationFailedException violation entries omit invalid value and root from the wire body
    When I send a "GET" request to "http://localhost/api/test/_throw-validation-with-sensitive-payload"
    Then the response status code should be 400
    And the response should be in JSON
    And the JSON node "type" should be equal to "validation-failed"
    And the JSON node "violations" should have 1 elements
    And the JSON node "violations[0].field" should be equal to "name"
    And the JSON node "violations[0].message" should be equal to "This value should not be blank."
    And the response should not contain "super-secret-payload"
    And the response should not contain "leaked-secret"
    And the response should not contain "invalidValue"
    And the response should not contain "messageTemplate"
    And the response should not contain "parameters"
    And the response should not contain "cause"
    And the response should not contain "constraint"

  Scenario: ValidationFailedException violation entries serialize message verbatim, never the template form
    When I send a "GET" request to "http://localhost/api/test/_throw-validation-template"
    Then the response status code should be 400
    And the response should be in JSON
    And the JSON node "type" should be equal to "validation-failed"
    And the JSON node "violations[0].field" should be equal to "age"
    And the JSON node "violations[0].message" should be equal to "This value should be greater than or equal to 18."
    And the response should not contain "{{ limit }}"

  Scenario: ValidationFailedException violation entries are reachable via JSON path under violations[N]
    When I send a "GET" request to "http://localhost/api/test/_throw-validation"
    Then the response status code should be 400
    And the response should be in JSON
    And the JSON node "violations[0].field" should be equal to "name"
    And the JSON node "violations[0].message" should be equal to "This value should not be blank."
    And the JSON node "violations[0].code" should be equal to "c1051bb4-d103-4f74-8988-acbcafc7fdc3"
    And the response should contain "violations"
    And the response should contain "name"
    And the response should contain "This value should not be blank."
    And the response should contain "c1051bb4-d103-4f74-8988-acbcafc7fdc3"

  Scenario: ValidationFailedException is distinct from DomainException implementing InvariantViolation
    When I send a "GET" request to "http://localhost/api/test/_throw-invariant-violation"
    Then the response status code should be 422
    And the response should be in JSON
    And the JSON node "type" should be equal to "invariant-violation"
    And the JSON node "status" should be equal to the number 422
    And the JSON node "title" should be equal to "Account already settled"
    And the JSON node "violations" should not exist
