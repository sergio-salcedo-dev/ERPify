Feature: Dead-letter queue status command
  In order to monitor the Messenger failure transport from the console
  As an operator
  I need "messenger:failed:status" to report the queue and reject bad input

  Scenario: It reports the dead-letter queue and succeeds
    When I run the "messenger:failed:status" command
    Then the last run should succeed
    And the last run output should contain "dead-letter queue"

  Scenario: --json emits a machine-readable summary
    When I run the "messenger:failed:status" command with options:
    """
    {"--json": true}
    """
    Then the last run should succeed
    And the last run output should be JSON with a "total" field

  Scenario: A positive numeric --limit is accepted
    When I run the "messenger:failed:status" command with options:
    """
    {"--limit": 5}
    """
    Then the last run should succeed

  Scenario: A non-positive --limit is rejected
    When I run the "messenger:failed:status" command with parameters:
      | --limit | 0 |
    Then the last run should fail
    And the last run output should contain "--limit must be a positive integer"
