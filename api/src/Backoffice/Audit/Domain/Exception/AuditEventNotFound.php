<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Audit\Domain\Exception;

use Erpify\Shared\ErrorContract\Domain\Exception\DomainException;
use Erpify\Shared\ErrorContract\Domain\Exception\NotFound;

final class AuditEventNotFound extends DomainException implements NotFound
{
    public static function withId(string $id): self
    {
        return new self(
            type: 'audit-event-not-found',
            title: \sprintf('Audit event with id <%s> not found.', $id),
            context: ['auditEventId' => $id],
        );
    }
}
