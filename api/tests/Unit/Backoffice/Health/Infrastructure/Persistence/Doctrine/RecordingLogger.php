<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Backoffice\Health\Infrastructure\Persistence\Doctrine;

use Psr\Log\AbstractLogger;
use Stringable;

/**
 * @internal
 */
final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{mixed, string, array<mixed>}> */
    public array $records = [];

    /**
     * @param array<mixed> $context
     */
    public function log(mixed $level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = [$level, (string) $message, $context];
    }
}
