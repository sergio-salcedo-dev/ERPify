<?php

declare(strict_types=1);

namespace Erpify\Iam\Session\Domain;

use Erpify\Shared\Uuid\Domain\Uuid;

/**
 * The domain identity of a {@see Entity\Session} — a UUID v7 the application mints,
 * held apart from the framework's own session id. This value object is the type the backend-substitutability seam
 * speaks: the correlation port ({@see \Erpify\Iam\Session\Application\CurrentSessionReference}), the repository
 * lookup and the minting use case all pass a {@see SessionId}, never a raw framework session token, so swapping
 * the session backend never reaches the domain or the use cases.
 *
 * Construction validates: {@see fromString()} guards a well-formed UUID at the edge (the correlation is
 * server-written, but a malformed value must surface as a clean domain error, never a malformed query), and
 * {@see generate()} is the single v7 mint site for a new session.
 */
final readonly class SessionId
{
    private function __construct(private string $value)
    {
    }

    public static function generate(): self
    {
        return new self(Uuid::generate());
    }

    /**
     * @throws \Erpify\Shared\Uuid\Domain\InvalidUuidException when the value is not a well-formed UUID
     */
    public static function fromString(string $value): self
    {
        Uuid::ensure($value);

        return new self($value);
    }

    public function toString(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
