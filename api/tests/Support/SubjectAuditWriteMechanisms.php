<?php

declare(strict_types=1);

namespace Erpify\Tests\Support;

/**
 * Names how each `AuditResource::of(FulfilIdentityErasure::SUBJECT_RESOURCE_TYPE, …)` write in a source is
 * serialised with the subject's `identity_user` row, read from the call shapes around it:
 *  - `row-lock` — the write, or a method every call of which, sits inside the argument list of `->whileHeld(` or
 *    `->whileHeldByEmail(`;
 *  - `locking-read` — every call of the method holding the write sits inside a `->transactional(` argument list,
 *    after a `->…ForUpdate(` call in that same list;
 *  - `creates-subject` — every call of it follows a `User::invite(` in the same method;
 *  - `unserialised` — none of these.
 *
 * Lexical, and nothing more: it never asks which row a `ForUpdate` read locks, nor follows a call through a
 * callable, a trait or another file.
 *
 * @internal test support
 */
final class SubjectAuditWriteMechanisms
{
    public const string ROW_LOCK = 'row-lock';

    public const string LOCKING_READ = 'locking-read';

    public const string CREATES_SUBJECT = 'creates-subject';

    public const string UNSERIALISED = 'unserialised';

    /**
     * @return list<string> one mechanism per subject write, in source order
     */
    public static function of(string $source): array
    {
        $tokens = new SignificantPhpTokens($source);
        $lockSpans = self::argumentSpans($tokens, ['whileHeld', 'whileHeldByEmail']);
        $mechanisms = [];

        foreach ($tokens->indices() as $index) {
            if (self::isSubjectWriteAt($tokens, $index)) {
                $mechanisms[] = self::mechanismAt($tokens, $index, $lockSpans);
            }
        }

        return $mechanisms;
    }

    /**
     * @param list<array{int, int}> $lockSpans
     */
    private static function mechanismAt(SignificantPhpTokens $tokens, int $site, array $lockSpans): string
    {
        if (self::within($site, $lockSpans)) {
            return self::ROW_LOCK;
        }

        $callers = self::callsOf($tokens, $tokens->enclosingFunctionAt($site));

        return match (true) {
            [] === $callers => self::UNSERIALISED,
            \array_all($callers, static fn (int $call): bool => self::within($call, $lockSpans)) => self::ROW_LOCK,
            \array_all($callers, static fn (int $call): bool => self::afterALockingRead($tokens, $call))
                => self::LOCKING_READ,
            \array_all($callers, static fn (int $call): bool => self::afterAnInvite($tokens, $call))
                => self::CREATES_SUBJECT,
            default => self::UNSERIALISED,
        };
    }

    private static function afterALockingRead(SignificantPhpTokens $tokens, int $call): bool
    {
        foreach (self::argumentSpans($tokens, ['transactional']) as [$open, $close]) {
            if ($call <= $open || $call >= $close) {
                continue;
            }

            for ($index = $open; $index < $call; ++$index) {
                if ($tokens->isMethodCallAt($index)
                    && \str_ends_with(\strtolower($tokens->textAt($index + 1)), 'forupdate')) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function afterAnInvite(SignificantPhpTokens $tokens, int $call): bool
    {
        for ($index = $tokens->enclosingFunctionAt($call); $index < $call; ++$index) {
            if ('User' === $tokens->textAt($index) && T_DOUBLE_COLON === $tokens->idAt($index + 1)
                && 0 === \strcasecmp('invite', $tokens->textAt($index + 2))) {
                return true;
            }
        }

        return false;
    }

    /**
     * The argument list of every `->name(` call, as the indices of its opening and closing parenthesis.
     *
     * @param list<string> $names
     *
     * @return list<array{int, int}>
     */
    private static function argumentSpans(SignificantPhpTokens $tokens, array $names): array
    {
        $spans = [];

        foreach ($tokens->indices() as $index) {
            if ($tokens->isMethodCallAt($index) && \in_array($tokens->textAt($index + 1), $names, true)) {
                $spans[] = [$index + 2, $tokens->closingParenthesisOf($index + 2)];
            }
        }

        return $spans;
    }

    /**
     * Every `$this->name(` call of the named function declared at `$declaration`; none when there is none.
     *
     * @return list<int>
     */
    private static function callsOf(SignificantPhpTokens $tokens, int $declaration): array
    {
        if (0 === $declaration) {
            return [];
        }

        $method = $tokens->textAt($declaration + 1);

        return \array_values(\array_filter(
            $tokens->indices(),
            static fn (int $index): bool => '$this' === $tokens->textAt($index - 1)
                && $tokens->isMethodCallAt($index)
                && 0 === \strcasecmp($method, $tokens->textAt($index + 1)),
        ));
    }

    private static function isSubjectWriteAt(SignificantPhpTokens $tokens, int $index): bool
    {
        return $tokens->namesClassAt($index, 'AuditResource')
            && T_DOUBLE_COLON === $tokens->idAt($index + 1)
            && 0 === \strcasecmp('of', $tokens->textAt($index + 2))
            && '(' === $tokens->textAt($index + 3)
            && $tokens->namesClassAt($index + 4, 'FulfilIdentityErasure')
            && T_DOUBLE_COLON === $tokens->idAt($index + 5)
            && 'SUBJECT_RESOURCE_TYPE' === $tokens->textAt($index + 6);
    }

    /**
     * @param list<array{int, int}> $spans
     */
    private static function within(int $index, array $spans): bool
    {
        return \array_any($spans, static fn (array $span): bool => $index > $span[0] && $index < $span[1]);
    }
}
