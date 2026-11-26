<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Audit\Application;

use Erpify\Shared\Audit\Application\AuditLogger;
use Erpify\Shared\Audit\Domain\AuditLevel;
use Erpify\Shared\Audit\Domain\AuditResource;
use Override;
use RuntimeException;

/**
 * A logger that reports into a {@see TransactionTrace} when it runs, and fails on demand.
 *
 * @internal
 *
 * @SuppressWarnings("PHPMD.UnusedFormalParameter") the interface's `$resource`; the double records the rest
 */
final readonly class TracingAuditLogger implements AuditLogger
{
    public function __construct(
        private TransactionTrace $trace,
        private ?RuntimeException $failure = null,
    ) {
    }

    #[Override]
    public function log(string $action, AuditLevel $level, ?AuditResource $resource = null, array $metadata = []): void
    {
        $this->trace->record('log');

        if ($this->failure instanceof RuntimeException) {
            throw $this->failure;
        }

        $this->trace->logged[] = ['action' => $action, 'level' => $level, 'metadata' => $metadata];
    }
}
