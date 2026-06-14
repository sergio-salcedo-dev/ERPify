<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Architecture;

use Erpify\Tests\Support\ApiSourceFiles;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Prescriptive gate for the event-dispatch boundary: a use case publishes domain events through the
 * `Erpify\Shared\Domain\Bus\Event\EventBus` port, never by importing Symfony's MessageBusInterface
 * directly. That coupling is the dual-write / hexagonal-purity smell the EventBus closes; this gate
 * stops it reappearing. Full rationale: docs/adr/event-driven-architecture.md.
 *
 * The single sanctioned exception is the audit axis: `BankAccountSearcher` dispatches a non-DomainEvent
 * best-effort audit message that must NOT travel the transactional EventBus, and stays on
 * MessageBusInterface until the AuditLogger epic lands (docs/adr/audit-activity-log.md). It is declared
 * in `api/.event-dispatch-allowlist`.
 *
 * Scope: the gate flags the coupling expressed as a `use` import of MessageBusInterface in any
 * `Application/` directory. The single adapter that legitimately wraps the message bus lives under
 * `Infrastructure/` and is out of scope by design. Runtime / reflection / container-alias resolution
 * is not checked — the boundary is enforced at the import seam, the overwhelmingly common shape.
 *
 * @internal
 */
#[CoversNothing]
final class EventDispatchGateTest extends TestCase
{
    /**
     * Failure preamble — a class const so make-target wrappers and CI log scrapers can grep the
     * literal string.
     */
    public const string FAILURE_PREAMBLE
        = 'Application code must publish domain events through Erpify\Shared\Domain\Bus\Event\EventBus, '
        . 'not by importing Symfony\Component\Messenger\MessageBusInterface directly. '
        . 'See docs/adr/event-driven-architecture.md';

    private const string FORBIDDEN_FQCN = \Symfony\Component\Messenger\MessageBusInterface::class;

    /**
     * Only the orchestration layer is guarded: the EventBus adapter that legitimately wraps the
     * message bus lives under `Infrastructure/`.
     */
    private const string APPLICATION_SEGMENT = '/Application/';

    public function testNoMessageBusInterfaceImportInApplicationLayer(): void
    {
        $hits = $this->scanApplicationLayer();

        if ([] === $hits) {
            // Empty result is the green path. Pin the assertion count so PHPUnit does not flag a
            // risky no-assertion test.
            $this->addToAssertionCount(1);

            return;
        }

        $message = self::FAILURE_PREAMBLE . "\n" . \implode("\n", \array_map(
            static fn (array $hit): string => \sprintf('%s:%d', $hit['file'], $hit['line']),
            $hits,
        ));

        $this->fail($message);
    }

    public function testGateScansAtLeastOneApplicationFile(): void
    {
        // A silent zero-file scan would make the gate vacuous and let the smell merge.
        $count = 0;

        foreach (ApiSourceFiles::phpFiles($this->apiSrcRoot()) as $file) {
            if (\str_contains($this->relativePath($file->getPathname()), self::APPLICATION_SEGMENT)) {
                ++$count;
            }
        }

        $this->assertGreaterThan(0, $count, 'Event-dispatch gate scanned zero Application files.');
    }

    public function testFixtureExposesMatcher(): void
    {
        $dirty = <<<'PHP'
            <?php
            namespace Erpify\Backoffice\Bank\Application;
            use Symfony\Component\Messenger\MessageBusInterface;
            final class Sample {}
            PHP;

        $this->assertSame(
            [3],
            $this->matchForbiddenImport($dirty),
            'Gate matcher failed to flag a direct MessageBusInterface import — the parser has regressed.',
        );

        $clean = <<<'PHP'
            <?php
            namespace Erpify\Backoffice\Bank\Application;
            use Erpify\Shared\Domain\Bus\Event\EventBus;
            use function Symfony\Component\Messenger\some_helper;
            final class Sample {}
            PHP;

        $this->assertSame(
            [],
            $this->matchForbiddenImport($clean),
            'Gate matcher flagged an EventBus import or `use function` — false positive.',
        );
    }

    public function testAllowlistEntriesPointToExistingFiles(): void
    {
        $apiRoot = $this->apiRoot();
        $missing = [];

        foreach ($this->loadAllowlist() as $relative) {
            if (!\is_file($apiRoot . '/' . $relative)) {
                $missing[] = $relative;
            }
        }

        $this->assertSame(
            [],
            $missing,
            \sprintf(
                "Stale entries in api/.event-dispatch-allowlist (file no longer exists):\n%s",
                \implode("\n", $missing),
            ),
        );
    }

    /**
     * @return list<array{file: string, line: int}>
     */
    private function scanApplicationLayer(): array
    {
        $allowlist = $this->loadAllowlist();
        $hits = [];

        foreach (ApiSourceFiles::phpFiles($this->apiSrcRoot()) as $file) {
            $relative = $this->relativePath($file->getPathname());

            if (!\str_contains($relative, self::APPLICATION_SEGMENT)) {
                continue;
            }

            if (\in_array($relative, $allowlist, true)) {
                continue;
            }

            $contents = \file_get_contents($file->getPathname());

            if (false === $contents) {
                continue;
            }

            foreach ($this->matchForbiddenImport($contents) as $line) {
                $hits[] = ['file' => $relative, 'line' => $line];
            }
        }

        return $hits;
    }

    /**
     * Returns the 1-based line numbers where the file imports the forbidden FQCN via `use`. Matches a
     * plain or aliased class import; `use function` / `use const` are skipped.
     *
     * @return list<int>
     */
    private function matchForbiddenImport(string $source): array
    {
        $lines = \preg_split('/\R/', $source);

        if (false === $lines) {
            return [];
        }

        $hits = [];

        foreach ($lines as $index => $line) {
            if (1 !== \preg_match('/^\s*use\s+(.+?)\s*;/', $line, $matches)) {
                continue;
            }

            $body = $matches[1];

            if (1 === \preg_match('/^(?:function|const)\s/', $body)) {
                continue;
            }

            if (\str_contains($body, self::FORBIDDEN_FQCN)) {
                $hits[] = $index + 1;
            }
        }

        return $hits;
    }

    /**
     * @return list<string>
     */
    private function loadAllowlist(): array
    {
        $path = $this->apiRoot() . '/.event-dispatch-allowlist';

        if (!\is_file($path)) {
            return [];
        }

        $raw = \file_get_contents($path);

        if (false === $raw) {
            return [];
        }

        $entries = [];

        foreach (\preg_split('/\R/', $raw) ?: [] as $line) {
            $trimmed = \trim($line);

            if ('' === $trimmed) {
                continue;
            }

            if (\str_starts_with($trimmed, '#')) {
                continue;
            }

            $entries[] = $trimmed;
        }

        return $entries;
    }

    private function relativePath(string $absolute): string
    {
        $prefix = $this->apiRoot() . '/';

        return \str_starts_with($absolute, $prefix) ? \substr($absolute, \strlen($prefix)) : $absolute;
    }

    private function apiRoot(): string
    {
        return \dirname(__DIR__, 4);
    }

    private function apiSrcRoot(): string
    {
        return $this->apiRoot() . '/src';
    }
}
