<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Architecture;

use Erpify\Tests\Support\AllowlistFile;
use Erpify\Tests\Support\ApiSourceFiles;
use Erpify\Tests\Support\ClassImports;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Prescriptive gate for the framework seams the orchestration layer is not allowed to reach through.
 * Each forbidden type has a framework-free port that replaces it, and importing the framework type
 * instead is the hexagonal-purity smell the port exists to close:
 *
 * - `Symfony\Component\Messenger\MessageBusInterface` — a use case publishes domain events through
 *   `Erpify\Shared\Event\Domain\EventBus`, never by dispatching on the bus itself. Dispatching
 *   directly is the dual-write smell (docs/adr/event-driven-architecture.md).
 * - `Doctrine\ORM\EntityManagerInterface`, `Doctrine\ORM\EntityManager`,
 *   `Doctrine\Persistence\ManagerRegistry` and `Doctrine\Persistence\ObjectManager` — a use case owns
 *   its transaction boundary through `Erpify\Shared\Persistence\Application\TransactionManager` and
 *   reads through its module's own repository interface, never by driving the ORM
 *   (docs/adr/external-dependencies-in-domain.md: Doctrine stays out of Application). The registry is
 *   how most Symfony code reaches a manager, so naming only the two rarer siblings would refuse the
 *   unusual shapes and leave the common one open.
 *
 * No `Application/` file imports any of them; every `EntityManagerInterface` import `api/src` holds
 * sits under `Infrastructure/`, which is where the adapters that wrap the seam belong.
 *
 * **Defence in depth over a line deptrac already holds for every REGISTERED context, plus first-line
 * cover for the contexts it does not know about.** `tools/deptrac/deptrac.yaml` collects every
 * `^Doctrine\.*` outside `Vendor.PassiveMetadata` into `Vendor.Doctrine`, which no `*.Application`
 * ruleset lists and which the baseline grandfathers for no Application class: an
 * `EntityManagerInterface` constructor argument planted in `BankCreator` is `DependsOnDisallowedLayer`
 * — Violations 1, exit 1. What deptrac cannot state is a context it has no layer for. Its layers are
 * one directory collector per registered module, and `Frontoffice/Dev` declares an `Infrastructure`
 * layer and no Application one, so a `src/Frontoffice/Dev/Application/` file sits in no layer at all:
 * measured, the same planted dependency there leaves deptrac at Violations 0, Uncovered 0, exit 0
 * while this gate names the file and its line. Matching on a path segment is what buys that — a module
 * is covered the day it is created, not the day somebody remembers to register it. The two also part
 * company on an import nothing uses: deptrac reads dependencies, so a bare unused import is Violations
 * 0 there (measured), while this gate refuses the import itself.
 *
 * Scope: a `use` import of a forbidden FQCN in any `Application/` directory, lexed by
 * {@see ClassImports} rather than read line by line. A grouped import (`use Doctrine\ORM\{A, B};`), a
 * nested group, a statement whose `;` sits on the next line, two on one line, a comment before the keyword,
 * an alias and a leading `\` are one and the same statement to the tokeniser, and a line-oriented
 * matcher was measured reading the first five of those as no import at all. `use function` /
 * `use const` import no class and are skipped, at the statement and at the group-element level.
 *
 * Reading tokens is also what makes the gate say the same thing locally and on CI. `make php.quality`
 * runs `php.cs-fixer` in APPLY mode ahead of this gate and PSR-12 expands a grouped import into plain
 * ones, so a line-based matcher caught that shape only because a *formatter* had rewritten it first,
 * and stayed green under `php.quality.dry-run`, which rewrites nothing. Measured on one planted
 * grouped import: green on the CI path, red on the local one. A boundary gate whose verdict depends on
 * whether a fixer ran before it is blindest exactly where it is the only reader.
 *
 * What a green does NOT prove:
 * - An inline fully-qualified reference (`private \Doctrine\ORM\EntityManagerInterface $em`) declares
 *   no import and passes here — measured. deptrac reds on that shape (Violations 1, exit 1) wherever
 *   it has a layer, which is the half of the pair this one cannot cover.
 * - Runtime, reflection and container-alias resolution are never inspected, so a collaborator pulled
 *   from the container by id is invisible.
 * - The forbidden set is a list, not the framework surface: a sibling type reaching the same
 *   subsystem passes until it is added here.
 * - The `Application/` match is case-sensitive, a blind spot deptrac's directory collectors share.
 * - The walk does not descend into symlinked directories, so a tree linked into `src` is invisible to
 *   the sweep and to the layout cross-check alike — measured, both stay green over it.
 * - An allowlist entry exempts a FILE, so it exempts it for every forbidden type at once. That file
 *   holds no entries, which leaves the allowlist assertion vacuous until the first one lands.
 *
 * @internal
 */
#[CoversNothing]
final class EventDispatchGateTest extends TestCase
{
    /** The remedy, named once so every failure carries it and not just the offending line. */
    private const string FAILURE_PREAMBLE
        = 'Application code must reach framework seams through their ports, not by importing the '
        . 'framework type: publish domain events through Erpify\Shared\Event\Domain\EventBus and '
        . 'own transactions through Erpify\Shared\Persistence\Application\TransactionManager. '
        . 'See docs/adr/event-driven-architecture.md and docs/adr/external-dependencies-in-domain.md';

    private const string TRANSACTION_MANAGER = \Erpify\Shared\Persistence\Application\TransactionManager::class;

    /**
     * Forbidden framework FQCN => the port that replaces it, named in the failure so the remedy
     * arrives with the violation.
     *
     * @var array<class-string, class-string>
     */
    private const array FORBIDDEN_FQCNS = [
        \Symfony\Component\Messenger\MessageBusInterface::class => \Erpify\Shared\Event\Domain\EventBus::class,
        \Doctrine\ORM\EntityManagerInterface::class => self::TRANSACTION_MANAGER,
        \Doctrine\ORM\EntityManager::class => self::TRANSACTION_MANAGER,
        \Doctrine\Persistence\ManagerRegistry::class => self::TRANSACTION_MANAGER,
        \Doctrine\Persistence\ObjectManager::class => self::TRANSACTION_MANAGER,
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

    /**
     * A silent zero-file scan would make the gate vacuous, and so would a narrowed one: the sweep is
     * compared against the Application directories the tree holds, both sides derived from the
     * filesystem so neither is a number anybody has to remember to update. The cross-check assumes the
     * `<context>/<module>/Application` layout every module uses today; a deeper one is a red asking
     * for a look rather than a silent partial scan.
     */
    public function testGateScansEveryApplicationFileInTheTree(): void
    {
        $scanned = [];

        foreach (ApiSourceFiles::phpFiles($this->apiSrcRoot()) as $file) {
            $relative = $this->relativePath($file->getPathname());

            if (\str_contains($relative, self::APPLICATION_SEGMENT)) {
                $scanned[] = $relative;
            }
        }

        \sort($scanned);

        $this->assertNotEmpty($scanned, 'Event-dispatch gate scanned zero Application files.');
        $this->assertSame(
            $this->applicationFilesFromLayout(),
            $scanned,
            'The gate no longer sweeps exactly the Application files the tree declares.',
        );
    }

    /**
     * A listed FQCN the matcher cannot actually find would make its half of the gate vacuous, so every
     * entry is exercised against a planted import rather than trusted for being in the list.
     */
    public function testFixtureExposesMatcherForEveryForbiddenFqcn(): void
    {
        foreach (self::FORBIDDEN_FQCNS as $fqcn => $port) {
            $this->assertSame(
                [['line' => 3, 'fqcn' => $fqcn, 'port' => $port]],
                $this->matchForbiddenImport($this->sampleWithImport(\sprintf('use %s;', $fqcn))),
                \sprintf('Gate matcher failed to flag a direct %s import — the parser has regressed.', $fqcn),
            );
        }
    }

    /**
     * Every shape a class import can take, including the five a line-oriented matcher was measured
     * reading as no import at all (everything up to the alias). Each is ordinary PHP that a fixer
     * reformats rather than rejects, so none of them announces itself as evasion.
     */
    public function testMatcherReadsEveryShapeOfClassImport(): void
    {
        $shapes = [
            'grouped import' => 'use Doctrine\ORM\{EntityManagerInterface, EntityManager};',
            'nested group' => 'use Doctrine\{ORM\EntityManagerInterface, DBAL\Connection};',
            'terminator on the next line' => "use Doctrine\\ORM\\EntityManagerInterface\n;",
            'two on one line' => 'use Erpify\Shared\Uuid\Domain\Uuid; use Doctrine\ORM\EntityManagerInterface;',
            'comment before the keyword' => '/* wrapped */ use Doctrine\ORM\EntityManagerInterface;',
            'aliased import' => 'use Doctrine\ORM\EntityManagerInterface as Manager;',
            'leading separator' => 'use \Doctrine\ORM\EntityManagerInterface;',
        ];

        foreach ($shapes as $label => $import) {
            $this->assertNotSame(
                [],
                $this->matchForbiddenImport($this->sampleWithImport($import)),
                \sprintf('Gate matcher missed a %s.', $label),
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
            use const Doctrine\ORM\SOME_FLAG;
            final class Sample
            {
                use SomeTrait;

                public function run(): void
                {
                    $wrap = static function () use ($sample): void {
                        new \Doctrine\ORM\Query();
                    };
                }
            }
            PHP;

        $this->assertSame(
            [],
            $this->matchForbiddenImport($clean),
            'Gate matcher flagged a port, a symbol import, a trait use or a closure capture — false positive.',
        );
    }

    /**
     * An allowlist entry earns its line by exempting a file the gate would otherwise fail on. One that
     * points nowhere, points outside the scanned layer, or points at a file holding no forbidden
     * import reads as a live exception while the exception it records is already paid — the state an
     * allowlist rots into once nobody rereads it.
     */
    public function testAllowlistEntriesAreLiveExemptions(): void
    {
        $apiRoot = $this->apiRoot();
        $rejected = [];

        foreach ($this->loadAllowlist() as $relative) {
            $reason = $this->allowlistEntryProblem($apiRoot . '/' . $relative, $relative);

            if (null !== $reason) {
                $rejected[] = $relative . ' — ' . $reason;
            }
        }

        $this->assertSame(
            [],
            $rejected,
            \sprintf(
                "Entries in api/.event-dispatch-allowlist that exempt nothing:\n%s",
                \implode("\n", $rejected),
            ),
        );
    }

    private function allowlistEntryProblem(string $path, string $relative): ?string
    {
        if (!\is_file($path)) {
            return 'no such file';
        }

        if (!\str_contains($relative, self::APPLICATION_SEGMENT)) {
            return 'outside the layer this gate reads';
        }

        $contents = \file_get_contents($path);

        if (false !== $contents && [] === $this->matchForbiddenImport($contents)) {
            return 'the file holds no forbidden import';
        }

        return null;
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
     * Every forbidden import the source declares: the 1-based line the imported name starts on, the
     * FQCN it resolves to and the port that replaces it.
     *
     * @return list<array{line: int, fqcn: string, port: string}>
     */
    private function matchForbiddenImport(string $source): array
    {
        $hits = [];

        foreach (ClassImports::of($source) as $import) {
            $port = self::FORBIDDEN_FQCNS[$import['name']] ?? null;

            if (null !== $port) {
                $hits[] = ['line' => $import['line'], 'fqcn' => $import['name'], 'port' => $port];
            }
        }

        return $hits;
    }

    /**
     * The Application files the `<context>/<module>/Application` layout declares, derived from the
     * directories rather than from the path substring the gate itself matches on.
     *
     * @return list<string>
     */
    private function applicationFilesFromLayout(): array
    {
        $files = [];

        foreach (\glob($this->apiSrcRoot() . '/*/*/Application', GLOB_ONLYDIR) ?: [] as $directory) {
            foreach (ApiSourceFiles::phpFiles($directory) as $file) {
                $files[] = $this->relativePath($file->getPathname());
            }
        }

        \sort($files);

        return $files;
    }

    private function sampleWithImport(string $import): string
    {
        return \sprintf(
            "<?php\nnamespace Erpify\\Backoffice\\Bank\\Application;\n%s\nfinal class Sample {}\n",
            $import,
        );
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
