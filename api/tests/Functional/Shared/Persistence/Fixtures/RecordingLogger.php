<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Shared\Persistence\Fixtures;

use Override;
use Psr\Log\AbstractLogger;
use Stringable;

/**
 * Spy PSR-3 logger capturing each record's level, message and context, so a persistence test can assert what a
 * containment reported without reading a Monolog handler.
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
