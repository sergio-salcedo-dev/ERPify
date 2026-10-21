<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Gate;

use Erpify\Shared\Audit\Domain\ActorContext;
use Erpify\Shared\Audit\Domain\ActorType;
use Erpify\Shared\Audit\Domain\AuditLevel;
use Erpify\Shared\Audit\Infrastructure\Persistence\AuditLogSchemaListener;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use SplFileInfo;

/**
 * The token lists of the `audit_log` `CHECK` constraints, read out of the migrations that add them and
 * compared with the enums they mirror — without a database. The migrations spell their tokens as literals on
 * purpose (a migration imports nothing from `src`), so a case added to `ActorType` or `AuditLevel` is
 * otherwise first refused at runtime, as a 23514 on the first row carrying it.
 * `AuditLogCheckConstraintFunctionalTest` asks Postgres the same question; this one answers it at unit time.
 *
 * What it reads, and the only shape it reads: `ADD CONSTRAINT <name> CHECK (' . self::<CONST> . ')` inside a
 * migration, where `<CONST>` is a `private const string` of the same file whose value holds the tokens as
 * single-quoted literals. A later migration defining the same name supersedes an earlier one, ordered by the
 * `Version<timestamp>` file name. Any other spelling of an `audit_log_*` CHECK fails here rather than being
 * read past.
 *
 * A green proves the committed migrations define exactly the pinned constraints, the listener names each of
 * them, and each token list equals its enum. It proves nothing about the live database (a migration not yet
 * run, a constraint dropped by hand — that is the functional test), nothing about a CHECK built from SQL
 * assembled at runtime, and nothing about a `DROP CONSTRAINT` with no later `ADD`.
 *
 * @internal
 */
#[CoversNothing]
final class AuditLogCheckEnumTokenGateTest extends TestCase
{
    private const string ADD_CHECK = '/ADD CONSTRAINT (audit_log_\w+) CHECK \(\'\s*\.\s*self::([A-Z_]+)\s*\.\s*\'\)/';

    private const string ANY_ADD_CHECK = '/ADD CONSTRAINT audit_log_\w+ CHECK/';

    private const array PINNED = [
        'audit_log_actor_id_presence_check',
        'audit_log_actor_type_check',
        'audit_log_level_check',
    ];

    #[Test]
    public function theListenerNamesExactlyThePinnedConstraints(): void
    {
        $named = [];

        foreach ((new ReflectionClass(AuditLogSchemaListener::class))->getConstants() as $name => $value) {
            if (\str_ends_with($name, '_CHECK')) {
                $named[] = $value;
            }
        }

        \sort($named);

        $this->assertSame(
            self::PINNED,
            $named,
            'AuditLogSchemaListener names a different set of audit_log CHECK constraints than this gate pins.',
        );
    }

    #[Test]
    public function theMigrationsDefineExactlyThePinnedConstraints(): void
    {
        $defined = \array_keys($this->definitions());
        \sort($defined);

        $this->assertSame(
            self::PINNED,
            $defined,
            'The migrations define a different set of audit_log CHECK constraints than this gate pins: a new '
            . 'one needs a line here, a constant on AuditLogSchemaListener and a case in the functional test.',
        );
    }

    #[Test]
    public function theActorTypeCheckAdmitsExactlyTheActorTypeCases(): void
    {
        $cases = \array_map(static fn (ActorType $type): string => $type->value, ActorType::cases());

        $this->assertTokens(
            AuditLogSchemaListener::ACTOR_TYPE_CHECK,
            $cases,
            'ActorType::cases(): a new case needs a migration that replaces the constraint (and, if it carries '
            . 'no id, the presence check too).',
        );
    }

    #[Test]
    public function thePresenceCheckNamesExactlyTheIdLessActorTypes(): void
    {
        $this->assertTokens(
            AuditLogSchemaListener::ACTOR_ID_PRESENCE_CHECK,
            [ActorContext::anonymous()->type->value, ActorContext::system()->type->value],
            'the id-less actors ActorContext mints: a migration must replace the constraint.',
        );
    }

    #[Test]
    public function theLevelCheckAdmitsExactlyTheAuditLevelCases(): void
    {
        $cases = \array_map(static fn (AuditLevel $level): string => $level->value, AuditLevel::cases());

        $this->assertTokens(
            AuditLogSchemaListener::LEVEL_CHECK,
            $cases,
            'AuditLevel::cases(): a new case needs a migration that replaces the constraint, and the pruner a '
            . 'retention window for it.',
        );
    }

    /**
     * @param list<string> $expected
     */
    private function assertTokens(string $constraint, array $expected, string $driftedFrom): void
    {
        $definitions = $this->definitions();
        $this->assertArrayHasKey($constraint, $definitions, \sprintf('No migration defines "%s".', $constraint));

        \preg_match_all("/'([^']*)'/", $definitions[$constraint], $matches);
        $tokens = $matches[1];
        \sort($tokens);
        \sort($expected);

        $this->assertSame(
            $expected,
            $tokens,
            \sprintf('The tokens the migrations give "%s" have drifted from %s', $constraint, $driftedFrom),
        );
    }

    /**
     * @return array<string, string> constraint name => the CHECK expression of the latest migration defining it
     */
    private function definitions(): array
    {
        $definitions = [];

        foreach ($this->migrationFiles() as $path) {
            $source = \file_get_contents($path);
            $this->assertIsString($source);

            $all = \preg_match_all(self::ANY_ADD_CHECK, $source);
            $read = \preg_match_all(self::ADD_CHECK, $source, $matches, PREG_SET_ORDER);

            $this->assertSame(
                $all,
                $read,
                \sprintf('%s adds an audit_log CHECK in a shape this gate cannot read.', \basename($path)),
            );

            foreach ($matches as [, $constraint, $constant]) {
                $definitions[$constraint] = $this->constantIn($source, $constant, $path);
            }
        }

        return $definitions;
    }

    private function constantIn(string $source, string $constant, string $path): string
    {
        $found = \preg_match(
            '/const string ' . $constant . ' = "([^"]*)";/',
            $source,
            $match,
        );

        if (1 !== $found || !isset($match[1])) {
            $this->fail(\sprintf(
                '%s names self::%s, which is not a double-quoted string constant.',
                \basename($path),
                $constant,
            ));
        }

        return $match[1];
    }

    /**
     * @return list<string> every migration file, ordered by its `Version<timestamp>` name
     */
    private function migrationFiles(): array
    {
        $files = [];
        $walk = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(\dirname(__DIR__, 3) . '/migrations', RecursiveDirectoryIterator::SKIP_DOTS),
        );

        /** @var SplFileInfo $file */
        foreach ($walk as $file) {
            if (1 === \preg_match('/^Version\d{14}\.php$/', $file->getFilename())) {
                $files[$file->getFilename()] = $file->getPathname();
            }
        }

        \ksort($files);
        $this->assertNotEmpty($files, 'The migration walk found no Version<timestamp>.php file.');

        return \array_values($files);
    }
}
