Feature: Serve the canonical bytes of an image by its identity
  As a bounded context consuming the shared image seam
  In order to render an image without knowing where or how it is stored
  I need an authenticated route that answers with the canonical bytes, supports conditional caching, and
  never lets the knowledge of an identifier stand in for a session

  # This is an infrastructure proof rather than a product API: it establishes that bytes are retrievable
  # across the module boundary. It grants no ownership and decides no semantic authorization — any
  # authenticated caller may read any image, because the module holds no owner and cannot tell a company
  # logo from a person's avatar.
  #
  # Two properties here are invisible to every other gate and are the reason this file exists. The route
  # name governs the generic activity audit: `AuditPolicy` records every successful GET under /api/ unless
  # the name matches one of five non-business shapes, so the epic's "zero audit rows" decision is a property
  # of the string `shared_image_get` and of nothing else. And the 304 is gated on a full verified read, not
  # on an existence predicate, which is what keeps a viewer from being told its stale copy is still good
  # after the bytes are gone.
  Background:
    Given I add "Accept" header equal to "application/json"

  @anonymous
  Scenario Outline: Knowing an identifier never substitutes for a session
    # The shapes an unauthenticated caller can ask about must be indistinguishable: malformed and absent
    # here, and one that really exists in the scenario below, which needs a fixture and so cannot be a row
    # of this table. The firewall answers before anything is resolved, so none of them reveals whether the
    # image is there. Asserted on status and on the problem `type`/`title` rather
    # than on the whole body: `instance` carries the identifier that was asked for and `correlation-id` is
    # per-request, so literal equality would be unsatisfiable rather than strict.
    When I send a "GET" request to "/images/<identifier>"
    Then the response status code should be 401
    And the header "Content-Type" should contain "application/problem+json"
    And the JSON node "type" should be equal to "unauthenticated"
    And the JSON node "title" should be equal to "Authentication required."

    Examples:
      | identifier                           |
      | not-a-uuid                           |
      | 019831b7-0000-7000-8000-00000000dead |

  @anonymous
  Scenario: An image that really exists is withheld from a caller with no session
    # The row above asks about identifiers with nothing behind them, so it cannot tell "the firewall refused"
    # apart from "there was nothing to give". This one seeds the bytes first: the 401 is then a refusal to
    # serve something that is genuinely there, which is the claim the route makes and the only shape of it
    # that can fail. Asserted on the body too — a 401 carrying the image would still be a 401.
    Given there is a stored image with its canonical bytes
    When I send a "GET" request for that image
    Then the response status code should be 401
    And the JSON node "type" should be equal to "unauthenticated"
    And the header "Content-Type" should not contain "image/webp"

  @anonymous
  Scenario: An extra query parameter does not turn the route anonymous
    When I send a "GET" request to "/images/019831b7-0000-7000-8000-00000000dead?download=1"
    Then the response status code should be 401
    And the JSON node "type" should be equal to "unauthenticated"

  Scenario: A malformed identifier is a request-target error, not a missing image
    # It reaches the controller on purpose: a `requirements` pattern on the route would have the router
    # answer 404 and conflate "you asked wrongly" with "there is nothing there".
    Given I am logged in as an administrator
    When I send a "GET" request to "/images/not-a-uuid"
    Then the response status code should be 400
    And the JSON node "type" should be equal to "invalid-uuid"

  Scenario: A well-formed identifier with no image behind it is a 404
    Given I am logged in as an administrator
    When I send a "GET" request to "/images/019831b7-0000-7000-8000-00000000dead"
    Then the response status code should be 404
    And the header "Content-Type" should contain "application/problem+json"
    And the JSON node "type" should be equal to "not-found"

  Scenario: A row whose bytes are gone answers the same 404 as a row that never existed
    # From outside they are one fact: nothing can be served under this identifier. Distinguishing them would
    # report this deployment's internal state to a caller who is owed no such thing.
    Given I am logged in as an administrator
    And there is an image row whose canonical bytes are gone
    When I send a "GET" request for that image
    Then the response status code should be 404
    And the JSON node "type" should be equal to "not-found"

  Scenario: A stored image is served with the canonical media type of its row
    Given I am logged in as an administrator
    And there is a stored image with its canonical bytes
    When I send a "GET" request for that image
    Then the response status code should be 200
    And the header "Content-Type" should be equal to "image/webp"
    And the header "Content-Length" should be equal to "27"
    And the header "X-Content-Type-Options" should be equal to "nosniff"

  Scenario: The response is privately cacheable for an hour and carries a strong validator
    # Asserted as directives present rather than as a whole string, for two measured reasons: the header bag
    # serialises cache directives alphabetically, and the stateful firewall's session listener rewrites the
    # header on kernel.response, adding `must-revalidate` and an `Expires`. That rewrite is accepted —
    # `immutable` governs the fresh phase and `must-revalidate` the stale one, so they compose.
    Given I am logged in as an administrator
    And there is a stored image with its canonical bytes
    When I send a "GET" request for that image
    Then the response status code should be 200
    And the header "Cache-Control" should contain "private"
    And the header "Cache-Control" should contain "max-age=3600"
    And the header "Cache-Control" should contain "immutable"
    And the header "Cache-Control" should not contain "public"
    And the header "ETag" should match "/^\x22[0-9a-f]{64}\x22$/"

  Scenario: A conditional request whose validator still matches is answered 304 with its own headers
    # `setNotModified()` keeps what is already on the response and strips the entity headers, so a 304 built
    # on a bare response would carry neither validator nor freshness — satisfying every other rule here and
    # leaving the client nothing to send back next time, which breaks the very loop this pays a full read to
    # sustain. The validator is echoed rather than written out: a literal digest would pin an incidental
    # property of the fixture into the acceptance contract.
    Given I am logged in as an administrator
    And there is a stored image with its canonical bytes
    When I send a "GET" request for that image
    Then the response status code should be 200
    And I add "If-None-Match" header equal to the response header "ETag"
    And I send a "GET" request for that image
    And the response status code should be 304
    And the header "ETag" should match "/^\x22[0-9a-f]{64}\x22$/"
    And the header "Cache-Control" should contain "max-age=3600"
    And the header "Content-Type" should not exist

  Scenario: A conditional request with somebody else's validator is answered in full
    Given I am logged in as an administrator
    And there is a stored image with its canonical bytes
    And I add "If-None-Match" header equal to "0000000000000000000000000000000000000000000000000000000000000000"
    When I send a "GET" request for that image
    Then the response status code should be 200
    And the header "Content-Type" should be equal to "image/webp"

  Scenario: A Range header is ignored and no partial-content capability is advertised
    # Announcing a capability this slice does not implement is worse than staying silent about it, so there
    # is no `Accept-Ranges` either. `Last-Modified` is absent for the same reason: `createdAt` exists, and
    # emitting it would open a second validation axis nothing here maintains.
    Given I am logged in as an administrator
    And there is a stored image with its canonical bytes
    And I add "Range" header equal to "bytes=0-3"
    When I send a "GET" request for that image
    Then the response status code should be 200
    And the header "Content-Length" should be equal to "27"
    And the header "Accept-Ranges" should not exist
    And the header "Last-Modified" should not exist

  Scenario: An upper-cased identifier serves the same representation as its lower-cased form
    # A UUID is case-insensitive as a value and case-sensitive as a string. The module reconciles the two in
    # the identifier's constructor, and this asserts the route inherits that rather than sidestepping it —
    # the direction that once reported a confirmed erasure over bytes it had stranded for ever.
    Given I am logged in as an administrator
    And there is a stored image with its canonical bytes
    When I send a "GET" request for that image
    Then the response status code should be 200
    And I add "If-None-Match" header equal to the response header "ETag"
    And I send a "GET" request for that image with an upper-cased identifier
    And the response status code should be 304

  Scenario: The least privileged session reads an image exactly like the most privileged one
    # Every other authenticated scenario here opens as an administrator, which is the one session that would
    # satisfy an `#[IsGranted]` added by accident — so the whole feature would stay green while the route
    # quietly stopped being readable by anybody else. The epic decides that a session is the entire
    # authorization story for this slice; this is the row that can fail if that stops being true.
    Given I am logged in as a viewer
    And there is a stored image with its canonical bytes
    When I send a "GET" request for that image
    Then the response status code should be 200
    And the header "Content-Type" should be equal to "image/webp"

  Scenario: A successful read leaves the activity trail untouched
    # The generic activity audit is opt-out and keyed on the ROUTE NAME. Counted before and after, because a
    # table that happens to be empty proves nothing at all.
    Given I am logged in as an administrator
    And there is a stored image with its canonical bytes
    When I execute the SQL query "SELECT id FROM audit_log"
    Then there should have 0 records in SQL result
    And I send a "GET" request for that image
    And the response status code should be 200
    And I execute the SQL query "SELECT id FROM audit_log"
    And there should have 0 records in SQL result

  Scenario: A validator that still matches is refused once the object is no longer retrievable
    # The gate on a 304 is the verified read itself, not an existence predicate: the storage port has none,
    # and adding one would reopen a decision it settled — its internal check RAISES when existence cannot be
    # established, which is what keeps a permission fault from being reported as an erasure. So a 304 costs
    # the same I/O and the same SHA-256 as a 200 and saves only the body. What it buys is this scenario: a
    # viewer holding a still-current validator is told the image is gone rather than that its copy is fresh.
    Given I am logged in as an administrator
    And there is a stored image with its canonical bytes
    When I send a "GET" request for that image
    Then the response status code should be 200
    And I add "If-None-Match" header equal to the response header "ETag"
    And the canonical bytes of that image are removed from storage
    And I send a "GET" request for that image
    And the response status code should be 404
    And the JSON node "type" should be equal to "not-found"
