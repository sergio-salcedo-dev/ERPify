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
    # The roles an invitation grants are recorded against the invited subject, so the console's two delegation
    # paths leave the same kind of evidence: without it, minting a second administrator off the record would be
    # a matter of inviting one rather than promoting one.
    And I execute the SQL query "SELECT id FROM audit_log WHERE action = 'USER_ROLES_GRANTED' AND level = 'security' AND resource_type = 'User' AND metadata = jsonb_build_object('previous_roles', jsonb_build_array(), 'new_roles', jsonb_build_array('EDITOR'))"
    And there should have 1 records in SQL result
    # The wrapped write's query budget (auth/admission lookups are excluded from the counter): the atomic
    # onboarding funnels through three contexts — identity, membership and invitation — and each aggregate's
    # domain events are appended to the event store inside the same transaction, so the count is well above a
    # single-table create. Exactly ONE of these is an `audit_log` write, the role-grant row above, written
    # synchronously because it is `security`; no CDC row joins it, since only Bank and BankAccount are
    # `AuditedEntity` and the generic access hook audits GET.
    And 27 requests got executed only for doctrine connection "default"

  # Handing the invitee ADMIN is a second, narrower act: the controller demands `users.grantAdmin` on top of
  # `users.invite`, but only when the payload actually carries that role. Both permissions are granted to
  # ADMIN and to nothing else — deliberately, since an empty delegation row would make a second administrator
  # impossible to create — so no seeded actor holds one without the other and the REFUSAL cannot be posed
  # here. It is pinned where such an actor can be built: UserPatchRolesFunctionalTest drives the roles
  # endpoint over a policy that withholds the delegation grant, and CreateInvitationControllerTest drives
  # this controller with a denying authorization checker. What this scenario pins is the other half — that
  # the guard is conditional and the grant is real, so legitimate delegation still goes through.
  Scenario: An administrator may invite a second administrator
    Given I am logged in as an administrator
    And the stored events are cleared
    When I send a POST request to "/backoffice/invitations" with body:
    """
    {
      "email": "second-admin@erpify.test",
      "roles": ["ADMIN"]
    }
    """
    Then the response status code should be 201
    And the response should be empty
    # `roles::text LIKE` rather than a jsonb containment test, because the step matcher reads its argument
    # from a double-quoted string and a JSON array literal would have to embed quotes inside it.
    And I execute the SQL query "SELECT id FROM identity_user WHERE email = 'second-admin@erpify.test' AND status = 'INVITED' AND roles::text LIKE '%ADMIN%'"
    And there should have 1 records in SQL result
    And there should be 1 event stored named "erpify.iam.invitation.sent"

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
    And I execute the SQL query "SELECT id FROM identity_user WHERE email = '<email>'"
    And there should have 0 records in SQL result

    # The two object bodies carry a legal role under a key. Every member-level constraint accepts them, so only
    # the list check stands between a JSON object and a variadic spread that would read its keys as named
    # arguments — the "email" key collides with the invitee parameter and would answer 500 instead of 422.
    Examples:
      | case          | email                 | body                                                                                                |
      | bad email     | not-an-email          | { "email": "not-an-email", "roles": ["EDITOR"] }                                                    |
      | empty roles   | candidate@erpify.test | { "email": "candidate@erpify.test", "roles": [] }                                                   |
      | unknown role  | candidate@erpify.test | { "email": "candidate@erpify.test", "roles": ["ROOT"] }                                              |
      | roles object  | candidate@erpify.test | { "email": "candidate@erpify.test", "roles": {"a": "EDITOR"} }                                      |
      | email key     | candidate@erpify.test | { "email": "candidate@erpify.test", "roles": {"email": "EDITOR"} }                                  |
      | over the cap  | candidate@erpify.test | { "email": "candidate@erpify.test", "roles": ["VIEWER","EDITOR","MANAGER","ADMIN","AUDIT_READER","VIEWER"] } |

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
