<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Documents\Document\Infrastructure\Erasure;

use Erpify\Documents\Document\Application\Erasure\LedgerEntry;
use Erpify\Documents\Document\Application\Erasure\LedgerUnreadable;
use Erpify\Documents\Document\Application\Erasure\LedgerWriteFailed;
use Erpify\Documents\Document\Application\Erasure\LedgerWriteTimedOut;
use Erpify\Documents\Document\Infrastructure\Erasure\ResticErasureLedger;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * The restic adapter against a stand-in `restic` script: what it puts on argv and in the environment, how it reads the
 * four endings of a write and the three of a listing, and that nothing of the child's output or the repository address
 * ever reaches an exception.
 *
 * @internal
 *
 * @SuppressWarnings("PHPMD.TooManyPublicMethods") one test per way the adapter reads or fails
 */
#[CoversClass(ResticErasureLedger::class)]
#[CoversClass(LedgerUnreadable::class)]
#[CoversClass(LedgerEntry::class)]
#[CoversClass(LedgerWriteFailed::class)]
#[CoversClass(LedgerWriteTimedOut::class)]
final class ResticErasureLedgerTest extends TestCase
{
    private const string SCOPE = '0190e9c2-7b5a-4d40-9c8f-2f9b5d3e1a01';

    private const string REPOSITORY = 'rest:https://ledger.example.invalid:8000/erasure';

    private string $directory;

    private TestHandler $log;

    #[Override]
    protected function setUp(): void
    {
        $this->log = new TestHandler();
        $this->directory = \sys_get_temp_dir() . '/restic-ledger-' . \bin2hex(\random_bytes(6));
        \mkdir($this->directory);
    }

    #[Override]
    protected function tearDown(): void
    {
        foreach (\glob($this->directory . '/*') ?: [] as $file) {
            \unlink($file);
        }

        \rmdir($this->directory);
    }

    #[Test]
    public function aWriteSendsTheScopeAsTheSnapshotAndNoSecretOnArgv(): void
    {
        $d = $this->directory;
        $ledger = $this->ledger($this->script(
            'printf "%s\n" "$@" > ' . $d . '/argv; env > ' . $d . '/env; cat > ' . $d . '/stdin; exit 0',
        ));

        $ledger->record(self::SCOPE);

        $argv = (string) \file_get_contents($this->directory . '/argv');
        $this->assertStringContainsString('--no-cache', $argv);
        $this->assertStringContainsString('erased-scope:' . self::SCOPE, $argv);

        foreach (['s3cret-repo', 's3cret-rest', 'ledger.example.invalid', 'restuser'] as $secret) {
            $this->assertStringNotContainsString($secret, $argv, 'no secret or address on the command line');
        }

        $env = (string) \file_get_contents($this->directory . '/env');
        $this->assertStringContainsString('RESTIC_REPOSITORY=' . self::REPOSITORY, $env);
        $this->assertStringContainsString('RESTIC_REST_USERNAME=restuser', $env);
        $this->assertStringContainsString('RESTIC_REST_PASSWORD=s3cret-rest', $env);
        $this->assertStringContainsString('RESTIC_PASSWORD=s3cret-repo', $env);
        $this->assertSame(
            self::SCOPE,
            \file_get_contents($this->directory . '/stdin'),
            'the snapshot has bytes to read back',
        );
    }

    #[Test]
    public function aNonZeroExitIsAWriteThatCertainlyFailedAndCarriesNoCause(): void
    {
        $ledger = $this->ledger($this->script(
            'echo "Fatal: unable to open repository at ' . self::REPOSITORY . '" >&2; exit 1',
        ));

        try {
            $ledger->record(self::SCOPE);
            $this->fail('A refused write is not durable.');
        } catch (LedgerWriteFailed $ledgerWriteFailed) {
            $this->assertStringNotContainsString('invalid', $ledgerWriteFailed->getMessage());
            $this->assertNotInstanceOf(Throwable::class, $ledgerWriteFailed->getPrevious());
        }
    }

    #[Test]
    public function aWriteThatRunsOutOfTimeIsOutcomeUnknown(): void
    {
        $ledger = $this->ledger($this->script('sleep 30'), writeTimeout: 1);

        $this->expectException(LedgerWriteTimedOut::class);

        $ledger->record(self::SCOPE);
    }

    #[Test]
    public function anUnconfiguredLedgerRefusesToWriteRatherThanReachForADefaultRepository(): void
    {
        $ledger = new ResticErasureLedger(null, null, null, null, null, $this->script('exit 0'));

        $this->expectException(LedgerWriteFailed::class);

        $ledger->record(self::SCOPE);
    }

    #[Test]
    public function theListingReadsEachScopeOnceWithTheEarliestOfItsEntries(): void
    {
        $json = \json_encode([
            ['time' => '2026-11-17T21:11:12.649341246+01:00', 'tags' => ['erased-scope:' . self::SCOPE]],
            ['time' => '2026-11-17T21:11:10.649341246+01:00', 'tags' => ['erased-scope:' . self::SCOPE]],
            ['time' => '2026-11-17T21:11:11.000000000+01:00', 'tags' => ['unrelated']],
        ], JSON_THROW_ON_ERROR);
        $ledger = $this->ledger($this->script("echo '" . $json . "'"));

        $entries = $ledger->entries();

        $this->assertCount(1, $entries);
        $this->assertSame(self::SCOPE, $entries[0]->scopeId);
        $this->assertSame('2026-11-17T21:11:10+01:00', $entries[0]->recordedAt->format('c'));
    }

    #[Test]
    public function aSnapshotResticSkippedIsAnUnreadableLedgerEvenWithAZeroExit(): void
    {
        $ledger = $this->ledger($this->script(
            'echo "Ignoring \"2d42e2cf\": failed to load snapshot" >&2; echo "[]"; exit 0',
        ));

        $this->expectException(LedgerUnreadable::class);

        $ledger->entries();
    }

    #[Test]
    public function aFailingListingIsAnUnreadableLedger(): void
    {
        $ledger = $this->ledger($this->script('exit 1'));

        $this->expectException(LedgerUnreadable::class);

        $ledger->entries();
    }

    #[Test]
    public function theFullReadChecksTheDataAndThenTheListing(): void
    {
        $record = $this->directory . '/calls';
        $ledger = $this->ledger($this->script('echo "$@" >> ' . $record . '; echo "[]"; exit 0'));

        $ledger->verifyReadableInFull();

        $calls = (string) \file_get_contents($record);
        $this->assertStringContainsString('check --read-data', $calls);
        $this->assertStringContainsString('snapshots --json', $calls);
    }

    #[Test]
    public function aCheckThatExitsNonZeroIsAnUnreadableLedger(): void
    {
        $ledger = $this->ledger($this->script('if [ "$2" = "check" ]; then exit 1; fi; echo "[]"'));

        $this->expectException(LedgerUnreadable::class);

        $ledger->verifyReadableInFull();
    }

    #[Test]
    public function aTagWithTheScopePrefixAndNoUuidMakesTheLedgerUnreadableInsteadOfBeingSkipped(): void
    {
        foreach (
            [
                'erased-scope:' . \str_repeat('-', 36),
                'erased-scope:not-a-uuid',
                'erased-scope:' . \strtoupper(self::SCOPE),
                'erased-scope:',
            ] as $tag
        ) {
            $json = \json_encode([['time' => '2026-11-17T21:11:10+01:00', 'tags' => [$tag]]], JSON_THROW_ON_ERROR);
            $ledger = $this->ledger($this->script("echo '" . $json . "'"));

            $unreadable = false;

            try {
                $ledger->entries();
            } catch (LedgerUnreadable) {
                $unreadable = true;
            }

            $this->assertTrue($unreadable, 'A damaged entry must not pass for a whole ledger: ' . $tag);
        }
    }

    #[Test]
    public function anEntryWithNoTimeOrAnUnparseableOneMakesTheLedgerUnreadable(): void
    {
        foreach ([[], ['time' => 'yesterday-ish'], ['time' => 12]] as $time) {
            $json = \json_encode([$time + ['tags' => ['erased-scope:' . self::SCOPE]]], JSON_THROW_ON_ERROR);
            $ledger = $this->ledger($this->script("echo '" . $json . "'"));

            $unreadable = false;

            try {
                $ledger->entries();
            } catch (LedgerUnreadable) {
                $unreadable = true;
            }

            $this->assertTrue($unreadable, 'An entry with no usable time is unreadable.');
        }
    }

    #[Test]
    public function aSnapshotThatIsNotOursIsIgnoredAndAnythingThatIsNotASnapshotIsUnreadable(): void
    {
        $foreign = \json_encode(
            [['time' => '2026-11-17T21:11:10+01:00', 'tags' => ['backup']], ['time' => 'x']],
            JSON_THROW_ON_ERROR,
        );
        $this->assertSame([], $this->ledger($this->script("echo '" . $foreign . "'"))->entries());

        $this->expectException(LedgerUnreadable::class);
        $this->ledger($this->script("echo '[\"not a snapshot\"]'"))->entries();
    }

    #[Test]
    public function eachWayAWriteFailsLogsItsOwnCauseSlugAndNeverTheChildsOutput(): void
    {
        $causes = [];

        foreach (
            [
                'exit 127' => 'binary-missing',
                'echo "HTTP 403 Forbidden ' . self::REPOSITORY . '" >&2; exit 1' => 'forbidden',
                'echo "401 Unauthorized" >&2; exit 1' => 'unauthorized',
                'exit 3' => 'exit-3',
            ] as $body => $expected
        ) {
            $this->log->clear();

            try {
                $this->ledger($this->script($body))->record(self::SCOPE);
            } catch (LedgerWriteFailed) {
                $causes[$expected] = $this->loggedCause();
                $this->assertStringNotContainsString(
                    'invalid',
                    (string) \json_encode($this->loggedContext()),
                );
            }
        }

        $this->assertSame(
            ['binary-missing', 'forbidden', 'unauthorized', 'exit-3'],
            \array_values($causes),
        );

        $this->log->clear();

        try {
            $unconfigured = new ResticErasureLedger(
                null,
                null,
                null,
                null,
                null,
                $this->script('exit 0'),
                10,
                new Logger('test', [$this->log]),
            );
            $unconfigured->record(self::SCOPE);
        } catch (LedgerWriteFailed) {
            $this->assertSame('not-configured', $this->loggedCause());
        }

        $this->log->clear();

        try {
            $this->ledger($this->script('sleep 30'), writeTimeout: 1)->record(self::SCOPE);
        } catch (LedgerWriteTimedOut) {
            $this->assertSame('timeout', $this->loggedCause());
        }
    }

    #[Test]
    public function aBinaryThatCannotBeStartedFailsEveryOperationClosedAndLogsItsOwnSlug(): void
    {
        // A NUL byte in the path is refused by the operating system before any process exists.
        $unstartable = "restic\0";
        $ledger = $this->ledger($unstartable);

        try {
            $ledger->record(self::SCOPE);
            $this->fail('A write that could not start is not durable.');
        } catch (LedgerWriteFailed) {
            $this->assertSame('cannot-start', $this->loggedCause());
        }

        foreach ($this->reads() as $read) {
            try {
                $read($ledger);
                $this->fail('A ledger that cannot be listed is unreadable.');
            } catch (LedgerUnreadable) {
                $this->addToAssertionCount(1);
            }
        }
    }

    #[Test]
    public function anUnconfiguredLedgerIsUnreadableToListAndToCheckAndRunsNothing(): void
    {
        $marker = $this->directory . '/ran';
        $ledger = new ResticErasureLedger(null, null, null, null, null, $this->script('touch ' . $marker));

        foreach ($this->reads() as $read) {
            try {
                $read($ledger);
                $this->fail('Nothing is configured, so nothing can be read.');
            } catch (LedgerUnreadable) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertFileDoesNotExist($marker, 'restic was never started without a repository');
    }

    #[Test]
    public function aListingThatIsNotAJsonArrayIsUnreadable(): void
    {
        foreach (['this is not json', '"a string"', '12'] as $output) {
            $unreadable = false;

            try {
                $this->ledger($this->script("echo '" . $output . "'"))->entries();
            } catch (LedgerUnreadable) {
                $unreadable = true;
            }

            $this->assertTrue($unreadable, 'A listing that is not an array of snapshots is unreadable: ' . $output);
        }
    }

    #[Test]
    public function aSnapshotWhoseTagsAreNotAListIsNotOneOfOurs(): void
    {
        $json = \json_encode(
            [['time' => '2026-11-17T21:11:10+01:00', 'tags' => 'erased-scope:x']],
            JSON_THROW_ON_ERROR,
        );

        $this->assertSame([], $this->ledger($this->script("echo '" . $json . "'"))->entries());
    }

    /**
     * The two reads of the ledger, each as a call on one adapter.
     *
     * @return list<callable(ResticErasureLedger): mixed>
     */
    private function reads(): array
    {
        return [
            static fn (ResticErasureLedger $ledger): array => $ledger->entries(),
            static function (ResticErasureLedger $ledger): void {
                $ledger->verifyReadableInFull();
            },
        ];
    }

    /**
     * @return array<mixed>
     */
    private function loggedContext(): array
    {
        $record = \array_first($this->log->getRecords());
        $this->assertInstanceOf(\Monolog\LogRecord::class, $record, 'the failure logged nothing');

        return $record->context;
    }

    private function loggedCause(): mixed
    {
        return $this->loggedContext()['cause'] ?? null;
    }

    private function ledger(string $binary, int $writeTimeout = 10): ResticErasureLedger
    {
        return new ResticErasureLedger(
            self::REPOSITORY,
            'restuser',
            's3cret-rest',
            's3cret-repo',
            null,
            $binary,
            $writeTimeout,
            new Logger('test', [$this->log]),
        );
    }

    private function script(string $body): string
    {
        $path = $this->directory . '/restic-' . \bin2hex(\random_bytes(3));
        \file_put_contents($path, "#!/bin/sh\n" . $body . "\n");
        \chmod($path, 0o755);

        return $path;
    }
}
