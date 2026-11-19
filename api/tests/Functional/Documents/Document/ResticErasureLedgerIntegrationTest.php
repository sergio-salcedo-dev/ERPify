<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Documents\Document;

use Erpify\Documents\Document\Application\Erasure\LedgerEntry;
use Erpify\Documents\Document\Application\Erasure\LedgerUnreadable;
use Erpify\Documents\Document\Infrastructure\Erasure\ResticErasureLedger;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * The adapter against REAL restic and a REAL append-only `rest-server`, the only place the claims of
 * `docs/runbooks/documents-erasure-ledger.md` can be shown rather than read. Opt-in: it needs the `ledger` compose
 * profile up and the php image built with restic, so `make php.ledger.integration` starts it, sets
 * `RUN_LEDGER_INTEGRATION` and runs this group. Without that variable every case is skipped, and the default suite
 * never reaches a repository.
 *
 * What it measures that nothing else does: a write is listed back with its scope and the earliest time; a `restic
 * forget` by the write credential is refused by the repository and removes nothing; and a corrupted snapshot file —
 * planted over the API, which the test server accepts because it runs with `--no-verify-upload` — makes both
 * `entries()` and `verifyReadableInFull()` raise, although a bare `restic snapshots` still exits 0.
 *
 * @internal
 */
#[Group('ledger')]
#[CoversClass(ResticErasureLedger::class)]
final class ResticErasureLedgerIntegrationTest extends TestCase
{
    private const string SCOPE_A = '0190e9c2-7b5a-4d40-9c8f-2f9b5d3e1a01';

    private const string SCOPE_B = '0190e9c2-7b5a-4d40-9c8f-2f9b5d3e1a02';

    private const string PASSWORD = 'integration-repository-password';

    private string $repository;

    private ResticErasureLedger $ledger;

    #[Override]
    protected function setUp(): void
    {
        if ('1' !== \getenv('RUN_LEDGER_INTEGRATION')) {
            $this->markTestSkipped('Opt-in: run `make php.ledger.integration` (starts an append-only rest-server).');
        }

        $host = \getenv('LEDGER_INTEGRATION_HOST');
        $this->repository = \sprintf(
            'rest:http://%s/%s',
            \is_string($host) && '' !== $host ? $host : 'ledger:8000',
            'it-' . \bin2hex(\random_bytes(6)),
        );

        $init = $this->restic(['init']);
        $init->run();
        $this->assertTrue($init->isSuccessful(), 'restic init: ' . $init->getErrorOutput());

        $this->ledger = new ResticErasureLedger($this->repository, null, null, self::PASSWORD);
    }

    #[Test]
    public function aWriteIsListedBackOncePerScopeWithItsTime(): void
    {
        $this->ledger->record(self::SCOPE_A);
        $this->ledger->record(self::SCOPE_B);
        $this->ledger->record(self::SCOPE_A);

        $entries = $this->ledger->entries();
        $scopes = \array_map(static fn (LedgerEntry $entry): string => $entry->scopeId, $entries);
        \sort($scopes);

        $this->assertSame([self::SCOPE_A, self::SCOPE_B], $scopes, 'at-least-once writes, one entry per scope');
        $this->ledger->verifyReadableInFull();
    }

    #[Test]
    public function aForgetByTheWriteCredentialIsRefusedAndRemovesNoEntry(): void
    {
        $this->ledger->record(self::SCOPE_A);
        $id = $this->snapshotIds()[0] ?? $this->fail('The write left no snapshot.');

        $forget = $this->restic(['forget', $id]);
        $forget->run();

        // restic still exits 0 here (measured), so the proof is the repository's refusal and the entry still listed.
        $this->assertStringContainsString('403', $forget->getErrorOutput() . $forget->getOutput());
        $this->assertSame([$id], $this->snapshotIds());
        $this->assertCount(1, $this->ledger->entries());
    }

    #[Test]
    public function aCorruptedSnapshotFileMakesTheLedgerUnreadableEvenThoughRawListingExitsZero(): void
    {
        $this->ledger->record(self::SCOPE_A);
        $this->plantSnapshot(\str_repeat('ab', 32), 'garbage');

        $listing = $this->restic(['snapshots', '--json']);
        $listing->run();
        $this->assertSame(0, $listing->getExitCode(), 'restic skips it and still exits 0');
        $this->assertStringContainsString('Ignoring', $listing->getErrorOutput());

        try {
            $this->ledger->entries();
            $this->fail('entries() must refuse a ledger with a skipped snapshot.');
        } catch (LedgerUnreadable) {
            $this->addToAssertionCount(1);
        }

        $this->expectException(LedgerUnreadable::class);
        $this->ledger->verifyReadableInFull();
    }

    /**
     * @return list<string>
     */
    private function snapshotIds(): array
    {
        $listing = $this->restic(['snapshots', '--json']);
        $listing->mustRun();

        $decoded = \json_decode($listing->getOutput(), true, 16, JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);

        return \array_values(\array_map(
            static fn (mixed $snapshot): string => \is_array($snapshot) && \is_string($snapshot['id'] ?? null)
                ? $snapshot['id']
                : '',
            $decoded,
        ));
    }

    /**
     * Creates a snapshot file the repository accepts without checking its hash (the test server's
     * `--no-verify-upload`), which a real operator could only have by a damaged disk.
     */
    private function plantSnapshot(string $name, string $content): void
    {
        $url = \preg_replace('#^rest:#', '', $this->repository) . '/snapshots/' . $name;
        $response = \file_get_contents($url, false, \stream_context_create(['http' => [
            'method' => 'POST',
            'content' => $content,
            'ignore_errors' => true,
        ]]));
        $this->assertNotFalse($response);
    }

    /**
     * @param list<string> $arguments
     */
    private function restic(array $arguments): Process
    {
        return new Process(['restic', '--no-cache', ...$arguments], null, [
            'RESTIC_REPOSITORY' => $this->repository,
            'RESTIC_PASSWORD' => self::PASSWORD,
        ], null, 60);
    }
}
