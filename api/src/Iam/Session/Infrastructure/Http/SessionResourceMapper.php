<?php

declare(strict_types=1);

namespace Erpify\Iam\Session\Infrastructure\Http;

use DateTimeInterface;
use Erpify\Iam\Session\Application\Resource\SessionResource;
use Erpify\Iam\Session\Domain\Entity\Session;
use Erpify\Iam\Session\Domain\SessionId;

/**
 * Maps a {@see Session} aggregate to the {@see SessionResource} wire contract, flagging the one that matches the
 * request's current correlation as `current` so "my sessions" can distinguish this device.
 */
final readonly class SessionResourceMapper
{
    public function toResource(Session $session, SessionId $currentSessionId): SessionResource
    {
        $id = $session->getId() ?? '';

        return new SessionResource(
            id: $id,
            device: $session->device(),
            createdAt: $session->getCreatedAt()->format(DateTimeInterface::ATOM),
            current: $id === $currentSessionId->toString(),
        );
    }
}
