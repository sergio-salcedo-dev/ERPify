<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Infrastructure\Security;

use Erpify\Iam\Identity\Infrastructure\Security\PasswordPolicyCheck;
use Erpify\Shared\Validation\Application\Validator;
use Erpify\Shared\Validation\Infrastructure\PasswordPolicy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;

/**
 * @internal
 */
#[CoversClass(PasswordPolicyCheck::class)]
final class PasswordPolicyCheckTest extends TestCase
{
    public function testAcceptsACredentialThatSatisfiesThePolicy(): void
    {
        $this->assertSame([], $this->check()->violationsFor('a-perfectly-fine-password'));
    }

    #[DataProvider('provideRefusesCases')]
    public function testRefuses(string $plainPassword, string $expectedMessage): void
    {
        $this->assertSame([$expectedMessage], $this->check()->violationsFor($plainPassword));
    }

    /**
     * The expected strings are written out interpolated, not as `PasswordPolicy`'s templates: these are the
     * bytes the operator reads, and the same bytes `passwordPolicy.ts` declares so both ends of the product
     * say one thing. Referencing the constants here would let the two drift apart and still pass.
     *
     * @return iterable<string, array{0: string,1: string}>
     */
    public static function provideRefusesCases(): iterable
    {
        // The console has no request-mapping edge, so without this check the bootstrap account — the most
        // privileged the installation will ever hold — was minted by any non-empty string.
        yield 'a single character' => ['x', 'The password must be at least 8 characters.'];
        yield 'whitespace only' => [
            \str_repeat(' ', 8),
            'The password must contain at least one non-whitespace character.',
        ];
        yield 'past the ceiling' => [
            \str_repeat('a', PasswordPolicy::MAX_LENGTH + 1),
            'The password must not exceed 128 characters.',
        ];
    }

    public function testTheEmptyStringIsReportedAsRequiredRatherThanAsTooShort(): void
    {
        $this->assertSame(['The password must not be empty.'], $this->check()->violationsFor(''));
    }

    private function check(): PasswordPolicyCheck
    {
        return new PasswordPolicyCheck(new Validator(Validation::createValidator()));
    }
}
