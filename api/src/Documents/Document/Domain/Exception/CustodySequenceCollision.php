<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Domain\Exception;

use RuntimeException;

/**
 * Another transaction committed a custody row with the sequence number this one computed: `UNIQUE (document_id,
 * seq)` fired. The sequence is read under the lock on the document row, so reaching the constraint means the
 * lock did not serialise the two appenders, and the whole unit of work has to run again — a fresh lock, a
 * fresh `MAX(seq)`, a fresh insert — never only the INSERT, which would repeat a number already computed from
 * a stale read.
 *
 * Internal to the append use case, which retries it a bounded number of times and then answers
 * {@see \Erpify\Shared\Persistence\Domain\Exception\TransientTransactionFailure}. It is deliberately not a
 * `DomainException`: no HTTP answer maps to it, and a marker would put it in the error contract as though a
 * client could choose to do something about it.
 */
final class CustodySequenceCollision extends RuntimeException
{
    public static function create(): self
    {
        return new self('A concurrent transaction committed the same custody sequence number.');
    }
}
