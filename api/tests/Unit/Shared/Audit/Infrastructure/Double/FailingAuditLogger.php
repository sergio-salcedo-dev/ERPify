<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Audit\Infrastructure\Double;

use Erpify\Shared\Audit\Application\AuditLogger;
use Erpify\Shared\Audit\Domain\AuditLevel;
use Erpify\Shared\Audit\Domain\AuditResource;
use Override;
use RuntimeException;
use Throwable;

/**
 * An {@see AuditLogger} whose {@see log()} always throws, standing in for a self-audit write that fails
 * after a real erasure already committed — so a test can assert the command surfaces the gap rather than
 * reporting success.
 *
 * @internal
 */
final readonly class FailingAuditLogger implements AuditLogger
{
    /**
     * Accepts the instance to throw so a caller that swallows the failure can be asserted to have carried
     * *that* throwable into its log context — an assertion on class and message alone would still pass if the
     * `catch` block logged one it had constructed itself.
     */
    public function __construct(private ?Throwable $failure = null)
    {
    }

    /**
     * @param array<string, mixed> $metadata
     */
    #[Override]
    public function log(string $action, AuditLevel $level, ?AuditResource $resource = null, array $metadata = []): void
    {
        throw $this->failure ?? new RuntimeException('audit write failed');
    }
}
