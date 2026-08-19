<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Architecture;

use Erpify\Tests\Support\AllowlistFile;
use Erpify\Tests\Support\ApiSourceFiles;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Prescriptive gate for the framework seams the orchestration layer is not allowed to reach through.
 * Each forbidden interface has a framework-free port that replaces it, and importing the framework type
 * instead is the hexagonal-purity smell the port exists to close:
 *
 * - `Symfony\Component\Messenger\MessageBusInterface` — a use case publishes domain events through
 *   `Erpify\Shared\Event\Domain\EventBus`, never by dispatching on the bus itself. Dispatching
 *   directly is the dual-write smell (docs/adr/event-driven-architecture.md).
 * - `Doctrine\ORM\EntityManagerInterface` — a use case owns its transaction boundary through
 *   `Erpify\Shared\Persistence\Application\TransactionManager`, never by driving the ORM
 *   (docs/adr/external-dependencies-in-domain.md: Doctrine stays out of Application).
 *
 * Neither import exists anywhere under `api/src`. The gate is what makes that an invariant rather
 * than a convention, since a convention regresses in silence.
 *
 * Scope: the coupling is flagged as a `use` import of the forbidden FQCN in any `Application/`
 * directory. The adapters that legitimately wrap each seam live under `Infrastructure/` and are out of
 * scope by design.
 *
 * What a green does NOT prove: runtime, reflection and container-alias resolution are never inspected,
 * so a service pulled from the container by id is invisible here. Nor is the whole framework surface
 * enumerated — only the FQCNs listed below are refused, so a sibling type reaching the same subsystem
 * (`Doctrine\ORM\EntityManager`, `Doctrine\Persistence\ObjectManager`) passes until it is added.
 * The boundary is enforced at the import seam, the overwhelmingly common shape.
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
        = 'Application code must reach framework seams through their ports, not by importing the '
        . 'framework type: publish domain events through Erpify\Shared\Event\Domain\EventBus and '
        . 'own transactions through Erpify\Shared\Persistence\Application\TransactionManager. '
        . 'See docs/adr/event-driven-architecture.md and docs/adr/external-dependencies-in-domain.md';

    /**
     * Forbidden framework FQCN => the port that replaces it, named in the failure so the remedy
     * arrives with the violation.
     *
     * @var array<class-string, class-string>
     */
    private const array FORBIDDEN_FQCNS = [
        \Symfony\Component\Messenger\MessageBusInterface::class => \Erpify\Shared\Event\Domain\EventBus::class,
        \Doctrine\ORM\EntityManagerInterface::class => \Erpify\Shared\Persistence\Application\TransactionManager::class,
    ];

    /**
     * Only the orchestration layer is guarded: the adapters that legitimately wrap each framework seam
     * live under `Infrastructure/`.
     */
    private const string APPLICATION_SEGMENT = '/Application/';

    public function testNoForbiddenFrameworkImportInApplicationLayer(): void
    {
        $hits = $this->scanApplicationLayer();

        $this->assertSame([], $hits, self::FAILURE_PREAMBLE . "\n" . \implode("\n", \array_map(
            static fn (array $hit): string => \sprintf(
                '%s:%d imports %s — use %s instead',
                $hit['file'],
                $hit['line'],
                $hit['fqcn'],
                $hit['port'],
            ),
            $hits,
        )));
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

    /**
     * A listed FQCN the matcher cannot actually find would make its half of the gate vacuous, so every
     * entry is exercised against a planted import rather than trusted for being in the list.
     */
    public function testFixtureExposesMatcherForEveryForbiddenFqcn(): void
    {
        foreach (self::FORBIDDEN_FQCNS as $fqcn => $port) {
            $dirty = \sprintf(
                "<?php\nnamespace Erpify\\Backoffice\\Bank\\Application;\nuse %s;\nfinal class Sample {}\n",
                $fqcn,
            );

            $this->assertSame(
                [['line' => 3, 'fqcn' => $fqcn, 'port' => $port]],
                $this->matchForbiddenImport($dirty),
                \sprintf('Gate matcher failed to flag a direct %s import — the parser has regressed.', $fqcn),
            );
        }
    }

    public function testMatcherIgnoresPortsAndFunctionImports(): void
    {
        $clean = <<<'PHP'
            <?php
            namespace Erpify\Backoffice\Bank\Application;
            use Erpify\Shared\Event\Domain\EventBus;
            use Erpify\Shared\Persistence\Application\TransactionManager;
            use function Symfony\Component\Messenger\some_helper;
            final class Sample {}
            PHP;

        $this->assertSame(
            [],
            $this->matchForbiddenImport($clean),
            'Gate matcher flagged a port import or `use function` — false positive.',
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
     * @return list<array{file: string, line: int, fqcn: string, port: string}>
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

            foreach ($this->matchForbiddenImport($contents) as $hit) {
                $hits[] = [
                    'file' => $relative,
                    'line' => $hit['line'],
                    'fqcn' => $hit['fqcn'],
                    'port' => $hit['port'],
                ];
            }
        }

        return $hits;
    }

    /**
     * Returns the 1-based line numbers where the file imports a forbidden FQCN via `use`, each paired
     * with the FQCN it matched and the port that replaces it. Matches a plain or aliased class
     * import; `use function` / `use const` are skipped.
     *
     * @return list<array{line: int, fqcn: string, port: string}>
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

            foreach (self::FORBIDDEN_FQCNS as $fqcn => $port) {
                if (\str_contains($body, $fqcn)) {
                    $hits[] = ['line' => $index + 1, 'fqcn' => $fqcn, 'port' => $port];
                }
            }
        }

        return $hits;
    }

    /**
     * @return list<string>
     */
    private function loadAllowlist(): array
    {
        return AllowlistFile::entries($this->apiRoot() . '/.event-dispatch-allowlist');
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
