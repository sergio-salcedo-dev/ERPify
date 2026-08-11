Feature: A docstring fenced with backticks, which Behat's lexer opens exactly like three double quotes

  Scenario: Prose inside the fence is not a step
    Given I am logged in as an admin
    When I send a "POST" request to "/backoffice/banks" with body:
    ```
    Then this line is prose inside a fenced docstring
    And so is this one
    ```
    Then the response status code should be 201
