<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Infrastructure\Security;

use Erpify\Iam\Identity\Infrastructure\Security\PermissionVoter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * The expensive half of the second OCP tripwire (ADR D9): the voter accepts `#[IsGranted('bank.read',
 * subject: $bank)]` but must never read that subject to decide — the day it does, RBAC has become ABAC.
 *
 * A behavioural test already proves this for a couple of subjects
 * ({@see PermissionVoterTest::testItDoesNotReadTheSubject}, exercising `null` and an object). This one is
 * strictly stronger: it reads the voter's own source and asserts `$subject` appears only where it is
 * *declared* as a parameter, never in a body — so it holds for every possible subject and would catch an
 * `if ($subject instanceof …)` slipped into `voteOnAttribute` even if that branch happened to keep the
 * behavioural test green. Same token-source technique as `StaticAuthorizationPolicyIsDataOnlyTest`
 * (`nikic/php-parser` is not on the app autoload). A prose mention of the variable in a docblock is a single
 * comment token, never a `T_VARIABLE`, so it cannot false-trip.
 *
 * @internal
 */
#[CoversClass(PermissionVoter::class)]
final class PermissionVoterDoesNotEvaluateSubjectTest extends TestCase
{
    private const string SUBJECT_VARIABLE = '$subject';

    public function testTheVoterNeverReadsTheSubjectToDecide(): void
    {
        $reads = $this->subjectOccurrences()['reads'];

        $this->assertSame([], $reads, \sprintf(
            'PermissionVoter must never read %s to decide (ADR D9: the row-level door stays open but '
            . 'unevaluated); reading it turns this RBAC voter into ABAC. Found %d read(s).',
            self::SUBJECT_VARIABLE,
            \count($reads),
        ));
    }

    public function testTheVoterStillDeclaresTheSubjectParameter(): void
    {
        // Guard against silent rot: if the signatures stopped declaring $subject the read check above would
        // pass vacuously. Pin that the parameter still exists, so a dropped subject fails loudly instead.
        $this->assertGreaterThan(0, $this->subjectOccurrences()['declarations']);
    }

    /**
     * Splits every `$subject` token in the voter's source into parameter *declarations* (inside a function's
     * parameter parentheses) and *reads* (anywhere else). The scan tracks only whether the current token sits
     * inside a parameter list: it arms on a `function`/`fn` keyword, opens the depth on that signature's first
     * `(`, and any `$subject` seen at depth zero is a body read.
     *
     * @return array{declarations: int, reads: list<int>}
     */
    private function subjectOccurrences(): array
    {
        $occurrences = [];
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
                $occurrences[] = ['index' => $index, 'declared' => $parameterDepth > 0];
            }
        }

        return $this->partition($occurrences);
    }

    /**
     * @param list<array{index: int, declared: bool}> $occurrences
     *
     * @return array{declarations: int, reads: list<int>}
     */
    private function partition(array $occurrences): array
    {
        $declarations = 0;
        $reads = [];

        foreach ($occurrences as $occurrence) {
            if ($occurrence['declared']) {
                ++$declarations;

                continue;
            }

            $reads[] = $occurrence['index'];
        }

        return ['declarations' => $declarations, 'reads' => $reads];
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
