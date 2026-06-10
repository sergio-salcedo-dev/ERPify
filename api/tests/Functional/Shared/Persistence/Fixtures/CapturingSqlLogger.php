<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Shared\Persistence\Fixtures;

use Override;
use Psr\Log\AbstractLogger;
use Stringable;

/**
 * Test-only PSR-3 logger that records the SQL + bound parameters of every "Executing statement"
 * message emitted by the DBAL logging middleware, so the keyset SQL snapshot test can pin the
 * engine's compiled read-path SQL without reaching into any Doctrine object.
 */
final class CapturingSqlLogger extends AbstractLogger
{
    /** @var list<array{sql: string, params: array<int|string, mixed>}> */
    public array $records = [];

    public function reset(): void
    {
        $this->records = [];
    }

    /**
     * @param array<array-key, mixed> $context
     *
     * @SuppressWarnings("PHPMD.UnusedFormalParameter")
     */
    #[Override]
    public function log(mixed $level, string|Stringable $message, array $context = []): void
    {
        $sql = $context['sql'] ?? null;

        if (\is_string($sql) && \str_contains((string) $message, 'Executing statement')) {
            $params = $context['params'] ?? null;
            $this->records[] = ['sql' => $sql, 'params' => \is_array($params) ? $params : []];
        }
    }
}
