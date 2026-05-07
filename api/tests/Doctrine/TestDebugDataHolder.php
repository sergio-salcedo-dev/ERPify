<?php

declare(strict_types=1);

namespace Erpify\Tests\Doctrine;

use Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder;
use Symfony\Bridge\Doctrine\Middleware\Debug\Query;

/**
 * Captures executed queries during Behat scenarios so DoctrineContext can
 * make per-connection assertions. Static state is intentional: FoB's
 * SymfonyExtension boots a separate test container, and queries executed
 * inside the request kernel must remain visible to the assertion-side
 * container.
 */
class TestDebugDataHolder extends DebugDataHolder
{
    private const array INCLUDED_CLASSES = [
        'Symfony\Component\EventDispatcher\EventDispatcher',
        'Symfony\Component\Messenger\Command\ConsumeMessagesCommand',
    ];

    private const array EXCLUDED_CLASSES = [
        'DAMA\DoctrineTestBundle\Doctrine\DBAL\PostConnectEventListener',
    ];

    /** @var array<string, array<int, array{sql: string, params: array<int|string, mixed>, types: array<int|string, mixed>, executionMS: float|callable}>> */
    private static array $data = [];

    /** @var array<string, array<int, array<int, array<string, mixed>>>> */
    private static array $backtraces = [];

    public function reset(): void
    {
        self::$data = [];
        self::$backtraces = [];
    }

    /**
     * @SuppressWarnings("PHPMD.BooleanArgumentFlag")
     */
    public function addQuery(string $connectionName, Query $query, bool $force = false): void
    {
        $backtraces = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);

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
     * @return array<string, array<int, array<string, mixed>>>
     */
    public function getData(): array
    {
        foreach (self::$data as $connectionName => $dataForConn) {
            foreach ($dataForConn as $idx => $record) {
                if (\is_callable($record['executionMS'])) {
                    self::$data[$connectionName][$idx]['executionMS'] = ($record['executionMS'])();
                }
            }
        }

        $dataWithBacktraces = [];
        foreach (self::$data as $connectionName => $dataForConn) {
            $dataWithBacktraces[$connectionName] = $this->withBacktraces($connectionName, $dataForConn);
        }

        return $dataWithBacktraces;
    }

    /**
     * @param array<int, array<string, mixed>> $dataForConn
     *
     * @return array<int, array<string, mixed>>
     */
    private function withBacktraces(string $connectionName, array $dataForConn): array
    {
        $records = [];
        foreach ($dataForConn as $idx => $record) {
            if (isset(self::$backtraces[$connectionName][$idx])) {
                $record['backtrace'] = self::$backtraces[$connectionName][$idx];
            }

            $records[] = $record;
        }

        return $records;
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

        $classes = array_unique(array_map(static fn (array $frame) => $frame['class'] ?? null, $backtraces));
        foreach ($classes as $class) {
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

    private function isSkippedClass(?string $class): bool
    {
        return null === $class
            || str_starts_with($class, 'Behat')
            || str_starts_with($class, 'PHPUnit')
            || str_starts_with($class, 'Symfony')
            || str_contains($class, 'OptimizedLoadingFixturesContext');
    }

    private function hasAppSuffix(string $class): bool
    {
        return str_ends_with($class, 'Controller')
            || str_ends_with($class, 'ParamConverter')
            || str_ends_with($class, 'Command')
            || str_ends_with($class, 'Resolver');
    }

    private function isInControllerNamespace(string $class): bool
    {
        return str_contains($class, '\\Controller\\');
    }
}
