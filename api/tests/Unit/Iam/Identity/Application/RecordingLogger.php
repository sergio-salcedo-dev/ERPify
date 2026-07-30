<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Application;

use Override;
use Psr\Log\AbstractLogger;
use Stringable;

/**
 * Captures log records so a best-effort collaborator's swallow-and-log contract can be asserted. Swallowing
 * without logging is an effect that silently did not happen, and a {@see \Psr\Log\NullLogger} cannot tell the
 * two apart.
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
    public function log(mixed $level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
    }
}
