<?php

declare(strict_types=1);

namespace Erpify\Tests\Doctrine;

use Override;
use Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder;
use Symfony\Bridge\Doctrine\Middleware\Debug\Query;

/**
 * Captures executed queries during Behat scenarios so DoctrineContext can
 * make per-connection assertions. Static state is intentional: FoB's
 * SymfonyExtension boots a separate test container, and queries executed
 * inside the request kernel must remain visible to the assertion-side
 * container.
 *
 * @phpstan-type StoredQueryRecord array{
 *     sql: string,
 *     params: array<int|string, mixed>,
 *     types: array<int|string, mixed>,
 *     executionMS: float|null|(callable(): (float|null)),
 * }
 * @phpstan-type ResolvedQueryRecord array{
 *     sql: string,
 *     params: array<int|string, mixed>,
 *     types: array<int|string, mixed>,
 *     executionMS: float|null,
 *     backtrace?: array<int, array<string, mixed>>,
 * }
 */
class TestDebugDataHolder extends DebugDataHolder
{
    private const array INCLUDED_CLASSES = [
        \Symfony\Component\EventDispatcher\EventDispatcher::class,
        \Symfony\Component\Messenger\Command\ConsumeMessagesCommand::class,
    ];

    private const array EXCLUDED_CLASSES = [
        'DAMA\DoctrineTestBundle\Doctrine\DBAL\PostConnectEventListener',
    ];

    /** @var array<string, array<int, StoredQueryRecord>> */
    private static array $data = [];

    /** @var array<string, array<int, array<int, array<string, mixed>>>> */
    private static array $backtraces = [];

    #[Override]
    public function reset(): void
    {
        self::$data = [];
        self::$backtraces = [];
    }

    /**
     * @SuppressWarnings("PHPMD.BooleanArgumentFlag")
     */
    #[Override]
    public function addQuery(string $connectionName, Query $query, bool $force = false): void
    {
        $backtraces = \debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);

        if (!$force && !$this->shouldLog($backtraces)) {
            return;
        }

        self::$data[$connectionName][] = [
            'sql' => $query->getSql(),
            'params' => $query->getParams(),
            'types' => $query->getTypes(),
            // stop() may not have been called when DebugMiddleware records the query;
            // store the duration callable and resolve it lazily in getData().
            'executionMS' => $query->getDuration(...),
        ];

        // array_slice(2) drops this method + DebugMiddleware's invoker frame from the trace.
        self::$backtraces[$connectionName][] = \array_slice($backtraces, 2);
    }

    /**
     * @return array<string, array<int, ResolvedQueryRecord>>
     */
    #[Override]
    public function getData(): array
    {
        $resolved = [];

        foreach (self::$data as $connectionName => $dataForConn) {
            $resolved[$connectionName] = [];

            foreach ($dataForConn as $idx => $record) {
                $executionMS = $record['executionMS'];

                if (\is_callable($executionMS)) {
                    $executionMS = $executionMS();
                }

                $resolvedRecord = [
                    'sql' => $record['sql'],
                    'params' => $record['params'],
                    'types' => $record['types'],
                    'executionMS' => $executionMS,
                ];

                if (isset(self::$backtraces[$connectionName][$idx])) {
                    $resolvedRecord['backtrace'] = self::$backtraces[$connectionName][$idx];
                }

                $resolved[$connectionName][] = $resolvedRecord;
            }
        }

        return $resolved;
    }

    /**
     * @param array<int, array<string, mixed>> $backtraces
     *
     * @SuppressWarnings("PHPMD.CyclomaticComplexity")
     */
    private function shouldLog(array $backtraces): bool
    {
        if ([] === $backtraces) {
            return true;
        }

        // Frames without a 'class' (top-level functions) are silently dropped here;
        // they cannot match any of the include/exclude/suffix predicates anyway.
        $seen = [];

        foreach ($backtraces as $backtrace) {
            $class = $backtrace['class'] ?? null;

            if (\is_string($class)) {
                $seen[$class] = true;
            }
        }

        foreach (\array_keys($seen) as $class) {
            if (\in_array($class, self::EXCLUDED_CLASSES, true)) {
                return false;
            }

            if (\in_array($class, self::INCLUDED_CLASSES, true)) {
                return true;
            }

            if ($this->isSkippedClass($class)) {
                continue;
            }

            if ($this->hasAppSuffix($class) || $this->isInControllerNamespace($class)) {
                return true;
            }
        }

        return false;
    }

    private function isSkippedClass(string $class): bool
    {
        return \str_starts_with($class, 'Behat')
            || \str_starts_with($class, 'PHPUnit')
            || \str_starts_with($class, 'Symfony')
            || \str_contains($class, 'OptimizedLoadingFixturesContext');
    }

    private function hasAppSuffix(string $class): bool
    {
        return \str_ends_with($class, 'Controller')
            || \str_ends_with($class, 'ParamConverter')
            || \str_ends_with($class, 'Command')
            || \str_ends_with($class, 'Resolver');
    }

    private function isInControllerNamespace(string $class): bool
    {
        return \str_contains($class, '\Controller\\');
    }
}
