<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional;

/**
 * Reduces an RFC 9457 refusal to the value that decides opacity: everything a client receives, minus the two
 * members that are per-occurrence by contract rather than per-cause.
 *
 * A test asserting that two refusals are indistinguishable can only compare the WHOLE body — naming the
 * members by hand is how a `detail`, an extension, or a reordered payload diverges with every named
 * assertion still green. But two members always differ and neither reveals a cause:
 *
 * - `instance` is a fresh UUIDv7 minted per error occurrence (`ExceptionResponder`), not anything derived
 *   from the request, so it differs even between two identical calls. Substituting it is only sound while
 *   the values are also asserted DISTINCT — answers sharing one would be a correlation leak of its own, and
 *   this normalisation would hide it. That obligation is discharged by {@see assertRefusalsAreIndistinguishable()}
 *   rather than left to each caller to remember: a normalisation whose precondition lives in prose one file
 *   away is satisfied by whoever happens to read the prose.
 * - `debug` exists to name the cause. It is emitted under `dev`/`test` and omitted in `prod`, so it cannot
 *   make two answers differ on the deployed wire, and holding it to sameness would assert against its
 *   contract rather than for it.
 *
 * `correlation-id` is NOT normalised here, deliberately: it is echoed from `X-Correlation-Id` when the caller
 * sends a canonical lowercase UUIDv7 and minted per request otherwise. A caller of this trait therefore has
 * to pin it on the request, and a caller that forgets gets a red rather than a silent exemption — which is
 * the right way round, since an unpinned correlation id is the one member that would differ for a reason
 * having nothing to do with the refusal.
 *
 * @phpstan-require-extends \PHPUnit\Framework\TestCase
 */
trait ComparesOpaqueRefusals
{
    private const string INSTANCE_PLACEHOLDER = '<per-occurrence-instance>';

    private const string CAUSE_NAMING_MEMBER = 'debug';

    /**
     * The comparable body, and the raw `instance` this trait's own comparison asserts distinct.
     *
     * @return array{0: array<string, mixed>, 1: string}
     */
    private function comparableRefusal(string $raw, string $case): array
    {
        $body = $this->decodedProblem($raw, $case);
        $instance = $this->instanceOf($body, $case, $raw);

        $body['instance'] = self::INSTANCE_PLACEHOLDER;
        unset($body[self::CAUSE_NAMING_MEMBER]);

        return [$body, $instance];
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
    private function instanceOf(array $body, string $case, string $raw): string
    {
        $instance = $body['instance'] ?? null;

        $this->assertIsString($instance, $case . ': ' . $raw);

        return $instance;
    }

    /**
     * Every refusal answers identically, and each still carries its own `instance`.
     *
     * The two halves are one method because they are one claim: the comparison is only meaningful over bodies
     * whose `instance` has been substituted, and that substitution is only honest while the originals differ.
     * Splitting them lets a caller take the normalisation and skip its guard.
     *
     * @param array<array-key, array<string, mixed>> $answers   keyed by case, so a failure names which one
     * @param list<string>                           $instances one per answer, in the order they arrived
     */
    private function assertRefusalsAreIndistinguishable(array $answers, array $instances): void
    {
        // A single answer compares against itself, and an empty map compares nothing at all — either way the
        // identity assertions below hold vacuously, which is the one shape that makes this whole test lie.
        $this->assertGreaterThan(1, \count($answers), 'Fewer than two refusals were collected.');
        $this->assertCount(\count($answers), $instances, 'An answer was collected without its instance.');

        $reference = \reset($answers);

        foreach ($answers as $case => $answer) {
            // Compared as whole arrays rather than member by member: an identical comparison over arrays also
            // holds the key ORDER, so a member added, dropped or moved on one answer fails here without
            // anyone having remembered to name it.
            $this->assertSame($reference, $answer, 'Refusal "' . $case . '" is distinguishable from the rest.');
        }

        $this->assertCount(
            \count($instances),
            \array_unique($instances),
            'Two refusals share one `instance`, which is a correlation leak the substitution above would hide.',
        );
    }
}
