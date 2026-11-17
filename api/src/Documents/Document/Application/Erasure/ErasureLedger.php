<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Application\Erasure;

/**
 * The append-only record of which scopes were ordered erased, kept off the database's failure and restore domain so a
 * restore can never bring a destroyed key back unnoticed (`docs/adr/documents-custody-log.md` D11).
 *
 * It holds scope ids only: random, never derivable from a document id or a person. Entries are at-least-once, so a
 * retry writes a second one and every reader deduplicates by scope id. An entry is a write-ahead intent that is
 * completed forward and never rolled back, and nothing in this port deletes one.
 */
interface ErasureLedger
{
    /**
     * Makes an entry for the scope durable, within a bounded time, and returns only once it is.
     *
     * @throws LedgerWriteFailed   when the entry is certainly not durable (the caller may retry from nothing)
     * @throws LedgerWriteTimedOut when the outcome is unknown: the entry may or may not have landed
     */
    public function record(string $scopeId): void;

    /**
     * Every scope in the ledger, once each, with the instant its first entry was written (stamped by restic on the
     * writing host).
     *
     * @throws LedgerUnreadable when the ledger cannot be listed or an entry in it cannot be read
     *
     * @return list<LedgerEntry>
     */
    public function entries(): array;

    /**
     * Proves the whole ledger readable: every byte of every entry, not only its listing. The listing alone can skip
     * an entry it fails to read and still succeed, so a restore asks this before it trusts {@see entries()}.
     *
     * @throws LedgerUnreadable
     */
    public function verifyReadableInFull(): void;
}
