<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Infrastructure\Http;

use Erpify\Shared\Audit\Application\AuditLogger;
use Erpify\Shared\Audit\Domain\AuditLevel;
use Erpify\Shared\Audit\Domain\AuditResource;
use Override;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Records what the request stack held AT THE MOMENT OF THE WRITE, which is the only thing that can tell a
 * correctly deferred projection from a silently degraded one.
 *
 * `kernel.terminate` runs after the kernel popped the request, so an emission that does not re-establish it
 * still writes a row — with a `system` actor, a fresh correlation id and no ip or user-agent. Every assertion
 * about the row's *content* passes; only an observation taken inside the call can see the difference.
 *
 * @internal
 */
final class RequestObservingAuditLogger implements AuditLogger
{
    /** @var list<string|null> path info of the request current at each write, or null if none was. */
    public array $requestPathsAtWrite = [];

    public function __construct(private readonly RequestStack $requestStack)
    {
    }

    #[Override]
    public function log(string $action, AuditLevel $level, ?AuditResource $resource = null, array $metadata = []): void
    {
        $this->requestPathsAtWrite[] = $this->requestStack->getCurrentRequest()?->getPathInfo();
    }
}
