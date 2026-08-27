@anonymous
Feature: Notify the owner of a locked identity
  As an operator investigating a lockout
  In order to see that a locked identity's owner was actually told, not just that the lock was set
  I need the lockout-notification sweep to record a security audit row when the notice is delivered

  # The trip and the notice are two different claims, projected onto two different audit rows by two
  # different collaborators — see login.feature's own "one security audit row naming the subject" scenario
  # for the trip's USER_LOCKED row. The sweep never runs from a request, so it is driven directly through
  # its handler ("the locked-identity notification sweep runs") rather than waiting on
  # `scheduler_identity_maintenance`'s own clock, the same way identity_integrity.feature drives its own
  # unscheduled maintenance command directly rather than through a trigger this suite cannot control.
  Scenario: A successful notice sweep records a security audit row for the notified identity
    Given I reload the fixtures
    And I execute the SQL query "UPDATE identity_user SET failed_attempts = 10, locked_until = '2099-01-01 00:00:00', lockout_notified_at = NULL WHERE email = 'leo@erpify.test'"
    When the locked-identity notification sweep runs
    Then 1 notification email was sent
    And I get the sent notification email number 1
    And The notification email recipient should be "leo@erpify.test"
    And The notification email subject should be equal to "Your ERPify account has been temporarily locked"
    And I execute the SQL query "SELECT action, level, actor_type, actor_id, resource_type, resource_id FROM audit_log WHERE action = 'ACCOUNT_LOCKOUT_NOTIFIED' AND resource_id = '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a64'"
    And the SQL result as JSON should be:
    """
    [
      {
        "action": "ACCOUNT_LOCKOUT_NOTIFIED",
        "level": "security",
        "actor_type": "system",
        "actor_id": null,
        "resource_type": "User",
        "resource_id": "0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a64"
      }
    ]
    """
