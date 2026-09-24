<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Gate;

use Erpify\Tests\Support\CredentialProofRules;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Falsifiability of the proof the sibling gate applies to every `credential-affecting` route's action: each
 * rule is driven over a controller or use case that violates it, so a rule that could detect nothing would red
 * here rather than read green there. How a call is recognised at all is {@see PhpCallSitesTest}'s.
 *
 * @internal
 */
#[CoversClass(CredentialProofRules::class)]
final class CredentialProofActionRulesGateTest extends TestCase
{
    private const string USE_CASE = \Erpify\Iam\Identity\Application\RevokeRecoverySecret::class;

    private const string PROVING_USE_CASE = <<<'PHP'
        <?php
        final class RevokeRecoverySecret {
            public function revoke(): void {
                $this->transactionManager->transactional(function () use ($verify): void {
                    $this->proveCurrentPassword->ensure($user, $verify);
                });
            }
        }
        PHP;

    private const string CONTROLLER = <<<'PHP'
        <?php
        final class RevokeController {
            public function __invoke(): void {
                $this->currentPasswordProofThrottle->ensureWithinBudget($id);
                $this->revokeRecoverySecret->revoke($id, fn ($s): bool => $this->passwordHasher->verify($p, $s));
            }
        }
        PHP;

    public function testACorrectControllerPasses(): void
    {
        $this->assertSame([], $this->violations(self::CONTROLLER));
    }

    public function testAControllerWithoutTheThrottleReds(): void
    {
        $collaborators = $this->controllerCollaborators();
        unset($collaborators['currentPasswordProofThrottle']);

        $this->assertViolation(
            'does not inject CurrentPasswordProofThrottle',
            $this->violations(self::CONTROLLER, collaborators: $collaborators),
        );
    }

    /**
     * Only the action the route is bound to is read: a spend in a helper — declared above the action, where a
     * whole-file offset would place it first — or in a sibling action is not a spend on this route.
     */
    public function testASpendOutsideTheBoundActionReds(): void
    {
        $controller = <<<'PHP'
            <?php
            final class RevokeController {
                private function spend(): void { $this->currentPasswordProofThrottle->ensureWithinBudget($id); }
                public function mint(): void { $this->currentPasswordProofThrottle->ensureWithinBudget($id); }
                public function __invoke(): void {
                    $this->revokeRecoverySecret->revoke($id, fn ($s): bool => $this->passwordHasher->verify($p, $s));
                    $this->spend();
                }
            }
            PHP;

        $this->assertViolation('never calls ensureWithinBudget()', $this->violations($controller));
    }

    public function testAnActionTheControllerDoesNotDeclareReds(): void
    {
        $this->assertViolation('declares no method revoke()', $this->violations(self::CONTROLLER, 'revoke'));
    }

    /**
     * The proof takes its verdict from a closure the controller builds, so a controller handing it
     * `fn () => true` proves nothing while the use case still calls `ensure()`.
     */
    public function testAControllerThatNeverVerifiesThePasswordReds(): void
    {
        $controller = \str_replace(
            'fn ($s): bool => $this->passwordHasher->verify($p, $s)',
            'static fn (): bool => true',
            self::CONTROLLER,
        );

        $this->assertViolation('never calls PasswordHasher::verify()', $this->violations($controller));
    }

    #[DataProvider('provideAUseCaseMethodThatDoesNotProveRedsCases')]
    public function testAUseCaseMethodThatDoesNotProveReds(string $useCase): void
    {
        $this->assertViolation(
            'calls ProveCurrentPassword::ensure()',
            $this->violations(self::CONTROLLER, useCase: $useCase),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideAUseCaseMethodThatDoesNotProveRedsCases(): iterable
    {
        $call = '$this->proveCurrentPassword->ensure($user, $verify);';

        yield 'proof removed' => [\str_replace($call, '', self::PROVING_USE_CASE)];
        yield 'proof only in a comment' => [\str_replace($call, '// ' . $call, self::PROVING_USE_CASE)];
        yield 'proof only in a sibling method' => [<<<'PHP'
            <?php
            final class RevokeRecoverySecret {
                public function mint(): void { $this->proveCurrentPassword->ensure($user, $verify); }
                public function revoke(): void { $this->recoverySecrets->remove($user); }
            }
            PHP];
    }

    public function testAProvingUseCaseThatIsInjectedButNeverInvokedReds(): void
    {
        $controller = \str_replace('$this->revokeRecoverySecret->revoke(', '$this->other->revoke(', self::CONTROLLER);

        $this->assertViolation('calls ProveCurrentPassword::ensure()', $this->violations($controller));
    }

    public function testSpendingTheBudgetAfterReachingTheProofReds(): void
    {
        $controller = <<<'PHP'
            <?php
            final class RevokeController {
                public function __invoke(): void {
                    $this->revokeRecoverySecret->revoke($id, fn ($s): bool => $this->passwordHasher->verify($p, $s));
                    $this->currentPasswordProofThrottle->ensureWithinBudget($id);
                }
            }
            PHP;

        $this->assertViolation('before it spends', $this->violations($controller));
    }

    /**
     * Every proving invocation is judged, not only the first one found.
     */
    public function testASecondProvingUseCaseReachedBeforeTheSpendReds(): void
    {
        $controller = <<<'PHP'
            <?php
            final class RevokeController {
                public function __invoke(): void {
                    $this->mintRecoverySecret->revoke($id, $verify);
                    $this->currentPasswordProofThrottle->ensureWithinBudget($id);
                    $this->revokeRecoverySecret->revoke($id, fn ($s): bool => $this->passwordHasher->verify($p, $s));
                }
            }
            PHP;
        $useCases = [
            'revokeRecoverySecret' => $this->useCase(self::PROVING_USE_CASE),
            'mintRecoverySecret' => $this->useCase(self::PROVING_USE_CASE),
        ];

        $this->assertViolation(
            'before it spends',
            CredentialProofRules::proofViolations(
                'r',
                $controller,
                '__invoke',
                $this->controllerCollaborators(),
                $useCases,
            ),
        );
    }

    /**
     * @param list<string> $violations
     */
    private function assertViolation(string $expected, array $violations): void
    {
        $this->assertStringContainsString($expected, \implode("\n", $violations));
    }

    /**
     * @param array<string, list<string>>|null $collaborators
     *
     * @return list<string>
     */
    private function violations(
        string $controller,
        string $action = '__invoke',
        ?array $collaborators = null,
        string $useCase = self::PROVING_USE_CASE,
    ): array {
        return CredentialProofRules::proofViolations(
            'r',
            $controller,
            $action,
            $collaborators ?? $this->controllerCollaborators(),
            ['revokeRecoverySecret' => $this->useCase($useCase)],
        );
    }

    /**
     * @return array<string, list<string>>
     */
    private function controllerCollaborators(): array
    {
        return [
            'revokeRecoverySecret' => [self::USE_CASE],
            'passwordHasher' => [CredentialProofRules::HASHER],
            'currentPasswordProofThrottle' => [CredentialProofRules::THROTTLE],
        ];
    }

    /**
     * @return array{source: string, collaborators: array<string, list<string>>}
     */
    private function useCase(string $source): array
    {
        return ['source' => $source, 'collaborators' => ['proveCurrentPassword' => [CredentialProofRules::PROOF]]];
    }
}
