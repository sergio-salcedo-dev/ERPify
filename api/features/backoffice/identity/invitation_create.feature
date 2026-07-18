Feature: Invite a member from the console
  As an administrator
  In order to onboard people without minting credentials directly
  I need one authenticated alta that provisions an INVITED identity, emails the accept link, and refuses
  anyone but an administrator or a malformed payload before it ever touches the domain

  Background:
    Given I add "Content-Type" header equal to "application/json"
    And I add "Accept" header equal to "application/json"

  Scenario: An administrator invites a new member and the identity is provisioned INVITED
    Given I am logged in as an administrator
    And the stored events are cleared
    And I reset the stats for all doctrine connections
    When I send a POST request to "/backoffice/invitations" with body:
    """
    {
      "email": "newbie@erpify.test",
      "roles": ["EDITOR"]
    }
    """
    Then the response status code should be 201
    And the response should be empty
    And I execute the SQL query "SELECT id FROM identity_user WHERE email = 'newbie@erpify.test' AND status = 'INVITED'"
    And there should have 1 records in SQL result
    # Both invitation events land on the outbox inside the same transaction that persists the aggregate; the
    # plaintext accept token rides only in the email, never in an event.
    And there should be 1 event stored named "erpify.iam.invitation.created"
    And there should be 1 event stored named "erpify.iam.invitation.sent"
    # The token email is synchronous best-effort post-commit (never a transport), captured by the recorder.
    And 1 notification email was sent
    And The notification email subject should be equal to "Your ERPify invitation"
    And The notification email recipient should be "newbie@erpify.test"
    # The wrapped write's query budget (auth/admission lookups are excluded from the counter): the atomic
    # onboarding funnels through three contexts and the audit listener writes a regulatory change row per
    # aggregate on the same transaction, so the count is higher than a single-table create.
    And 26 requests got executed only for doctrine connection "default"

  Scenario: A non-administrator is refused the alta with 403 and writes nothing
    Given I am logged in as a viewer
    And the stored events are cleared
    And I reset the stats for all doctrine connections
    When I send a POST request to "/backoffice/invitations" with body:
    """
    {
      "email": "blocked-invite@erpify.test",
      "roles": ["EDITOR"]
    }
    """
    Then the response status code should be 403
    And the header "Content-Type" should contain "application/problem+json"
    And the JSON node "type" should be equal to "forbidden"
    And there should be 0 events stored named "erpify.iam.invitation.created"
    And 0 notification emails were sent
    And I execute the SQL query "SELECT id FROM identity_user WHERE email = 'blocked-invite@erpify.test'"
    And there should have 0 records in SQL result

  Scenario Outline: A malformed alta is refused 422 before the domain, with no write
    Given I am logged in as an administrator
    And the stored events are cleared
    And I reset the stats for all doctrine connections
    When I send a POST request to "/backoffice/invitations" with body:
    """
    <body>
    """
    Then the response status code should be 422
    And the header "Content-Type" should contain "application/problem+json"
    And the JSON node "type" should be equal to "validation-failed"
    And there should be 0 events stored named "erpify.iam.invitation.created"
    And 0 notification emails were sent

    Examples:
      | case         | body                                                    |
      | bad email    | { "email": "not-an-email", "roles": ["EDITOR"] }        |
      | empty roles  | { "email": "candidate@erpify.test", "roles": [] }       |
      | unknown role | { "email": "candidate@erpify.test", "roles": ["ROOT"] } |

  Scenario: Re-inviting an email already in use is refused 422 with no partial write
    Given I am logged in as an administrator
    And the stored events are cleared
    And I reset the stats for all doctrine connections
    When I send a POST request to "/backoffice/invitations" with body:
    """
    {
      "email": "admin@erpify.test",
      "roles": ["VIEWER"]
    }
    """
    Then the response status code should be 422
    And the JSON node "type" should be equal to "validation-failed"
    And the JSON node "violations[0].message" should be equal to "This email is already in use."
    And there should be 0 events stored named "erpify.iam.invitation.created"
    And 0 notification emails were sent
