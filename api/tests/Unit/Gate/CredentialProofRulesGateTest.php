<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Gate;

use Erpify\Tests\Support\CredentialProofRules;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Falsifiability of the registry-side rules the sibling gate applies over a correct tree — the line format,
 * completeness and staleness, the `anonymous` escape and the route-to-action resolution: each one is driven
 * over input that violates it, so a rule that could detect nothing would red here rather than read green there.
 * The proof over a controller action is {@see CredentialProofActionRulesGateTest}'s.
 *
 * @internal
 */
#[CoversClass(CredentialProofRules::class)]
final class CredentialProofRulesGateTest extends TestCase
{
    public function testARouteResolvingToNoneOrSeveralActionsReds(): void
    {
        $action = ['class' => self::class, 'method' => '__invoke'];

        $this->assertSame([], CredentialProofRules::actionViolations('r', [$action]));
        $this->assertViolation('declare its name explicitly', CredentialProofRules::actionViolations('r', []));
        $this->assertViolation('expected exactly one', CredentialProofRules::actionViolations('r', [$action, $action]));
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
            'iam_webhook :: anonymous :: exempted by name',
            'iam_me_revoke_recovery_secret :: anonymous :: dodging the proof',
        ]);

        $violations = CredentialProofRules::anonymousViolations(
            $registry,
            [
                'identity_login' => '/api/v1/backoffice/login',
                'iam_webhook' => '/api/v1/webhook',
                'iam_me_revoke_recovery_secret' => '/api/v1/me/recovery-secret/revoke',
            ],
            ['^/api/v1/backoffice/login$', '^/api/test/', 'route:iam_webhook'],
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
        yield 'anonymous with a separator inside its reason' => ['identity_login :: anonymous :: :: y'];
        yield 'ordinary with a trailing field' => ['iam_me :: ordinary :: because'];
        yield 'no class' => ['iam_me'];
    }

    public function testADuplicatedRouteIsRefused(): void
    {
        $this->expectException(RuntimeException::class);

        CredentialProofRules::parse(['iam_me :: ordinary', 'iam_me :: credential-affecting']);
    }

    /**
     * @param list<string> $violations
     */
    private function assertViolation(string $expected, array $violations): void
    {
        $this->assertStringContainsString($expected, \implode("\n", $violations));
    }
}
