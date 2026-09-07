<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional;

/**
 * Reduces an RFC 9457 refusal to the value that decides opacity: everything a client receives, minus the
 * members that are per-occurrence by contract rather than per-cause.
 *
 * A test asserting that two refusals are indistinguishable can only compare the WHOLE body — naming the
 * members by hand is how a `detail`, an extension, or a reordered payload diverges with every named
 * assertion still green. Three members are set aside: two that vary per occurrence, and one that names
 * the cause and is therefore dropped rather than substituted.
 *
 * - `instance` is a fresh UUIDv7 minted per error occurrence (`ExceptionResponder`), not anything derived
 *   from the request, so it differs even between two identical calls.
 * - `correlation-id` is the request's own id, and the server mints one per request with no way for a caller
 *   to supply it, so it differs between two calls for a reason that has nothing to do with the refusal.
 * - `debug` exists to name the cause. It is emitted under `dev`/`test` and omitted in `prod`, so it cannot
 *   make two answers differ on the deployed wire, and holding it to sameness would assert against its
 *   contract rather than for it. It is dropped rather than substituted, for that reason.
 *
 * Substituting the first two is only sound while their values are also asserted DISTINCT — answers sharing
 * one would be a correlation leak of its own, and this normalisation would hide it. That obligation is
 * discharged by {@see assertRefusalsAreIndistinguishable()} rather than left to each caller to remember: a
 * normalisation whose precondition lives in prose one file away is satisfied by whoever happens to read the
 * prose.
 *
 * @phpstan-require-extends \PHPUnit\Framework\TestCase
 */
trait ComparesOpaqueRefusals
{
    /** Substituted before the comparison and asserted distinct after it. */
    private const array PER_OCCURRENCE_MEMBERS = ['instance', 'correlation-id'];

    private const string CAUSE_NAMING_MEMBER = 'debug';

    /**
     * The comparable body, and the raw per-occurrence values this trait's own comparison asserts distinct.
     *
     * @return array{0: array<string, mixed>, 1: array<string, string>}
     */
    private function comparableRefusal(string $raw, string $case): array
    {
        $body = $this->decodedProblem($raw, $case);
        $perOccurrence = [];

        foreach (self::PER_OCCURRENCE_MEMBERS as $member) {
            $perOccurrence[$member] = $this->stringMember($body, $member, $case, $raw);
            $body[$member] = '<per-occurrence-' . $member . '>';
        }

        unset($body[self::CAUSE_NAMING_MEMBER]);

        return [$body, $perOccurrence];
    }

    /**
     * @return array<string, mixed>
     */
    private function decodedProblem(string $raw, string $case): array
    {
        $body = \json_decode($raw, true, flags: JSON_THROW_ON_ERROR);

        $this->assertIsArray($body, $case . ': ' . $raw);

        /** @var array<string, mixed> $body */
        return $body;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function stringMember(array $body, string $member, string $case, string $raw): string
    {
        $value = $body[$member] ?? null;

        $this->assertIsString($value, $case . ' is missing a string `' . $member . '`: ' . $raw);

        return $value;
    }

    /**
     * Every refusal answers identically, and each still carries its own per-occurrence values.
     *
     * The two halves are one method because they are one claim: the comparison is only meaningful over bodies
     * whose per-occurrence members have been substituted, and that substitution is only honest while the
     * originals differ. Splitting them lets a caller take the normalisation and skip its guard.
     *
     * @param array<array-key, array<string, mixed>> $answers       keyed by case, so a failure names which one
     * @param list<array<string, string>>            $perOccurrence one per answer, in the order they arrived
     */
    private function assertRefusalsAreIndistinguishable(array $answers, array $perOccurrence): void
    {
        // A single answer compares against itself, and an empty map compares nothing at all — either way the
        // identity assertions below hold vacuously, which is the one shape that makes this whole test lie.
        $this->assertGreaterThan(1, \count($answers), 'Fewer than two refusals were collected.');
        $this->assertCount(
            \count($answers),
            $perOccurrence,
            'An answer was collected without its per-occurrence members.',
        );

        $reference = \reset($answers);

        foreach ($answers as $case => $answer) {
            // Compared as whole arrays rather than member by member: an identical comparison over arrays also
            // holds the key ORDER, so a member added, dropped or moved on one answer fails here without
            // anyone having remembered to name it.
            $this->assertSame($reference, $answer, 'Refusal "' . $case . '" is distinguishable from the rest.');
        }

        foreach (self::PER_OCCURRENCE_MEMBERS as $member) {
            $values = \array_column($perOccurrence, $member);

            $this->assertCount(\count($perOccurrence), $values, 'An answer carried no `' . $member . '`.');
            $this->assertCount(
                \count($values),
                \array_unique($values),
                'Two refusals share one `' . $member . '`, which is a leak the substitution above would hide.',
            );
        }
    }
}
