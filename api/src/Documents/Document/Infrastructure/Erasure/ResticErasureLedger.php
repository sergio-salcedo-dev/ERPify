<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Infrastructure\Erasure;

use DateTimeImmutable;
use Erpify\Documents\Document\Application\Erasure\ErasureLedger;
use Erpify\Documents\Document\Application\Erasure\LedgerEntry;
use Erpify\Documents\Document\Application\Erasure\LedgerUnreadable;
use Erpify\Documents\Document\Application\Erasure\LedgerWriteFailed;
use Erpify\Documents\Document\Application\Erasure\LedgerWriteTimedOut;
use Erpify\Documents\Document\Domain\Erasure\ErasureLimits;
use Erpify\Shared\Uuid\Domain\Uuid;
use JsonException;
use Override;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * The erasure ledger over a restic repository behind an append-only REST server on a second host
 * (`docs/adr/documents-custody-log.md` D11). One entry is one snapshot whose tag carries the scope id and whose
 * content is the scope id too, so `restic check --read-data` has bytes to read.
 *
 * **Secrets never travel on argv or in the URL.** The repository address, the REST username and password and the
 * repository password reach the child as `RESTIC_*` environment variables (`RESTIC_PASSWORD_FILE` when a secret file
 * is configured), and the command line holds only the sub-command, its fixed flags and the scope id — a random
 * identifier, not a person's. restic quotes the repository URL in its own errors, so no message, exit code detail or
 * stderr leaves this class: a failure is one of three typed exceptions that carry no cause.
 *
 * **"Read in full" is two checks, because one is not enough.** `restic check --read-data` exits non-zero on a
 * corrupt snapshot file, but `restic snapshots` skips one it cannot load, prints `Ignoring "<id>": …` on stderr and
 * exits 0 — so a listing alone would call a ledger with a missing entry readable. {@see entries()} therefore refuses
 * any `Ignoring` line, and {@see verifyReadableInFull()} adds the full read. `--no-cache` on every call: a local cache
 * would answer from a copy of a file the repository no longer holds.
 *
 * It cannot delete: the server is append-only, so a `restic forget` is answered 403 by the repository, and this class
 * issues none.
 */
#[AsAlias(ErasureLedger::class)]
final readonly class ResticErasureLedger implements ErasureLedger
{
    private const string TAG_PREFIX = 'erased-scope:';

    private const string SNAPSHOT_HOST = 'erpify';

    private const int LIST_TIMEOUT_SECONDS = 60;

    private const int CHECK_TIMEOUT_SECONDS = 900;

    public function __construct(
        #[Autowire(env: 'default::DOCUMENTS_ERASURE_LEDGER_REPOSITORY')]
        private ?string $repository,
        #[Autowire(env: 'default::DOCUMENTS_ERASURE_LEDGER_REST_USERNAME')]
        private ?string $restUsername,
        #[Autowire(env: 'default::DOCUMENTS_ERASURE_LEDGER_REST_PASSWORD')]
        private ?string $restPassword,
        #[Autowire(env: 'default::DOCUMENTS_ERASURE_LEDGER_PASSWORD')]
        private ?string $repositoryPassword,
        #[Autowire(env: 'default::DOCUMENTS_ERASURE_LEDGER_PASSWORD_FILE')]
        private ?string $repositoryPasswordFile = null,
        private string $binary = 'restic',
        private int $writeTimeoutSeconds = ErasureLimits::LEDGER_WRITE_TIMEOUT_SECONDS,
        #[Autowire(service: 'monolog.logger.observability')]
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    #[Override]
    public function record(string $scopeId): void
    {
        $process = $this->process(
            [
                'backup',
                '--stdin',
                '--stdin-filename', 'scope',
                '--host', self::SNAPSHOT_HOST,
                '--tag', self::TAG_PREFIX . $scopeId,
                '--quiet',
            ],
            $this->writeTimeoutSeconds,
            $scopeId,
        );

        if (!$this->configured()) {
            $this->logCause('not-configured');

            throw LedgerWriteFailed::create();
        }

        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            $this->logCause('timeout');

            throw LedgerWriteTimedOut::create();
        } catch (Throwable) {
            $this->logCause('cannot-start');

            throw LedgerWriteFailed::create();
        }

        if (!$process->isSuccessful()) {
            $this->logCause($this->causeOf($process));

            throw LedgerWriteFailed::create();
        }
    }

    #[Override]
    public function entries(): array
    {
        $output = $this->listSnapshots();

        try {
            $snapshots = \json_decode($output, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw LedgerUnreadable::create();
        }

        if (!\is_array($snapshots)) {
            throw LedgerUnreadable::create();
        }

        $earliest = [];

        foreach ($snapshots as $snapshot) {
            $entry = $this->entryOf($snapshot);

            if (!$entry instanceof LedgerEntry) {
                continue;
            }

            $known = $earliest[$entry->scopeId] ?? null;

            if (!$known instanceof LedgerEntry || $entry->recordedAt < $known->recordedAt) {
                $earliest[$entry->scopeId] = $entry;
            }
        }

        return \array_values($earliest);
    }

    #[Override]
    public function verifyReadableInFull(): void
    {
        $check = $this->process(['check', '--read-data'], self::CHECK_TIMEOUT_SECONDS);

        if (!$this->configured()) {
            throw LedgerUnreadable::create();
        }

        try {
            $check->run();
        } catch (Throwable) {
            throw LedgerUnreadable::create();
        }

        if (!$check->isSuccessful()) {
            throw LedgerUnreadable::create();
        }

        $this->listSnapshots();
    }

    /**
     * @throws LedgerUnreadable
     */
    private function listSnapshots(): string
    {
        $process = $this->process(['snapshots', '--json'], self::LIST_TIMEOUT_SECONDS);

        if (!$this->configured()) {
            throw LedgerUnreadable::create();
        }

        try {
            $process->run();
        } catch (Throwable) {
            throw LedgerUnreadable::create();
        }

        // `Ignoring "<id>": …` is what restic prints for a snapshot it could not load and skipped, with exit 0.
        if (!$process->isSuccessful() || \str_contains($process->getErrorOutput(), 'Ignoring')) {
            throw LedgerUnreadable::create();
        }

        return $process->getOutput();
    }

    /**
     * The entry a snapshot is, or null when it is not one of ours (it carries no scope tag). **A snapshot that IS ours
     * and cannot be read as an entry — a tag whose scope is not a lower-case UUID, a missing or unparseable time —
     * makes the ledger unreadable and is never skipped**: skipping it would let a damaged entry pass for a ledger that
     * is whole, which is the one thing "read in full" must not do.
     *
     * @throws LedgerUnreadable
     */
    private function entryOf(mixed $snapshot): ?LedgerEntry
    {
        if (!\is_array($snapshot)) {
            throw LedgerUnreadable::create();
        }

        $scopeId = $this->scopeTagOf($snapshot['tags'] ?? []);

        if (null === $scopeId) {
            return null;
        }

        $time = $snapshot['time'] ?? null;

        if (!Uuid::isValid($scopeId) || \strtolower($scopeId) !== $scopeId || !\is_string($time)) {
            throw LedgerUnreadable::create();
        }

        try {
            return new LedgerEntry($scopeId, new DateTimeImmutable($time));
        } catch (Throwable) {
            throw LedgerUnreadable::create();
        }
    }

    /** The text after the scope prefix of the first scope tag, or null when the snapshot carries none. */
    private function scopeTagOf(mixed $tags): ?string
    {
        if (!\is_array($tags)) {
            return null;
        }

        foreach ($tags as $tag) {
            if (\is_string($tag) && \str_starts_with($tag, self::TAG_PREFIX)) {
                return \substr($tag, \strlen(self::TAG_PREFIX));
            }
        }

        return null;
    }

    /** One slug per way a write fails: enough to tell a missing binary from a refused credential, never stderr. */
    private function causeOf(Process $process): string
    {
        $error = $process->getErrorOutput();

        return match (true) {
            127 === $process->getExitCode() => 'binary-missing',
            \str_contains($error, '403') => 'forbidden',
            \str_contains($error, '401') => 'unauthorized',
            default => 'exit-' . $process->getExitCode(),
        };
    }

    private function logCause(string $cause): void
    {
        $this->logger->error('The erasure ledger write failed.', ['cause' => $cause]);
    }

    private function configured(): bool
    {
        return \is_string($this->repository) && '' !== $this->repository
            && ((\is_string($this->repositoryPassword) && '' !== $this->repositoryPassword)
                || (\is_string($this->repositoryPasswordFile) && '' !== $this->repositoryPasswordFile));
    }

    /**
     * @param list<string> $arguments
     */
    private function process(array $arguments, int $timeoutSeconds, ?string $input = null): Process
    {
        $environment = [
            'RESTIC_REPOSITORY' => (string) $this->repository,
            'RESTIC_REST_USERNAME' => (string) $this->restUsername,
            'RESTIC_REST_PASSWORD' => (string) $this->restPassword,
            'RESTIC_PASSWORD' => (string) $this->repositoryPassword,
            'RESTIC_PASSWORD_FILE' => (string) $this->repositoryPasswordFile,
        ];

        // An empty variable would be read as set, and one the parent process holds would be inherited: `false` unsets
        // it, so a secret file and a password are never both present.
        $environment = \array_map(
            static fn (string $value): false|string => '' === $value ? false : $value,
            $environment,
        );

        $command = [$this->binary, '--no-cache', ...$arguments];
        $process = new Process($command, null, $environment, $input, $timeoutSeconds);
        $process->setIdleTimeout(null);

        return $process;
    }
}
