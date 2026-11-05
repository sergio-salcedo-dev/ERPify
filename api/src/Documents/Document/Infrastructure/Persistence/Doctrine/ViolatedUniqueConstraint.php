<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Infrastructure\Persistence\Doctrine;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Throwable;

/**
 * Says whether a failure is a unique violation of one NAMED constraint, so the caller classifies it by the name
 * it gave that constraint and not by a sentence of the driver's.
 *
 * DBAL translates the SQLSTATE to {@see UniqueConstraintViolationException} but exposes nothing about WHICH
 * constraint, and PDO has no structured field for it: the name is somewhere in the server's message and nowhere
 * else. The check is therefore locale-independent: it asks whether the driver message CONTAINS the exact name,
 * delimited on both sides by a character that cannot continue an identifier (not `[A-Za-z0-9_$]`), and never
 * parses the surrounding wording — which `lc_messages` translates, and which would turn a lost race into a 500.
 * The delimiters are what keep a column name (`idempotency_key_hash` inside `uniq_document_idempotency_key_hash`)
 * or a longer constraint name from reading as this one. Anything that is not a unique violation at all answers
 * false, and the caller rethrows the original: a failure is never silently reclassified.
 *
 * The driver text is read and dropped. It carries the offending key (`DETAIL: Key (idempotency_key_hash)=…`),
 * which is why the exceptions the callers raise instead do not chain it.
 */
final class ViolatedUniqueConstraint
{
    private function __construct()
    {
    }

    public static function is(Throwable $failure, string $constraintName): bool
    {
        if (!$failure instanceof UniqueConstraintViolationException) {
            return false;
        }

        $driver = $failure->getPrevious();

        if (!$driver instanceof Throwable || '' === $constraintName) {
            return false;
        }

        $pattern = '/(?<![A-Za-z0-9_$])' . \preg_quote($constraintName, '/') . '(?![A-Za-z0-9_$])/';

        return 1 === \preg_match($pattern, $driver->getMessage());
    }
}
