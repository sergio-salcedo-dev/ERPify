# Testing

- Add tests whenever relevant and appropriate
- Use PHPUnit for unit tests and integration tests in PHP
- Use Behat for behavior-driven development (BDD) and acceptance tests
- Use Jest for JavaScript/TypeScript testing
- Write tests before or alongside code (TDD/BDD when applicable)
- Maintain high test coverage for critical business logic
- Tests should be fast, independent, and repeatable

## Testing Best Practices
- Write tests for critical business logic
- Aim for high test coverage on important code paths
- Write unit tests that are fast and isolated
- Use integration tests for testing component interactions
- Write tests that are easy to understand and maintain
- Follow AAA pattern (Arrange, Act, Assert)
- Use test doubles appropriately, named per the convention below

## Test double naming convention

Name **ports by capability** and **implementations by technology/strategy** — the same naming axis holds in `src/` and `tests/`, so a port's production and test adapters read as siblings.

| Role | Convention | Example |
| --- | --- | --- |
| Port (capability) | `<Capability>` | `BankAccountCounter`, `BankRepository`, `BankExistenceChecker` |
| Production adapter | `<Technology><Port>` | `DoctrineBankAccountCounter`, `DoctrineBankRepository` |
| Test double that is an in-memory implementation of the port | `InMemory<Port>` | `InMemoryBankAccountCounter`, `InMemoryBankRepository` |
| Test double that is a test-double pattern, not a port implementation | `Spy*` / `Stub*` / `Dummy*` | `StubDriverException`, `SpyMailer`, `StubClock` |

- An in-memory test implementation of a port is `InMemory<Port>`, never `Fake<Port>`: it stays symmetric with the `Doctrine<Port>` adapter and states *how* it works rather than the uninformative "fake".
- An in-memory double that also records the calls it received still uses `InMemory<Port>` — the implementation nature dominates the incidental spying.
- Reserve `Spy*` / `Stub*` / `Dummy*` for doubles that embody a test-double pattern instead of an alternative implementation of a domain port (a stubbed framework exception, a spy mailer, a stub clock).

## Behat step vocabulary

A step definition is a shared asset. Never delete one for being unused, and search the vocabulary before writing a new one — `make php.behat c='-dl'` lists it, `make php.behat c="-d '<text>'"` searches it. When you touch a feature, spend the idle steps that fit it: an assertion that exists and is never made proves nothing. Full rule, current numbers and the debugging-only exception: [`api/CLAUDE.md`](../../api/CLAUDE.md).

## Error Handling in Tests
- Use exceptions for error handling, not return codes
- Create specific exception types for different error scenarios
- Fail fast and fail clearly with meaningful error messages
- Log errors appropriately with context
- Never expose internal implementation details in error messages
