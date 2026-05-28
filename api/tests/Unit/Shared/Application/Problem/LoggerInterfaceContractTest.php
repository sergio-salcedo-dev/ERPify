<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Application\Problem;

use Erpify\Shared\Application\Problem\ProblemDetailsFactory;
use Erpify\Shared\Infrastructure\Http\EventListener\ExceptionResponder;
use FilesystemIterator;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use SplFileInfo;

/**
 * Pins the "PSR-3 logger only, no custom async infrastructure" invariant on
 * the error path.
 *
 * Two cooperating gates:
 *   1. Reflection: every logger-shaped constructor parameter on
 *      {@see ExceptionResponder} and {@see ProblemDetailsFactory} declares
 *      {@see LoggerInterface} (or a sub-interface). No `LoggerAwareInterface`,
 *      no Monolog `Logger` concrete, no internal logger abstraction — the
 *      production listener must accept a vanilla PSR-3 sink so a calling
 *      project can swap in their own observability target without code
 *      changes.
 *   2. Source-text grep: neither the listener source nor the factory source
 *      imports `Symfony\Component\Messenger\…`, `react/async`, `amp/amp`, or
 *      similar custom-async infra. Synchronous PSR-3 writes (Monolog default
 *      stderr) are the contract; an async escape hatch on the error path is
 *      explicitly out-of-scope.
 *
 * Mirrors the curated-grep + reflection pattern from
 * {@see NoDatabaseDependenciesContractTest} and {@see BannedDoctrineApisTest}
 * so a future contributor adding a Messenger-based async log on the error
 * path fails the build before merge.
 *
 * @internal
 */
#[CoversNothing]
final class LoggerInterfaceContractTest extends TestCase
{
    /**
     * @param class-string $class
     */
    #[DataProvider('provideListenerLoggerDepIsPsr3OnlyCases')]
    public function testListenerLoggerDepIsPsr3Only(string $class): void
    {
        $reflectionClass = new ReflectionClass($class);
        $constructor = $reflectionClass->getConstructor();
        $this->assertInstanceOf(
            ReflectionMethod::class,
            $constructor,
            \sprintf('%s must declare a constructor for the logger-shape pin.', $class),
        );

        $loggerParameters = [];

        foreach ($constructor->getParameters() as $reflectionParameter) {
            if ($this->isLoggerShaped($reflectionParameter)) {
                $loggerParameters[] = $reflectionParameter;
            }
        }

        $this->assertNotEmpty(
            $loggerParameters,
            \sprintf(
                '%s must accept at least one PSR-3 logger dependency so the wire '
                . 'contract can satisfy the "exactly one structured log line per error" '
                . 'requirement without reaching for a global state.',
                $class,
            ),
        );

        foreach ($loggerParameters as $loggerParameter) {
            $type = $loggerParameter->getType();
            $this->assertInstanceOf(
                ReflectionNamedType::class,
                $type,
                \sprintf(
                    '%s::__construct $%s must declare a single named PSR-3 type '
                    . '(union / intersection types are an explicit smell on the error path).',
                    $class,
                    $loggerParameter->getName(),
                ),
            );

            $typeName = $type->getName();

            $this->assertSame(
                LoggerInterface::class,
                $typeName,
                \sprintf(
                    '%s::__construct $%s must declare exactly Psr\Log\LoggerInterface, '
                    . 'not %s. PSR-3 is the only logger contract the error path may depend on; '
                    . 'no Monolog concrete, no LoggerAwareInterface, no internal abstraction.',
                    $class,
                    $loggerParameter->getName(),
                    $typeName,
                ),
            );
        }
    }

    /**
     * @return iterable<string, array{class-string}>
     */
    public static function provideListenerLoggerDepIsPsr3OnlyCases(): iterable
    {
        yield 'ExceptionResponder' => [ExceptionResponder::class];
        yield 'ProblemDetailsFactory' => [ProblemDetailsFactory::class];
    }

    /**
     * Source-text grep — pins that the listener and factory carry no `use` import
     * pointing at custom-async infrastructure. Synchronous PSR-3 writes are the
     * contract; an async escape hatch on the error path is explicitly out-of-scope.
     *
     * Patterns covered:
     *   - `Symfony\Component\Messenger\…` — message-bus-based async dispatch
     *   - `React\…`                       — `react/async`, `react/event-loop`, …
     *   - `Amp\…`                         — `amp/amp`, `amp/parallel`, …
     *   - `Spatie\Async\…`                — `spatie/async`, parallel forks
     *   - `Swoole\…`                      — Swoole coroutines
     */
    #[DataProvider('provideNoCustomAsyncInfrastructureInListenerOrFactoryCases')]
    public function testNoCustomAsyncInfrastructureInListenerOrFactory(string $regex, string $rationale): void
    {
        $hits = [];

        foreach ($this->errorPathPhpFiles() as $file) {
            $contents = \file_get_contents($file->getPathname());
            $this->assertNotFalse($contents, \sprintf('Failed to read %s.', $file->getPathname()));

            if (1 === \preg_match($regex, $contents)) {
                $hits[] = $this->relativePath($file->getPathname());
            }
        }

        $this->assertSame(
            [],
            $hits,
            \sprintf(
                'Error-path source MUST NOT depend on custom async infrastructure: %s '
                . 'PSR-3 sync writes (Monolog default stderr) are the contract. Files: %s',
                $rationale,
                \implode(', ', $hits),
            ),
        );
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideNoCustomAsyncInfrastructureInListenerOrFactoryCases(): iterable
    {
        yield 'symfony-messenger' => [
            '/use\s+Symfony\\\Component\\\Messenger\b/',
            'Symfony Messenger is reserved for async commands (audit, email) — not the error log path.',
        ];

        yield 'react-php' => [
            '/use\s+React\\\(Async|EventLoop|Promise)\b/',
            'react/async / react/event-loop / react/promise belong in long-running workers, not the listener.',
        ];

        yield 'amphp' => [
            '/use\s+Amp\\\/',
            'Amp coroutines are an explicit non-goal for the error path.',
        ];

        yield 'spatie-async' => [
            '/use\s+Spatie\\\Async\\\/',
            'spatie/async forks workers per call — never on the request hot path.',
        ];

        yield 'swoole' => [
            '/use\s+Swoole\\\/',
            'Swoole coroutines require a special runtime and are out of scope for FrankenPHP worker mode.',
        ];
    }

    public function testGrepGateActuallyScannedAtLeastOneSourceFile(): void
    {
        $count = \iterator_count($this->errorPathPhpFiles());

        $this->assertGreaterThan(
            0,
            $count,
            'Logger contract grep gate scanned zero files — check the directory roots match '
            . 'the actual error-path source tree.',
        );
    }

    private function isLoggerShaped(ReflectionParameter $parameter): bool
    {
        $type = $parameter->getType();

        if (!$type instanceof ReflectionNamedType) {
            return false;
        }

        if ($type->isBuiltin()) {
            return false;
        }

        $typeName = $type->getName();

        if (LoggerInterface::class === $typeName) {
            return true;
        }

        if (!\interface_exists($typeName) && !\class_exists($typeName)) {
            return false;
        }

        return \is_a($typeName, LoggerInterface::class, allow_string: true);
    }

    /**
     * Yields the explicit Problem Details error-contract files / directory: the entire
     * `Shared/Application/Problem/` subtree plus the responder and listener that emit
     * the wire body. Legacy search-path classes are intentionally excluded — they
     * predate the error contract and live in `Shared/Infrastructure/Http/` but are not
     * part of the listener / factory / responder triple.
     *
     * @return iterable<SplFileInfo>
     */
    private function errorPathPhpFiles(): iterable
    {
        $apiRoot = \dirname(__DIR__, 5);

        $directoryRoot = $apiRoot . '/src/Shared/Application/Problem';

        if (\is_dir($directoryRoot)) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($directoryRoot, FilesystemIterator::SKIP_DOTS),
            );

            foreach ($iterator as $file) {
                if (!$file instanceof SplFileInfo) {
                    continue;
                }

                if (!$file->isFile()) {
                    continue;
                }

                if ('php' !== $file->getExtension()) {
                    continue;
                }

                yield $file;
            }
        }

        $explicitFiles = [
            $apiRoot . '/src/Shared/Infrastructure/Http/ProblemDetailsResponder.php',
            $apiRoot . '/src/Shared/Infrastructure/Http/EventListener/ExceptionResponder.php',
        ];

        foreach ($explicitFiles as $explicitFile) {
            if (!\is_file($explicitFile)) {
                continue;
            }

            yield new SplFileInfo($explicitFile);
        }
    }

    private function relativePath(string $absolute): string
    {
        $apiRoot = \dirname(__DIR__, 5);
        $apiPrefix = $apiRoot . '/';

        if (\str_starts_with($absolute, $apiPrefix)) {
            return \substr($absolute, \strlen($apiPrefix));
        }

        return $absolute;
    }
}
