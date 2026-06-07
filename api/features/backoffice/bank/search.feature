Feature: Search banks
  As an API consumer
  In order to manage banks
  I need to be able to retrieve banks

  Scenario: List all banks
    When I send a "GET" request to "/backoffice/banks"
    Then the response status code should be 200
    And the JSON node "data.items" should have 31 elements
    And the JSON nodes matching "data.items[*]" should have 5 children
    And the JSON nodes matching "data.items[*].id" should exist
    And the JSON nodes matching "data.items[*].name" should exist
    And the JSON nodes matching "data.items[*].shortName" should exist
    And the JSON nodes matching "data.items[*].createdAt" should exist
    And the JSON nodes matching "data.items[*].updatedAt" should exist
    And the JSON node "data.pagination" should have 5 elements
    And the JSON node "data.pagination.currentPage" should be equal to the number 1
    And the JSON node "data.pagination.pageCount" should be null
    And the JSON node "data.pagination.hasMorePages" should be false
    And the JSON node "data.pagination.cursor" should not be null
    And 2 requests got executed only for doctrine connection "default"

  Scenario: Filtering by a valid id that does not exist returns no results
    When I send a "GET" request to "/backoffice/banks?filters[0][field]=id&filters[0][operator]=in&filters[0][value][]=2e6d865c-17b0-476a-85f2-037bf6d3b3dc"
    Then the response status code should be 200
    And the JSON node "data.items" should have 0 elements
    And the JSON node "data.pagination" should exist
    And 1 request got executed only for doctrine connection "default"

  Scenario: Unknown pagination mode returns 400
    When I send a "GET" request to "/backoffice/banks?paginationMode=unknownPaginationMode"
    Then the response status code should be 400
    And 0 requests got executed across all doctrine connections

  Scenario: Array-form pagination mode returns 400
    When I send a "GET" request to "/backoffice/banks?paginationMode[]=light"
    Then the response status code should be 400
    And 0 requests got executed across all doctrine connections

  Scenario Outline: Invalid page returns 400
    When I send a "GET" request to "/backoffice/banks?page=<page>"
    Then the response status code should be 400
    And 0 requests got executed across all doctrine connections
    Examples:
      | page  |
      | 0     |
      | -1    |
      | 10001 |

  Scenario Outline: Invalid limit returns 400
    When I send a "GET" request to "/backoffice/banks?limit=<limit>"
    Then the response status code should be 400
    And 0 requests got executed across all doctrine connections
    Examples:
      | limit |
      | 0     |
      | -1    |
      | 1001  |
      | abc   |

  # PaginatorCursorFactory silently coerces unsigned input to empty cursor (HMAC hardening),
  # so missing-separator or signature-mismatch produces 200, not 400. See spec change log #1.
  Scenario: Cursor without HMAC signature is silently treated as empty
    When I send a "GET" request to "/backoffice/banks?cursor=invalidBase64"
    Then the response status code should be 200
    And 2 requests got executed only for doctrine connection "default"

  # Paginator refactor coverage: alterWhere + buildCursorWhere extraction
  # and the inlined isSingleFirstPageQuery short-circuit in setCursorCount.
  Scenario: Light pagination mode emits a cursor and skips pageCount on page one
    When I send a "GET" request to "/backoffice/banks?paginationMode=light&limit=5"
    Then the response status code should be 200
    And the JSON node "data.items" should have 5 elements
    And the JSON node "data.pagination.currentPage" should be equal to the number 1
    And the JSON node "data.pagination.pageCount" should be null
    And the JSON node "data.pagination.hasMorePages" should be true
    And the JSON node "data.pagination.cursor" should not be null
    And 2 requests got executed only for doctrine connection "default"

  Scenario: Light pagination mode follows the cursor to the next page
    Given I send a "GET" request to "/backoffice/banks?paginationMode=light&limit=5"
    And I send a "GET" request to "/backoffice/banks?paginationMode=light&limit=5&page=2&cursor={value}" using the JSON node "data.pagination.cursor" from the previous response
    Then the response status code should be 200
    And the JSON node "data.items" should have 5 elements
    And the JSON node "data.pagination.currentPage" should be equal to the number 2
    And the JSON node "data.pagination.pageCount" should be null
    And the JSON node "data.pagination.hasMorePages" should be true
    And the JSON node "data.pagination.cursor" should not be null
    And 4 requests got executed only for doctrine connection "default"

  Scenario: Detailed pagination mode follows the cursor to the next page
    Given I send a "GET" request to "/backoffice/banks?paginationMode=detailed&limit=5"
    And I send a "GET" request to "/backoffice/banks?paginationMode=detailed&limit=5&page=2&cursor={value}" using the JSON node "data.pagination.cursor" from the previous response
    Then the response status code should be 200
    And the JSON node "data.items" should have 5 elements
    And the JSON node "data.pagination.currentPage" should be equal to the number 2
    And the JSON node "data.pagination.pageCount" should be equal to the number 7
    And the JSON node "data.pagination.hasMorePages" should be true
    And the JSON node "data.pagination.cursor" should not be null
    And 5 requests got executed only for doctrine connection "default"

  Scenario: Detailed pagination mode exposes total counts on a full first page
    When I send a "GET" request to "/backoffice/banks?paginationMode=detailed&limit=1000"
    Then the response status code should be 200
    And the JSON node "data.items" should have 31 elements
    And the JSON node "data.pagination.currentPage" should be equal to the number 1
    And the JSON node "data.pagination.pageCount" should be equal to the number 1
    And the JSON node "data.pagination.hasMorePages" should be false
    And 2 requests got executed only for doctrine connection "default"

  Scenario: Detailed pagination mode runs the COUNT query when the page does not fit
    When I send a "GET" request to "/backoffice/banks?paginationMode=detailed&limit=10"
    Then the response status code should be 200
    And the JSON node "data.items" should have 10 elements
    And the JSON node "data.pagination.currentPage" should be equal to the number 1
    And the JSON node "data.pagination.pageCount" should be equal to the number 4
    And the JSON node "data.pagination.hasMorePages" should be true
    And 3 requests got executed only for doctrine connection "default"

  # Generic filters[N][field|operator|value] contract — the single filtering vocabulary. The
  # legacy names[]/ids[] params were retired before any production deployment (story 1.5).
  Scenario: Generic eq filter matches a bank case-insensitively
    When I send a "GET" request to "/backoffice/banks?filters[0][field]=name&filters[0][operator]=eq&filters[0][value]=BBVA"
    Then the response status code should be 200
    And the JSON node "data.items" should have 1 elements
    And the JSON node "data.pagination" should exist
    And 2 requests got executed only for doctrine connection "default"

  Scenario: Generic in filter matches several banks
    When I send a "GET" request to "/backoffice/banks?filters[0][field]=name&filters[0][operator]=in&filters[0][value][]=BBVA&filters[0][value][]=CaixaBank"
    Then the response status code should be 200
    And the JSON node "data.items" should have 2 elements
    And 2 requests got executed only for doctrine connection "default"

  Scenario: Generic contains filter matches banks by substring
    When I send a "GET" request to "/backoffice/banks?filters[0][field]=name&filters[0][operator]=contains&filters[0][value]=banc"
    Then the response status code should be 200
    And the JSON node "data.items" should have 2 elements
    And 2 requests got executed only for doctrine connection "default"

  Scenario: Generic contains filter ignores diacritics in the search value
    When I send a "GET" request to "/backoffice/banks?filters[0][field]=name&filters[0][operator]=contains&filters[0][value]=G%C3%A9n%C3%A9rale"
    Then the response status code should be 200
    And the JSON node "data.items" should have 1 elements
    And 2 requests got executed only for doctrine connection "default"

  Scenario: Generic id filter accepts the in operator with a bound uuid
    When I send a "GET" request to "/backoffice/banks?filters[0][field]=id&filters[0][operator]=in&filters[0][value][]=11111111-1111-7000-8000-000000000020"
    Then the response status code should be 200
    And the JSON node "data.items" should have 1 elements
    And 2 requests got executed only for doctrine connection "default"

  # The boundary scenario pins real behaviour under PHP's default max_input_vars=1000:
  # the effective wire limit is min(caps, max_input_vars, URL length).
  Scenario: Generic in filter at the values cap stays within max_input_vars
    When I send a "GET" request to "/backoffice/banks" with a "name" in-filter of 100 values, the last being "BBVA"
    Then the response status code should be 200
    And the JSON node "data.items" should have 1 elements
    And 2 requests got executed only for doctrine connection "default"

  # Semantic 400s come from the applier (invalid-search-criteria family) and abort before
  # any SQL executes; shortName is a real column but NOT in the allow-list on purpose.
  Scenario: Generic filter on a field outside the allow-list returns a 400 unknown-search-field Problem Details body
    When I send a "GET" request to "/backoffice/banks?filters[0][field]=shortName&filters[0][operator]=eq&filters[0][value]=BBVA"
    Then the response status code should be 400
    And the header "Content-Type" should be equal to "application/problem+json"
    And the response should be in JSON
    And the JSON node "type" should be equal to "unknown-search-field"
    And the JSON node "status" should be equal to the number 400
    And 0 requests got executed across all doctrine connections

  Scenario: Generic filter with an operator the field does not allow returns a 400 unsupported-search-operator Problem Details body
    When I send a "GET" request to "/backoffice/banks?filters[0][field]=id&filters[0][operator]=contains&filters[0][value]=1111"
    Then the response status code should be 400
    And the header "Content-Type" should be equal to "application/problem+json"
    And the JSON node "type" should be equal to "unsupported-search-operator"
    And 0 requests got executed across all doctrine connections

  # Shape 400s come from mapping (validation-failed + violations[]); operator tokens are
  # strictly lowercase — the enum backing string IS the wire contract.
  Scenario Outline: Invalid generic filter operator returns 400
    When I send a "GET" request to "/backoffice/banks?filters[0][field]=name&filters[0][operator]=<operator>&filters[0][value]=x"
    Then the response status code should be 400
    And 0 requests got executed across all doctrine connections
    Examples:
      | operator |
      | like     |
      | EQ       |
      | IN       |

  Scenario: Generic in filter with a scalar value returns a 400 validation-failed Problem Details body
    When I send a "GET" request to "/backoffice/banks?filters[0][field]=name&filters[0][operator]=in&filters[0][value]=BBVA"
    Then the response status code should be 400
    And the JSON node "type" should be equal to "validation-failed"
    And the JSON node "violations[0].field" should be equal to "filters[0].value"
    And 0 requests got executed across all doctrine connections

  Scenario: Generic eq filter with a list value returns a 400 validation-failed Problem Details body
    When I send a "GET" request to "/backoffice/banks?filters[0][field]=name&filters[0][operator]=eq&filters[0][value][]=BBVA"
    Then the response status code should be 400
    And the JSON node "type" should be equal to "validation-failed"
    And 0 requests got executed across all doctrine connections

  # A malformed uuid bound against the UUID column must surface as input error, never as a
  # Postgres 22P02 turned 500 — the field map marks id as requiresUuidValues.
  Scenario: Generic id filter with a malformed uuid returns a 400 invalid-search-value Problem Details body
    When I send a "GET" request to "/backoffice/banks?filters[0][field]=id&filters[0][operator]=eq&filters[0][value]=not-a-uuid"
    Then the response status code should be 400
    And the header "Content-Type" should be equal to "application/problem+json"
    And the JSON node "type" should be equal to "invalid-search-value"
    And the JSON node "field" should be equal to "id"
    And the JSON node "position" should be equal to the number 0
    And 0 requests got executed across all doctrine connections

  # Exact-id pins (story 1.5): result identity — not just counts — plus multi-filter AND
  # composition for the single filtering vocabulary.
  Scenario: Generic in filter over name pins the exact bank id
    When I send a "GET" request to "/backoffice/banks?filters[0][field]=name&filters[0][operator]=in&filters[0][value][]=BBVA"
    Then the response status code should be 200
    And the JSON node "data.items" should have 1 elements
    And the JSON node "data.items[0].id" should be equal to "11111111-1111-7000-8000-000000000020"
    And 2 requests got executed only for doctrine connection "default"

  Scenario: Generic in filter over name ignores diacritics in the search values
    When I send a "GET" request to "/backoffice/banks?filters[0][field]=name&filters[0][operator]=in&filters[0][value][]=Sociedad%20Anonima"
    Then the response status code should be 200
    And the JSON node "data.items" should have 1 elements
    And the JSON node "data.items[0].id" should be equal to "11111111-1111-7000-8000-000000000031"
    And 2 requests got executed only for doctrine connection "default"

  Scenario: Generic in filter over id pins the exact bank id
    When I send a "GET" request to "/backoffice/banks?filters[0][field]=id&filters[0][operator]=in&filters[0][value][]=11111111-1111-7000-8000-000000000020"
    Then the response status code should be 200
    And the JSON node "data.items" should have 1 elements
    And the JSON node "data.items[0].id" should be equal to "11111111-1111-7000-8000-000000000020"
    And 2 requests got executed only for doctrine connection "default"

  Scenario: Two generic filters on the same field compose with AND
    When I send a "GET" request to "/backoffice/banks?filters[0][field]=name&filters[0][operator]=in&filters[0][value][]=Banco%20Santander&filters[1][field]=name&filters[1][operator]=contains&filters[1][value]=banc"
    Then the response status code should be 200
    And the JSON node "data.items" should have 1 elements
    And the JSON node "data.items[0].id" should be equal to "11111111-1111-7000-8000-000000000019"
    And 2 requests got executed only for doctrine connection "default"

  Scenario: Disjoint generic filters compose with AND into an empty result
    When I send a "GET" request to "/backoffice/banks?filters[0][field]=name&filters[0][operator]=in&filters[0][value][]=BBVA&filters[1][field]=name&filters[1][operator]=contains&filters[1][value]=banc"
    Then the response status code should be 200
    And the JSON node "data.items" should have 0 elements
    And 1 request got executed only for doctrine connection "default"

  # The retired legacy params are plain unknown query params now: ignored like any other,
  # never an error, never a filter.
  Scenario: Retired legacy filter params are ignored as unknown query params
    When I send a "GET" request to "/backoffice/banks?names[]=BBVA&ids[]=11111111-1111-7000-8000-000000000020"
    Then the response status code should be 200
    And the JSON node "data.items" should have 31 elements
    And 2 requests got executed only for doctrine connection "default"
