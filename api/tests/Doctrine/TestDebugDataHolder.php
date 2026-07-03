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

    /**
     * Intentionally a no-op. When the profiler is enabled, Symfony's DoctrineDataCollector::reset()
     * calls this on the holder every time the KernelBrowser reboots the kernel between requests —
     * which would wipe the cross-request accumulator that per-scenario query assertions rely on.
     * Scenario-boundary clearing goes through {@see resetScenario()} instead.
     */
    #[Override]
    public function reset(): void
    {
    }

    /**
     * Explicit scenario-level reset of the static accumulator. Driven only by DoctrineContext at
     * scenario start, so it is decoupled from the profiler's per-request collector resets.
     */
    public function resetScenario(): void
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

        if (!$force && (!$this->shouldLog($backtraces) || $this->isAuthenticationLookup($query))) {
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
     * The session firewall reloads the authenticated user on every request (UserProvider::refreshUser →
     * findByEmail). That lookup is fixed per-request authentication plumbing, not the business-query
     * behaviour the per-connection budgets pin, so it is dropped here — otherwise every authenticated
     * scenario would carry a spurious +1 against every count. `identity_user` is the Identity aggregate's
     * table and has no business read path, so matching it by name is exact; revisit if that context ever
     * gains queryable read endpoints of its own.
     */
    private function isAuthenticationLookup(Query $query): bool
    {
        return \str_contains($query->getSql(), 'identity_user');
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
            $decision = $this->classifyClass($class);

            if (null !== $decision) {
                return $decision;
            }
        }

        return false;
    }

    /**
     * Tri-state classification for a single backtrace class — `true` to log, `false` to
     * suppress, `null` to defer to the next frame. Extracted from {@see shouldLog} to keep
     * that loop under the S1142 return budget without changing semantics.
     */
    private function classifyClass(string $class): ?bool
    {
        return match (true) {
            \in_array($class, self::EXCLUDED_CLASSES, true) => false,
            \in_array($class, self::INCLUDED_CLASSES, true) => true,
            $this->isSkippedClass($class) => null,
            $this->hasAppSuffix($class) || $this->isInControllerNamespace($class) => true,
            default => null,
        };
    }

    private function isSkippedClass(string $class): bool
    {
        return \str_starts_with($class, 'Behat')
            || \str_starts_with($class, 'PHPUnit')
            || \str_starts_with($class, 'Symfony')
            || \str_contains($class, 'FixturesContext');
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
