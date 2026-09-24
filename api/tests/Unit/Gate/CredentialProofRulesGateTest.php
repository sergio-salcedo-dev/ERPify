<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Gate;

use Erpify\Tests\Support\CredentialProofRules;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Falsifiability of the rules the sibling gate applies over a correct tree: each one is driven over input
 * that violates it, so a rule that could detect nothing would red here rather than read green there.
 *
 * @internal
 */
#[CoversClass(CredentialProofRules::class)]
final class CredentialProofRulesGateTest extends TestCase
{
    private const string USE_CASE = \Erpify\Iam\Identity\Application\RevokeRecoverySecret::class;

    private const string PROVING_USE_CASE = <<<'PHP'
        <?php
        final class RevokeRecoverySecret {
            public function revoke(): void { $this->proveCurrentPassword->ensure($user, $verify); }
        }
        PHP;

    private const string CONTROLLER = <<<'PHP'
        <?php
        final class RevokeController {
            public function __invoke(): void {
                $this->currentPasswordProofThrottle->ensureWithinBudget($id);
                $this->revokeRecoverySecret->revoke($id, $verify);
            }
        }
        PHP;

    public function testACorrectControllerPasses(): void
    {
        $this->assertSame([], $this->violations(self::CONTROLLER));
    }

    public function testAControllerWithoutTheThrottleReds(): void
    {
        $this->assertStringContainsString(
            'does not inject CurrentPasswordProofThrottle',
            \implode("\n", CredentialProofRules::proofViolations(
                'r',
                self::CONTROLLER,
                ['revokeRecoverySecret' => [self::USE_CASE]],
                $this->useCases(self::PROVING_USE_CASE),
            )),
        );
    }

    /**
     * A call that survives only in a comment is not a call.
     */
    public function testAThrottleThatIsNeverSpentReds(): void
    {
        $spend = '$this->currentPasswordProofThrottle->ensureWithinBudget($id);';
        $controller = \str_replace($spend, '// ' . $spend, self::CONTROLLER);

        $this->assertStringContainsString(
            'never calls ensureWithinBudget()',
            \implode("\n", $this->violations($controller)),
        );
    }

    public function testAUseCaseThatDoesNotProveReds(): void
    {
        $useCase = \str_replace('$this->proveCurrentPassword->ensure($user, $verify);', '', self::PROVING_USE_CASE);

        $this->assertStringContainsString(
            'calls ProveCurrentPassword::ensure()',
            \implode("\n", CredentialProofRules::proofViolations(
                'r',
                self::CONTROLLER,
                $this->controllerCollaborators(),
                $this->useCases($useCase),
            )),
        );
    }

    public function testSpendingTheBudgetAfterReachingTheProofReds(): void
    {
        $controller = <<<'PHP'
            <?php
            final class RevokeController {
                public function __invoke(): void {
                    $this->revokeRecoverySecret->revoke($id, $verify);
                    $this->currentPasswordProofThrottle->ensureWithinBudget($id);
                }
            }
            PHP;

        $this->assertStringContainsString('before it spends', \implode("\n", $this->violations($controller)));
    }

    public function testCompletenessAndStalenessBothRed(): void
    {
        $registry = CredentialProofRules::parse(['iam_retired :: ordinary']);

        $this->assertSame(
            [
                'Route "iam_new_token_revoke" has no line in api/.credential-proof-policy — classify it.',
                'api/.credential-proof-policy classifies "iam_retired", which the route manifest no longer declares.',
            ],
            CredentialProofRules::bijectionViolations(['iam_new_token_revoke'], $registry),
        );
    }

    public function testAnAuthenticatedRouteCannotBeClassifiedAnonymous(): void
    {
        $registry = CredentialProofRules::parse([
            'identity_login :: anonymous :: proves the password it is handed',
            'iam_me_revoke_recovery_secret :: anonymous :: dodging the proof',
        ]);

        $violations = CredentialProofRules::anonymousViolations(
            $registry,
            [
                'identity_login' => '/api/v1/backoffice/login',
                'iam_me_revoke_recovery_secret' => '/api/v1/me/recovery-secret/revoke',
            ],
            ['^/api/v1/backoffice/login$', '^/api/test/'],
        );

        $this->assertCount(1, $violations);
        $this->assertStringContainsString('iam_me_revoke_recovery_secret', $violations[0]);
    }

    #[DataProvider('provideAMalformedLineIsRefusedCases')]
    public function testAMalformedLineIsRefused(string $line): void
    {
        $this->expectException(RuntimeException::class);

        CredentialProofRules::parse([$line]);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideAMalformedLineIsRefusedCases(): iterable
    {
        yield 'unknown class' => ['iam_me :: exempt'];
        yield 'anonymous without a reason' => ['identity_login :: anonymous'];
        yield 'ordinary with a trailing field' => ['iam_me :: ordinary :: because'];
        yield 'no class' => ['iam_me'];
    }

    public function testADuplicatedRouteIsRefused(): void
    {
        $this->expectException(RuntimeException::class);

        CredentialProofRules::parse(['iam_me :: ordinary', 'iam_me :: credential-affecting']);
    }

    /**
     * @return list<string>
     */
    private function violations(string $controller): array
    {
        return CredentialProofRules::proofViolations(
            'r',
            $controller,
            $this->controllerCollaborators(),
            $this->useCases(self::PROVING_USE_CASE),
        );
    }

    /**
     * @return array<string, list<string>>
     */
    private function controllerCollaborators(): array
    {
        return [
            'revokeRecoverySecret' => [self::USE_CASE],
            'currentPasswordProofThrottle' => [CredentialProofRules::THROTTLE],
        ];
    }

    /**
     * @return array<string, array{source: string, collaborators: array<string, list<string>>}>
     */
    private function useCases(string $source): array
    {
        return ['revokeRecoverySecret' => [
            'source' => $source,
            'collaborators' => ['proveCurrentPassword' => [CredentialProofRules::PROOF]],
        ]];
    }
}
