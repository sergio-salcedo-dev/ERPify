Feature: Dead-letter queue status command
  In order to monitor the Messenger failure transport from the console
  As an operator
  I need "messenger:failed:status" to report the queue and reject bad input

  Scenario: It reports the dead-letter queue and succeeds
    When I run the "messenger:failed:status" command
    Then the last command should succeed
    And the command output should contain "dead-letter queue"

  Scenario: --json emits a machine-readable summary
    When I run the "messenger:failed:status" command with options:
    """
    {"--json": true}
    """
    Then the last command should succeed
    And the command output should contain "total"

  Scenario: A non-positive --limit is rejected
    When I run the "messenger:failed:status" command with parameters:
      | --limit | 0 |
    Then the last command should fail
    And the command output should contain "--limit must be a positive integer"
