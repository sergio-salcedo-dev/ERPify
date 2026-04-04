# DDD & Clean Architecture Rules

- **Domain Layer**: Pure logic, no external dependencies. Use Value Objects and Entities.
- **Application Layer**: Use cases. Orchestrates domain.
- **Infrastructure Layer**: Implementation of domain interfaces (repositories, API clients).
- **Dependency Inversion**: High-level modules should not depend on low-level modules. Both should depend on abstractions.
- **Encapsulation**: Keep logic internal to the context.
- **Shared Kernel**: Common utilities, base classes, and shared value objects.
