Feature: Search the cross-bank account collection
  As an API consumer
  In order to list and search bank accounts across all banks
  I need a standalone accounts collection endpoint

  # One query per page: the read-composition JOIN (BankAccount -> Bank) hydrates each row's owning-bank
  # identity in the same SELECT, so there is no existence guard and no per-row enricher. The collection
  # access is audited once at ACTIVITY level, written asynchronously off the request's "default"
  # connection — so it never adds to the per-page query budget (mirroring the nested accounts feature,
  # whose two queries are the existence guard plus the page, the audit uncounted).
  # Default order is createdAt asc with the id tiebreak; the Alice fixtures share the load instant, so
  # the account-id sequence (…0001 → …0005) is the stable order pinned below.
  Scenario: List all accounts across banks returns the cross-bank read projection
    When I send a "GET" request to "/backoffice/bank-accounts?limit=100"
    Then the response status code should be 200
    And the JSON node "data" should have 5 elements
    And the JSON nodes matching "data[*]" should have 10 children
    And the JSON nodes matching "data[*].bankId" should exist
    And the JSON nodes matching "data[*].bankName" should exist
    And the JSON nodes matching "data[*].bankShortName" should exist
    And the JSON node "data[0].id" should be equal to "33333333-3333-7000-8000-000000000001"
    And the JSON node "data[0].bankId" should be equal to "11111111-1111-7000-8000-000000000001"
    And the JSON node "data[0].bankName" should be equal to "JPMorgan Chase"
    And the JSON node "data[0].bankShortName" should be equal to "JPM"
    And the JSON node "data[0].holderName" should be equal to "Globex Corporation"
    And the JSON node "data[0].iban" should be equal to "DE89370400440532013000"
    And the JSON node "data[0].bic" should be equal to "DEUTDEFFXXX"
    And the JSON node "data[0].alias" should be equal to "Globex Treasury"
    And the JSON node "data[0].currency" should be equal to "EUR"
    And the JSON node "data[0].status" should be equal to "INACTIVE"
    And the JSON node "data[1].bankId" should be equal to "11111111-1111-7000-8000-000000000002"
    And the JSON node "data[1].bankName" should be equal to "Bank of America"
    And the JSON node "data[1].bankShortName" should be equal to "BAC"
    And the JSON node "data[1].holderName" should be equal to "Initech LLC"
    And the JSON node "data[1].bic" should be null
    And the JSON node "data[1].alias" should be null
    And the JSON node "data[1].status" should be equal to "ACTIVE"
    And the JSON node "data[4].bankName" should be equal to "Citigroup"
    And the JSON node "data[4].bankShortName" should be equal to "C"
    And the JSON node "pagination" should have 4 elements
    And the JSON node "pagination.hasNext" should be false
    And the JSON node "pagination.hasPrev" should be false
    And the JSON node "pagination.count" should be null
    And the JSON node "pagination.links.next" should be null
    And the JSON node "pagination.links.prev" should be null
    And 2 request got executed only for doctrine connection "default"

  # holderName has no normalizer, so CONTAINS matches via LOWER(...) LIKE LOWER(...) — case-insensitive.
  # "citi" (lower-case) narrows to Citigroup's three accounts, each carrying the same owning-bank identity.
  Scenario: A free-text holderName contains filter narrows the collection case-insensitively
    When I send a "GET" request to "/backoffice/bank-accounts?filters[0][field]=holderName&filters[0][operator]=contains&filters[0][value]=citi"
    Then the response status code should be 200
    And the JSON node "data" should have 3 elements
    And the JSON node "data[0].holderName" should be equal to "Citi Operations"
    And the JSON node "data[0].bankName" should be equal to "Citigroup"
    And the JSON node "data[2].holderName" should be equal to "Citi Treasury"
    And the JSON node "data[2].bankName" should be equal to "Citigroup"
    And 2 request got executed only for doctrine connection "default"

  # bankId is exact-only (eq/in, UUID-valued): it scopes the collection to a single bank's accounts
  # without a route constraint — the bank is a tunable filter here, not the route.
  Scenario: A bankId exact filter narrows to a single bank's accounts
    When I send a "GET" request to "/backoffice/bank-accounts?filters[0][field]=bankId&filters[0][operator]=eq&filters[0][value]=11111111-1111-7000-8000-000000000004"
    Then the response status code should be 200
    And the JSON node "data" should have 3 elements
    And the JSON node "data[0].bankName" should be equal to "Citigroup"
    And the JSON node "data[0].bankShortName" should be equal to "C"
    And the JSON node "data[0].holderName" should be equal to "Citi Operations"
    And the JSON node "data[2].bankName" should be equal to "Citigroup"
    And the JSON node "data[2].holderName" should be equal to "Citi Treasury"
    And 2 request got executed only for doctrine connection "default"

  # Keyset continuity over the SELECT NEW projection: a ?sort=createdAt walk of limit=2 pages must
  # retrace the account-id sequence with no dropped/repeated row, one query per page (3 pages -> 3
  # cumulative queries on the "default" connection, the async audit uncounted).
  Scenario: Keyset pagination walks the global collection one query per page
    When I send a "GET" request to "/backoffice/bank-accounts?sort=createdAt&limit=2"
    Then the response status code should be 200
    And the JSON node "data" should have 2 elements
    And the JSON node "data[0].id" should be equal to "33333333-3333-7000-8000-000000000001"
    And the JSON node "data[1].id" should be equal to "33333333-3333-7000-8000-000000000002"
    And the JSON node "pagination.hasNext" should be true
    And the JSON node "pagination.hasPrev" should be false
    And the JSON node "pagination.links.next" should not be null
    And I follow the "pagination.links.next" link from the previous response
    And the response status code should be 200
    And the JSON node "data" should have 2 elements
    And the JSON node "data[0].id" should be equal to "33333333-3333-7000-8000-000000000003"
    And the JSON node "data[1].id" should be equal to "33333333-3333-7000-8000-000000000004"
    And the JSON node "pagination.hasNext" should be true
    And the JSON node "pagination.hasPrev" should be true
    And I follow the "pagination.links.next" link from the previous response
    And the response status code should be 200
    And the JSON node "data" should have 1 elements
    And the JSON node "data[0].id" should be equal to "33333333-3333-7000-8000-000000000005"
    And the JSON node "pagination.hasNext" should be false
    And the JSON node "pagination.hasPrev" should be true
    And 6 requests got executed only for doctrine connection "default"

  # Cross-route scope guard: the collection cursor's fingerprint is bound to the collection's base
  # query (no base WHERE, JOIN Bank). Replayed on a bank's nested route — same sort and limit, but a
  # divergent base predicate (WHERE ba.bankId) — the recomputed fingerprint no longer matches, so it is
  # rejected as 422 invalid-cursor rather than paginating a foreign scope. Same route path would page fine.
  Scenario: A cursor minted on the collection is rejected on a bank's nested accounts route
    When I send a "GET" request to "/backoffice/bank-accounts?sort=createdAt&limit=2"
    Then the response status code should be 200
    And the JSON node "pagination.links.next" should not be null
    And I follow the "pagination.links.next" link from the previous response rebased onto "/backoffice/banks/11111111-1111-7000-8000-000000000004/accounts"
    And the response status code should be 422
    And the header "Content-Type" should be equal to "application/problem+json"
    And the JSON node "type" should be equal to "invalid-cursor"
    And the JSON node "status" should be equal to the number 422

  Scenario: A filter matching no account returns an empty, single-query page
    When I send a "GET" request to "/backoffice/bank-accounts?filters[0][field]=holderName&filters[0][operator]=contains&filters[0][value]=nonexistentholder"
    Then the response status code should be 200
    And the JSON node "data" should have 0 elements
    And the JSON node "pagination.hasNext" should be false
    And the JSON node "pagination.hasPrev" should be false
    And the JSON node "pagination.links.next" should be null
    And the JSON node "pagination.links.prev" should be null
    And 2 request got executed only for doctrine connection "default"

  # Semantic 422 from the applier: a sort field outside the allow-list (holderName/createdAt/updatedAt)
  # is rejected before any SQL runs — the field is never interpolated into DQL.
  Scenario: Sorting by a field outside the sort allow-list returns 422 unknown-sort-field
    When I send a "GET" request to "/backoffice/bank-accounts?sort=id&direction=ASC"
    Then the response status code should be 422
    And the header "Content-Type" should be equal to "application/problem+json"
    And the header "Cache-Control" should contain "no-store"
    And the JSON node "type" should be equal to "unknown-sort-field"
    And the JSON node "title" should be equal to "Unknown sort field."
    And the JSON node "status" should be equal to the number 422
    And the JSON node "field" should be equal to "id"
    And the JSON node "instance" should be a valid UUID
    And the JSON node "correlation-id" should be a valid UUID
    And 0 requests got executed across all doctrine connections

  # status and currency are deliberately NOT filterable (only holderName/iban/alias + bankId): a filter
  # on a field outside the allow-list is a 422 unknown-search-field before any SQL runs.
  Scenario: A filter on a field outside the allow-list returns 422 unknown-search-field
    When I send a "GET" request to "/backoffice/bank-accounts?filters[0][field]=status&filters[0][operator]=eq&filters[0][value]=ACTIVE"
    Then the response status code should be 422
    And the header "Content-Type" should be equal to "application/problem+json"
    And the header "Cache-Control" should contain "no-store"
    And the JSON node "type" should be equal to "unknown-search-field"
    And the JSON node "title" should be equal to "Unknown search field."
    And the JSON node "status" should be equal to the number 422
    And the JSON node "field" should be equal to "status"
    And the JSON node "instance" should be a valid UUID
    And the JSON node "correlation-id" should be a valid UUID
    And 0 requests got executed across all doctrine connections

  # A malformed uuid bound against the bankId UUID column surfaces as input error (the field map marks
  # bankId requiresUuidValues), never a Postgres 22P02 turned 500.
  Scenario: A bankId filter with a malformed uuid returns 422 invalid-search-value
    When I send a "GET" request to "/backoffice/bank-accounts?filters[0][field]=bankId&filters[0][operator]=eq&filters[0][value]=not-a-uuid"
    Then the response status code should be 422
    And the header "Content-Type" should be equal to "application/problem+json"
    And the header "Cache-Control" should contain "no-store"
    And the JSON node "type" should be equal to "invalid-search-value"
    And the JSON node "title" should be equal to "Search value must be a valid UUID."
    And the JSON node "status" should be equal to the number 422
    And the JSON node "field" should be equal to "bankId"
    And the JSON node "position" should be equal to the number 0
    And the JSON node "instance" should be a valid UUID
    And the JSON node "correlation-id" should be a valid UUID
    And 0 requests got executed across all doctrine connections

  # iban is canonicalized on write (upper-case, spaces stripped); the field normalizer applies the same
  # rule, so a human-grouped, lower-case fragment still matches the stored compact form (not a silent
  # empty page). Only Globex's German IBAN contains "DE893704".
  Scenario: A space-formatted lower-case IBAN fragment still matches via the canonicalizing normalizer
    When I send a "GET" request to "/backoffice/bank-accounts?filters[0][field]=iban&filters[0][operator]=contains&filters[0][value]=de89%203704"
    Then the response status code should be 200
    And the JSON node "data" should have 1 elements
    And the JSON node "data[0].holderName" should be equal to "Globex Corporation"
    And the JSON node "data[0].iban" should be equal to "DE89370400440532013000"
    And the JSON node "data[0].bankName" should be equal to "JPMorgan Chase"
    And 2 request got executed only for doctrine connection "default"

  # Pagination mode is validated at DTO mapping time (shape), inherited from the shared SearchQuery — an
  # unknown token is a 422 validation-failed before any SQL runs.
  Scenario: An unknown pagination mode returns 422 validation-failed
    When I send a "GET" request to "/backoffice/bank-accounts?paginationMode=unknownPaginationMode"
    Then the response status code should be 422
    And the header "Content-Type" should be equal to "application/problem+json"
    And the JSON node "type" should be equal to "validation-failed"
    And the JSON node "status" should be equal to the number 422
    And the JSON node "violations[0].field" should be equal to "paginationMode"
    And 0 requests got executed across all doctrine connections
