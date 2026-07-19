<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Domain\Event;

use DateTimeImmutable;
use Erpify\Shared\Access\Domain\Role;
use Erpify\Shared\Event\Domain\DomainEvent;
use Erpify\Shared\Event\Domain\Exception\CorruptEventStoreRow;
use Override;

/**
 * Records that an identity's whole role set was replaced. Unlike the lifecycle events the payload is not
 * empty: the resulting set IS the fact, and a role is authorization vocabulary rather than PII, so carrying it
 * lets a consumer learn what the identity may now do without re-reading an aggregate that may have moved on.
 * Only the resulting set is recorded, never a grant/revoke delta — the endpoint's semantics are an assignment,
 * and the previous set is already the preceding event's payload. Emitted where the replacement happens because
 * a domain fact is always recorded at its source.
 */
final class UserRolesChanged extends DomainEvent
{
    /**
     * @param list<Role> $roles
     */
    public function __construct(
        string $aggregateId,
        private readonly array $roles,
        ?string $eventId = null,
        ?DateTimeImmutable $occurredOn = null,
    ) {
        parent::__construct($aggregateId, $eventId, $occurredOn);
    }

    #[Override]
    public static function eventName(): string
    {
        return 'erpify.iam.identity.roles-changed';
    }

    #[Override]
    public static function aggregateType(): string
    {
        return 'Iam.Identity';
    }

    /**
     * @return array{roles: list<string>}
     */
    #[Override]
    public function toPrimitives(): array
    {
        return ['roles' => \array_map(static fn (Role $role): string => $role->value, $this->roles)];
    }

    /**
     * @param array<string, mixed> $body
     */
    #[Override]
    public static function fromPrimitives(
        string $aggregateId,
        array $body,
        string $eventId,
        string $occurredOn,
    ): static {
        return new self(
            $aggregateId,
            self::rolesFrom(self::arrayMember($body, 'roles')),
            $eventId,
            new DateTimeImmutable($occurredOn),
        );
    }

    /**
     * @param array<mixed> $values
     *
     * @return list<Role>
     */
    private static function rolesFrom(array $values): array
    {
        return \array_values(\array_map(
            static function (mixed $value): Role {
                if (!\is_string($value)) {
                    throw CorruptEventStoreRow::malformedPayloadMember(self::eventName(), 'roles', 'a list of strings');
                }

                // A stored value outside the vocabulary means the role set was renamed without an upcaster
                // registered for this event's version. Failing loudly is the point: decoding it leniently
                // would replay a smaller role set than was actually granted, inventing a history the log
                // never recorded.
                return Role::from($value);
            },
            $values,
        ));
    }
}
