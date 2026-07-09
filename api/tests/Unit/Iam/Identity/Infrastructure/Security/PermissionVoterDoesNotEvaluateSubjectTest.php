<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Infrastructure\Security;

use Erpify\Iam\Identity\Infrastructure\Security\PermissionVoter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * The static half of ADR D9's second tripwire: the voter accepts `#[IsGranted('bank.read', subject: $bank)]`
 * but must never read that subject to decide — the day it does, RBAC has become ABAC.
 *
 * This scan reads the voter's own source and asserts the `$subject` parameter is only ever *declared*, never
 * read in a body — a fast structural check across the whole method space, aimed at the accidental drift a
 * reviewer most easily misses: an `if ($subject instanceof …)` written with the parameter's canonical name.
 * It is deliberately *paired with*, not "stronger than", the behavioural property test
 * {@see PermissionVoterTest::testTheDecisionIsIndependentOfTheSubject}. The scan matches the literal token
 * `$subject`, so a `Voter` override that renamed its second parameter would read `$other` unseen; that gap is
 * closed on two sides — {@see self::testTheSubjectParameterKeepsItsCanonicalName} pins the parameter name so
 * the token match stays sound, and the behavioural test (vote invariant under a varying subject) catches
 * renamed or dynamic reads no source scan can see. Same token-source technique as
 * `StaticAuthorizationPolicyIsDataOnlyTest` (`nikic/php-parser` is not on the app autoload). A prose mention
 * of the variable in a docblock is a comment token, never a `T_VARIABLE`, so it cannot false-trip.
 *
 * @internal
 */
#[CoversClass(PermissionVoter::class)]
final class PermissionVoterDoesNotEvaluateSubjectTest extends TestCase
{
    private const string SUBJECT_VARIABLE = '$subject';

    public function testTheVoterNeverReadsTheSubjectToDecide(): void
    {
        $reads = $this->subjectReads();

        $this->assertSame([], $reads, \sprintf(
            'PermissionVoter must never read %s to decide (ADR D9: the row-level door stays open but '
            . 'unevaluated); reading it turns this RBAC voter into ABAC. Found %d read(s).',
            self::SUBJECT_VARIABLE,
            \count($reads),
        ));
    }

    public function testTheSubjectParameterKeepsItsCanonicalName(): void
    {
        // The read scan matches the literal `$subject`, and a Voter override may legally rename its
        // second parameter — a body read of the renamed variable would then slip past unseen. Pin the name
        // on both decision surfaces so the scan stays sound; this is also the anti-vacuous guard, since a
        // dropped subject parameter fails here loudly rather than letting the read check pass trivially.
        foreach (['supports', 'voteOnAttribute'] as $method) {
            $subject = (new ReflectionMethod(PermissionVoter::class, $method))->getParameters()[1] ?? null;

            if (null === $subject) {
                $this->fail(\sprintf('%s() must declare a subject parameter at position 1.', $method));
            }

            $this->assertSame('subject', $subject->getName(), \sprintf(
                '%s() renamed its position-1 subject parameter; the source read scan matches `$subject`, so '
                . 'a rename would open an ABAC escape hatch. Keep the parameter named `subject`.',
                $method,
            ));
        }
    }

    /**
     * Source token indices where the voter *reads* `$subject` — the variable appears outside any function's
     * parameter list. Flat state machine: it arms on a `function`/`fn` keyword, opens the depth on that
     * signature's first `(`, closes it on the matching `)`, and records each `$subject` token against the
     * parameter-list depth it sits at; the depth-zero ones are the body reads. Declarations (depth > 0) are
     * filtered out here; {@see self::testTheSubjectParameterKeepsItsCanonicalName} — not a declaration count —
     * is what keeps the read check from passing vacuously.
     *
     * @return list<int>
     */
    private function subjectReads(): array
    {
        $depthAtSubject = [];
        $parameterDepth = 0;
        $atSignature = false;

        foreach ($this->voterSourceTokens() as $index => $token) {
            if ($this->isFunctionKeyword($token)) {
                $atSignature = true;
            } elseif ('(' === $token && ($atSignature || $parameterDepth > 0)) {
                ++$parameterDepth;
                $atSignature = false;
            } elseif (')' === $token && $parameterDepth > 0) {
                --$parameterDepth;
            } elseif ($this->isSubjectVariable($token)) {
                $depthAtSubject[$index] = $parameterDepth;
            }
        }

        return \array_keys(\array_filter($depthAtSubject, static fn (int $depth): bool => 0 === $depth));
    }

    /**
     * @return list<array{int, string, int}|string>
     */
    private function voterSourceTokens(): array
    {
        $file = (new ReflectionClass(PermissionVoter::class))->getFileName();
        $this->assertIsString($file);

        $source = \file_get_contents($file);
        $this->assertIsString($source);

        return \token_get_all($source);
    }

    /**
     * @param array{int, string, int}|string $token
     */
    private function isFunctionKeyword(array|string $token): bool
    {
        return \is_array($token) && \in_array($token[0], [T_FUNCTION, T_FN], true);
    }

    /**
     * @param array{int, string, int}|string $token
     */
    private function isSubjectVariable(array|string $token): bool
    {
        return \is_array($token) && T_VARIABLE === $token[0] && self::SUBJECT_VARIABLE === $token[1];
    }
}
