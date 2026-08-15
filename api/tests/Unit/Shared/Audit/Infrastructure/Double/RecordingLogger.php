<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Audit\Infrastructure\Double;

use Override;
use Psr\Log\AbstractLogger;
use Stringable;

/**
 * Spy PSR-3 logger capturing each record's level, message and context, so a test can assert the
 * activity best-effort branch logs exactly one record, at the level production delivers, carrying only
 * safe keys.
 *
 * @internal
 */
final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{level: mixed, message: string, context: array<array-key, mixed>}> */
    public array $records = [];

    /**
     * @param array<array-key, mixed> $context
     */
    #[Override]
    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
    }
}
