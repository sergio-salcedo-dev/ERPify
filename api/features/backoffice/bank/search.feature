Feature: Search banks
  As an API consumer
  In order to manage banks
  I need to be able to retrieve banks

  Scenario: List all banks
    When I send a "GET" request to "/backoffice/banks"
    Then the response status code should be 200
    And the JSON node "data" should have 31 elements
    And the JSON nodes matching "data[*]" should have 5 children
    And the JSON nodes matching "data[*].id" should exist
    And the JSON nodes matching "data[*].name" should exist
    And the JSON nodes matching "data[*].shortName" should exist
    And the JSON nodes matching "data[*].createdAt" should exist
    And the JSON nodes matching "data[*].updatedAt" should exist
    And the JSON node "pagination" should have 5 elements
    And the JSON node "pagination.currentPage" should be equal to the number 1
    And the JSON node "pagination.pageCount" should be null
    And the JSON node "pagination.hasMorePages" should be false
    And the JSON node "pagination.cursor" should not be null
    And 2 requests got executed only for doctrine connection "default"

  Scenario: Filtering by a valid id that does not exist returns no results
    When I send a "GET" request to "/backoffice/banks?filters[0][field]=id&filters[0][operator]=in&filters[0][value][]=2e6d865c-17b0-476a-85f2-037bf6d3b3dc"
    Then the response status code should be 200
    And the JSON node "data" should have 0 elements
    And the JSON node "pagination" should exist
    And 1 request got executed only for doctrine connection "default"

  Scenario: Unknown pagination mode returns 422
    When I send a "GET" request to "/backoffice/banks?paginationMode=unknownPaginationMode"
    Then the response status code should be 422
    And 0 requests got executed across all doctrine connections

  Scenario: Array-form pagination mode returns 422
    When I send a "GET" request to "/backoffice/banks?paginationMode[]=light"
    Then the response status code should be 422
    And 0 requests got executed across all doctrine connections

  Scenario Outline: Invalid page returns 422
    When I send a "GET" request to "/backoffice/banks?page=<page>"
    Then the response status code should be 422
    And 0 requests got executed across all doctrine connections
    Examples:
      | page  |
      | 0     |
      | -1    |
      | 10001 |

  Scenario Outline: Invalid limit returns 422
    When I send a "GET" request to "/backoffice/banks?limit=<limit>"
    Then the response status code should be 422
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
    And the JSON node "data" should have 5 elements
    And the JSON node "pagination.currentPage" should be equal to the number 1
    And the JSON node "pagination.pageCount" should be null
    And the JSON node "pagination.hasMorePages" should be true
    And the JSON node "pagination.cursor" should not be null
    And 2 requests got executed only for doctrine connection "default"

  Scenario: Light pagination mode follows the cursor to the next page
    Given I send a "GET" request to "/backoffice/banks?paginationMode=light&limit=5"
    And I send a "GET" request to "/backoffice/banks?paginationMode=light&limit=5&page=2&cursor={value}" using the JSON node "pagination.cursor" from the previous response
    Then the response status code should be 200
    And the JSON node "data" should have 5 elements
    And the JSON node "pagination.currentPage" should be equal to the number 2
    And the JSON node "pagination.pageCount" should be null
    And the JSON node "pagination.hasMorePages" should be true
    And the JSON node "pagination.cursor" should not be null
    And 4 requests got executed only for doctrine connection "default"

  Scenario: Detailed pagination mode follows the cursor to the next page
    Given I send a "GET" request to "/backoffice/banks?paginationMode=detailed&limit=5"
    And I send a "GET" request to "/backoffice/banks?paginationMode=detailed&limit=5&page=2&cursor={value}" using the JSON node "pagination.cursor" from the previous response
    Then the response status code should be 200
    And the JSON node "data" should have 5 elements
    And the JSON node "pagination.currentPage" should be equal to the number 2
    And the JSON node "pagination.pageCount" should be equal to the number 7
    And the JSON node "pagination.hasMorePages" should be true
    And the JSON node "pagination.cursor" should not be null
    And 5 requests got executed only for doctrine connection "default"

  Scenario: Detailed pagination mode exposes total counts on a full first page
    When I send a "GET" request to "/backoffice/banks?paginationMode=detailed&limit=1000"
    Then the response status code should be 200
    And the JSON node "data" should have 31 elements
    And the JSON node "pagination.currentPage" should be equal to the number 1
    And the JSON node "pagination.pageCount" should be equal to the number 1
    And the JSON node "pagination.hasMorePages" should be false
    And 2 requests got executed only for doctrine connection "default"

  Scenario: Detailed pagination mode runs the COUNT query when the page does not fit
    When I send a "GET" request to "/backoffice/banks?paginationMode=detailed&limit=10"
    Then the response status code should be 200
    And the JSON node "data" should have 10 elements
    And the JSON node "pagination.currentPage" should be equal to the number 1
    And the JSON node "pagination.pageCount" should be equal to the number 4
    And the JSON node "pagination.hasMorePages" should be true
    And 3 requests got executed only for doctrine connection "default"

  # Generic filters[N][field|operator|value] contract — the single filtering vocabulary. The
  # legacy names[]/ids[] params were retired before any production deployment (story 1.5).
  Scenario: Generic eq filter matches a bank case-insensitively
    When I send a "GET" request to "/backoffice/banks?filters[0][field]=name&filters[0][operator]=eq&filters[0][value]=BBVA"
    Then the response status code should be 200
    And the JSON node "data" should have 1 elements
    And the JSON node "pagination" should exist
    And 2 requests got executed only for doctrine connection "default"

  Scenario: Generic in filter matches several banks
    When I send a "GET" request to "/backoffice/banks?filters[0][field]=name&filters[0][operator]=in&filters[0][value][]=BBVA&filters[0][value][]=CaixaBank"
    Then the response status code should be 200
    And the JSON node "data" should have 2 elements
    And 2 requests got executed only for doctrine connection "default"

  Scenario: Generic contains filter matches banks by substring
    When I send a "GET" request to "/backoffice/banks?filters[0][field]=name&filters[0][operator]=contains&filters[0][value]=banc"
    Then the response status code should be 200
    And the JSON node "data" should have 2 elements
    And 2 requests got executed only for doctrine connection "default"

  Scenario: Generic contains filter ignores diacritics in the search value
    When I send a "GET" request to "/backoffice/banks?filters[0][field]=name&filters[0][operator]=contains&filters[0][value]=G%C3%A9n%C3%A9rale"
    Then the response status code should be 200
    And the JSON node "data" should have 1 elements
    And 2 requests got executed only for doctrine connection "default"

  Scenario: Generic id filter accepts the in operator with a bound uuid
    When I send a "GET" request to "/backoffice/banks?filters[0][field]=id&filters[0][operator]=in&filters[0][value][]=11111111-1111-7000-8000-000000000020"
    Then the response status code should be 200
    And the JSON node "data" should have 1 elements
    And 2 requests got executed only for doctrine connection "default"

  # The boundary scenario pins real behaviour under PHP's default max_input_vars=1000:
  # the effective wire limit is min(caps, max_input_vars, URL length).
  Scenario: Generic in filter at the values cap stays within max_input_vars
    When I send a "GET" request to "/backoffice/banks" with a "name" in-filter of 100 values, the last being "BBVA"
    Then the response status code should be 200
    And the JSON node "data" should have 1 elements
    And 2 requests got executed only for doctrine connection "default"

  # Semantic 400s come from the applier (invalid-search-criteria family) and abort before
  # any SQL executes; storedObjectKey is a real column but NOT in the allow-list on purpose.
  Scenario: Generic filter on a field outside the allow-list returns a 400 unknown-search-field Problem Details body
    When I send a "GET" request to "/backoffice/banks?filters[0][field]=storedObjectKey&filters[0][operator]=eq&filters[0][value]=BBVA"
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
    And the JSON node "data" should have 1 elements
    And the JSON node "data[0].id" should be equal to "11111111-1111-7000-8000-000000000020"
    And 2 requests got executed only for doctrine connection "default"

  Scenario: Generic in filter over name ignores diacritics in the search values
    When I send a "GET" request to "/backoffice/banks?filters[0][field]=name&filters[0][operator]=in&filters[0][value][]=Sociedad%20Anonima"
    Then the response status code should be 200
    And the JSON node "data" should have 1 elements
    And the JSON node "data[0].id" should be equal to "11111111-1111-7000-8000-000000000031"
    And 2 requests got executed only for doctrine connection "default"

  Scenario: Generic in filter over id pins the exact bank id
    When I send a "GET" request to "/backoffice/banks?filters[0][field]=id&filters[0][operator]=in&filters[0][value][]=11111111-1111-7000-8000-000000000020"
    Then the response status code should be 200
    And the JSON node "data" should have 1 elements
    And the JSON node "data[0].id" should be equal to "11111111-1111-7000-8000-000000000020"
    And 2 requests got executed only for doctrine connection "default"

  Scenario: Two generic filters on the same field compose with AND
    When I send a "GET" request to "/backoffice/banks?filters[0][field]=name&filters[0][operator]=in&filters[0][value][]=Banco%20Santander&filters[1][field]=name&filters[1][operator]=contains&filters[1][value]=banc"
    Then the response status code should be 200
    And the JSON node "data" should have 1 elements
    And the JSON node "data[0].id" should be equal to "11111111-1111-7000-8000-000000000019"
    And 2 requests got executed only for doctrine connection "default"

  Scenario: Disjoint generic filters compose with AND into an empty result
    When I send a "GET" request to "/backoffice/banks?filters[0][field]=name&filters[0][operator]=in&filters[0][value][]=BBVA&filters[1][field]=name&filters[1][operator]=contains&filters[1][value]=banc"
    Then the response status code should be 200
    And the JSON node "data" should have 0 elements
    And 1 request got executed only for doctrine connection "default"

  # The retired legacy params are plain unknown query params now: ignored like any other,
  # never an error, never a filter.
  Scenario: Retired legacy filter params are ignored as unknown query params
    When I send a "GET" request to "/backoffice/banks?names[]=BBVA&ids[]=11111111-1111-7000-8000-000000000020"
    Then the response status code should be 200
    And the JSON node "data" should have 31 elements
    And 2 requests got executed only for doctrine connection "default"

  # Story 1.7: shortName is filterable (eq/in/contains). The column is stored upper-case ASCII
  # via NormalizedText::toAsciiUpper, and the field normalizer applies the same rule to the
  # search value, so matching is case-insensitive by construction (lowercase input matches).
  Scenario: Generic eq filter over shortName matches case-insensitively
    When I send a "GET" request to "/backoffice/banks?filters[0][field]=shortName&filters[0][operator]=eq&filters[0][value]=bbva"
    Then the response status code should be 200
    And the JSON node "data" should have 1 elements
    And the JSON node "data[0].id" should be equal to "11111111-1111-7000-8000-000000000020"
    And 2 requests got executed only for doctrine connection "default"

  Scenario: Generic in filter over shortName matches several banks case-insensitively
    When I send a "GET" request to "/backoffice/banks?filters[0][field]=shortName&filters[0][operator]=in&filters[0][value][]=bbva&filters[0][value][]=san"
    Then the response status code should be 200
    And the JSON node "data" should have 2 elements
    And 2 requests got executed only for doctrine connection "default"

  Scenario: Generic contains filter over shortName matches by substring case-insensitively
    When I send a "GET" request to "/backoffice/banks?filters[0][field]=shortName&filters[0][operator]=contains&filters[0][value]=bbv"
    Then the response status code should be 200
    And the JSON node "data" should have 1 elements
    And the JSON node "data[0].id" should be equal to "11111111-1111-7000-8000-000000000020"
    And 2 requests got executed only for doctrine connection "default"

  # Story 1.7: temporal range operators (gt/gte/lt/lte) over createdAt/updatedAt. Fixtures are
  # created at load time, so bounds use a far past/future to keep counts deterministic. The "+"
  # in the offset is URL-encoded as %2B. Range is allow-listed ONLY on createdAt/updatedAt.
  Scenario: Generic gte range filter over createdAt returns banks created on or after a past bound
    When I send a "GET" request to "/backoffice/banks?filters[0][field]=createdAt&filters[0][operator]=gte&filters[0][value]=2000-01-01T00:00:00%2B00:00"
    Then the response status code should be 200
    And the JSON node "data" should have 31 elements
    And 2 requests got executed only for doctrine connection "default"

  Scenario: Generic lt range filter over updatedAt returns banks updated before a future bound
    When I send a "GET" request to "/backoffice/banks?filters[0][field]=updatedAt&filters[0][operator]=lt&filters[0][value]=2100-01-01T00:00:00%2B00:00"
    Then the response status code should be 200
    And the JSON node "data" should have 31 elements
    And 2 requests got executed only for doctrine connection "default"

  Scenario: Generic gt range filter over createdAt with a future bound returns no results
    When I send a "GET" request to "/backoffice/banks?filters[0][field]=createdAt&filters[0][operator]=gt&filters[0][value]=2100-01-01T00:00:00%2B00:00"
    Then the response status code should be 200
    And the JSON node "data" should have 0 elements
    And 1 request got executed only for doctrine connection "default"

  # The negative twin of the "all 31" sanity checks above: a past upper bound proves lte
  # actually constrains. A filter that ignored the bound would still return all 31, so this
  # scenario fails on a no-op range filter where the "all 31" ones would not.
  Scenario: Generic lte range filter over createdAt with a past bound returns no results
    When I send a "GET" request to "/backoffice/banks?filters[0][field]=createdAt&filters[0][operator]=lte&filters[0][value]=2000-01-01T00:00:00%2B00:00"
    Then the response status code should be 200
    And the JSON node "data" should have 0 elements
    And 1 request got executed only for doctrine connection "default"

  # The canonical JS Date.prototype.toISOString() form (fractional seconds + Z) is accepted as
  # a first-class bound, not only the +00:00 offset form.
  Scenario: Generic gte range filter accepts the JS toISOString datetime form
    When I send a "GET" request to "/backoffice/banks?filters[0][field]=createdAt&filters[0][operator]=gte&filters[0][value]=2000-01-01T00:00:00.000Z"
    Then the response status code should be 200
    And the JSON node "data" should have 31 elements
    And 2 requests got executed only for doctrine connection "default"

  # An out-of-range UTC offset (beyond UTC+14/-12) is malformed input: rejected as a 400
  # invalid-search-value before any SQL runs, never silently shifted past a real timezone.
  Scenario: A range filter with an out-of-range UTC offset returns 400 invalid-search-value
    When I send a "GET" request to "/backoffice/banks?filters[0][field]=createdAt&filters[0][operator]=gte&filters[0][value]=2026-01-01T00:00:00%2B25:00"
    Then the response status code should be 400
    And the header "Content-Type" should be equal to "application/problem+json"
    And the JSON node "type" should be equal to "invalid-search-value"
    And 0 requests got executed across all doctrine connections

  # gte+lte over the same field compose with AND into a closed range — the documented
  # equivalent of a (deliberately absent) "between" operator.
  Scenario: A closed date range composes gte and lte with AND
    When I send a "GET" request to "/backoffice/banks?filters[0][field]=createdAt&filters[0][operator]=gte&filters[0][value]=2000-01-01T00:00:00%2B00:00&filters[1][field]=createdAt&filters[1][operator]=lte&filters[1][value]=2100-01-01T00:00:00%2B00:00"
    Then the response status code should be 200
    And the JSON node "data" should have 31 elements
    And 2 requests got executed only for doctrine connection "default"

  # name lists default operators (eq/in/contains) only; a range operator is not allow-listed,
  # so the applier rejects it semantically before any SQL runs.
  Scenario: A range operator on a field that does not allow it returns 400 unsupported-search-operator
    When I send a "GET" request to "/backoffice/banks?filters[0][field]=name&filters[0][operator]=gt&filters[0][value]=x"
    Then the response status code should be 400
    And the header "Content-Type" should be equal to "application/problem+json"
    And the JSON node "type" should be equal to "unsupported-search-operator"
    And 0 requests got executed across all doctrine connections

  # A malformed datetime is client input: it must surface as 400 invalid-search-value, never as
  # a Postgres 22007/22008 turned 500. The applier rejects it before any SQL runs.
  Scenario: A malformed datetime on a range filter returns 400 invalid-search-value
    When I send a "GET" request to "/backoffice/banks?filters[0][field]=createdAt&filters[0][operator]=gte&filters[0][value]=not-a-date"
    Then the response status code should be 400
    And the header "Content-Type" should be equal to "application/problem+json"
    And the JSON node "type" should be equal to "invalid-search-value"
    And the JSON node "field" should be equal to "createdAt"
    And the JSON node "position" should be equal to the number 0
    And 0 requests got executed across all doctrine connections

  # Range operators are scalar: a list value is a shape error caught at mapping (validation-failed).
  Scenario: A range operator with a list value returns 400 validation-failed
    When I send a "GET" request to "/backoffice/banks?filters[0][field]=createdAt&filters[0][operator]=gt&filters[0][value][]=2026-01-01T00:00:00%2B00:00"
    Then the response status code should be 400
    And the JSON node "type" should be equal to "validation-failed"
    And 0 requests got executed across all doctrine connections

  # Story 1.8: server-side ordering. `sort` resolves against the repository's sort allow-list
  # (name/shortName/createdAt/updatedAt → b.*), never interpolated raw into DQL; `direction` is the
  # SortDirection enum with uppercase wire tokens (ASC/DESC), distinct from the lowercase operators.
  # Order-correctness is pinned on fields with distinct values per bank: name orders by the
  # accent-folded lower-cased nameNormalized, shortName by its upper-case column. data[0].id under
  # limit=1 proves both the field resolution and the direction.
  Scenario Outline: Sorting by a distinct-valued field orders the list by that field
    When I send a "GET" request to "/backoffice/banks?sort=<field>&direction=<direction>&limit=1"
    Then the response status code should be 200
    And the JSON node "data" should have 1 elements
    And the JSON node "data[0].id" should be equal to "<id>"
    And 2 requests got executed only for doctrine connection "default"
    Examples:
      | field     | direction | id                                   |
      | name      | ASC       | 11111111-1111-7000-8000-000000000022 |
      | name      | DESC      | 11111111-1111-7000-8000-000000000003 |
      | shortName | ASC       | 11111111-1111-7000-8000-000000000016 |
      | shortName | DESC      | 11111111-1111-7000-8000-000000000003 |

  # createdAt/updatedAt are allow-listed and index-backed (NFR4), but the Alice fixtures are created
  # within the same instant, so ordering by them ties and the id ASC tiebreak (added by the
  # Paginator) decides — there is no date order to pin. These scenarios prove the temporal fields
  # are accepted (never a 400 unknown-sort-field) and the indexed query executes in both directions.
  Scenario Outline: Sorting by an allow-listed temporal field executes in both directions
    When I send a "GET" request to "/backoffice/banks?sort=<field>&direction=<direction>"
    Then the response status code should be 200
    And the JSON node "data" should have 31 elements
    And 2 requests got executed only for doctrine connection "default"
    Examples:
      | field     | direction |
      | createdAt | ASC       |
      | createdAt | DESC      |
      | updatedAt | ASC       |
      | updatedAt | DESC      |

  # Semantic 400: a sort field outside the allow-list is rejected before any SQL runs (the field
  # is never interpolated into DQL). Reuses the InvalidSearchCriteria family → unknown-sort-field.
  Scenario: Sorting by a field outside the sort allow-list returns 400 unknown-sort-field
    When I send a "GET" request to "/backoffice/banks?sort=id&direction=ASC"
    Then the response status code should be 400
    And the header "Content-Type" should be equal to "application/problem+json"
    And the JSON node "type" should be equal to "unknown-sort-field"
    And the JSON node "status" should be equal to the number 400
    And 0 requests got executed across all doctrine connections

  # Shape 400: an invalid direction is caught at mapping by the enum (validation-failed), exactly
  # like an unknown paginationMode — no new code, the #[MapQueryString] + enum type provides it.
  Scenario: An invalid sort direction returns 400 validation-failed
    When I send a "GET" request to "/backoffice/banks?sort=name&direction=sideways"
    Then the response status code should be 400
    And the JSON node "type" should be equal to "validation-failed"
    And 0 requests got executed across all doctrine connections

  # Lowercase direction tokens are not the enum backing values (ASC/DESC); rejected at mapping.
  Scenario: A lowercase sort direction returns 400 validation-failed
    When I send a "GET" request to "/backoffice/banks?sort=name&direction=asc"
    Then the response status code should be 400
    And the JSON node "type" should be equal to "validation-failed"
    And 0 requests got executed across all doctrine connections

  # Without sort the default order is unchanged (createdAt asc, id tiebreak) — full backward compat.
  Scenario: Without an explicit sort the default order is unchanged
    When I send a "GET" request to "/backoffice/banks?limit=1"
    Then the response status code should be 200
    And the JSON node "data" should have 1 elements
    And the JSON node "data[0].id" should be equal to "11111111-1111-7000-8000-000000000001"
    And 2 requests got executed only for doctrine connection "default"

  # Story 1.8 code-review (P1): `direction` is a nullable enum (?SortDirection), but an array form is
  # still a type mismatch at mapping → 400 validation-failed, exactly like the non-nullable
  # paginationMode[]. Pinned so a nullable-coercion regression (array silently → null → default
  # direction) is caught.
  Scenario: An array-form sort direction returns 400 validation-failed
    When I send a "GET" request to "/backoffice/banks?sort=name&direction[]=ASC"
    Then the response status code should be 400
    And the JSON node "type" should be equal to "validation-failed"
    And 0 requests got executed across all doctrine connections

  # Story 1.8 code-review (P2): a SQL/DQL-injection-shaped sort is just another value outside the sort
  # allow-list — resolved through sortFieldMap() to null → 400 unknown-sort-field before any SQL runs,
  # never interpolated into DQL. Adversarial regression guard for the "client sort is never
  # interpolated" invariant. The value decodes to `createdAt); DROP TABLE bank; --`.
  Scenario: An injection-shaped sort value is rejected before any SQL runs
    When I send a "GET" request to "/backoffice/banks?sort=createdAt%29%3B%20DROP%20TABLE%20bank%3B%20--&direction=ASC"
    Then the response status code should be 400
    And the header "Content-Type" should be equal to "application/problem+json"
    And the JSON node "type" should be equal to "unknown-sort-field"
    And 0 requests got executed across all doctrine connections

  # Story 1.8 code-review (P4): an empty sort= on the wire means "no sort" — SearchQuery::toCriteria()
  # coalesces '' → null, so it falls back to the default order (createdAt asc, id tiebreak) instead of a
  # 400 unknown-sort-field.
  Scenario: An empty sort parameter falls back to the default order
    When I send a "GET" request to "/backoffice/banks?sort=&limit=1"
    Then the response status code should be 200
    And the JSON node "data" should have 1 elements
    And the JSON node "data[0].id" should be equal to "11111111-1111-7000-8000-000000000001"
    And 2 requests got executed only for doctrine connection "default"
