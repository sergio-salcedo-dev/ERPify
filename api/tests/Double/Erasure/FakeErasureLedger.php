<?php

declare(strict_types=1);

namespace Erpify\Tests\Double\Erasure;

use DateTimeImmutable;
use Erpify\Documents\Document\Application\Erasure\ErasureLedger;
use Erpify\Documents\Document\Application\Erasure\LedgerEntry;
use Erpify\Documents\Document\Application\Erasure\LedgerUnreadable;
use Erpify\Documents\Document\Application\Erasure\LedgerWriteFailed;
use Erpify\Documents\Document\Application\Erasure\LedgerWriteTimedOut;
use Erpify\Shared\Clock\Domain\SystemClock;
use Override;

/**
 * The erasure ledger dev and test bind: an in-process list of scope ids that can be scripted into every ending the
 * real one has. State is static for the reason `FakeMalwareScanner`'s is: the kernel reboots between requests, so a
 * script written to a property would be lost. {@see reset()} clears it.
 *
 * @SuppressWarnings("PHPMD.TooManyPublicMethods") one scripting method per ending the real ledger has
 */
final class FakeErasureLedger implements ErasureLedger
{
    /** @var array<string, DateTimeImmutable> scope id to the instant its first entry landed */
    private static array $entries = [];

    private static string $writeMode = 'normal';

    private static bool $unreadable = false;

    private static ?int $failAfter = null;

    /** @var list<string> every write attempted, landed or not, in order */
    private static array $attempts = [];

    public static function reset(): void
    {
        self::$entries = [];
        self::$writeMode = 'normal';
        self::$unreadable = false;
        self::$failAfter = null;
        self::$attempts = [];
    }

    /** The next writes fail before the entry is durable. */
    public static function failWrites(): void
    {
        self::$writeMode = 'fail';
    }

    /** The first `$successful` writes land; every later one fails before the entry is durable. */
    public static function failWritesAfter(int $successful): void
    {
        self::$failAfter = $successful;
    }

    /** The next writes time out and the entry did NOT land. */
    public static function timeOutWithoutLanding(): void
    {
        self::$writeMode = 'timeout';
    }

    /** The next writes time out although the entry DID land: the outcome is unknown to the caller. */
    public static function timeOutAfterLanding(): void
    {
        self::$writeMode = 'timeout-landed';
    }

    /** Listing and the full read fail, as a missing or corrupt entry makes them. */
    public static function makeUnreadable(): void
    {
        self::$unreadable = true;
    }

    /** Plants an entry as if a write had landed at `$at`, with no write attempted. */
    public static function plant(string $scopeId, ?DateTimeImmutable $at = null): void
    {
        self::$entries[$scopeId] ??= $at ?? SystemClock::now();
    }

    public static function has(string $scopeId): bool
    {
        return isset(self::$entries[$scopeId]);
    }

    /** @return list<string> */
    public static function attempts(): array
    {
        return self::$attempts;
    }

    #[Override]
    public function record(string $scopeId): void
    {
        self::$attempts[] = $scopeId;

        if (null !== self::$failAfter && \count(self::$attempts) > self::$failAfter) {
            throw LedgerWriteFailed::create();
        }

        match (self::$writeMode) {
            'fail' => throw LedgerWriteFailed::create(),
            'timeout' => throw LedgerWriteTimedOut::create(),
            'timeout-landed' => $this->landThenTimeOut($scopeId),
            default => self::plant($scopeId),
        };
    }

    /**
     * @return list<LedgerEntry>
     */
    #[Override]
    public function entries(): array
    {
        if (self::$unreadable) {
            throw LedgerUnreadable::create();
        }

        $entries = [];

        foreach (self::$entries as $scopeId => $at) {
            $entries[] = new LedgerEntry($scopeId, $at);
        }

        return $entries;
    }

    #[Override]
    public function verifyReadableInFull(): void
    {
        if (self::$unreadable) {
            throw LedgerUnreadable::create();
        }
    }

    private function landThenTimeOut(string $scopeId): never
    {
        self::plant($scopeId);

        throw LedgerWriteTimedOut::create();
    }
}
