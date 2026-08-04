<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Validation\Infrastructure;

use Erpify\Shared\Validation\Infrastructure\PasswordPolicy;
use Erpify\Shared\Validation\Infrastructure\PasswordPolicyValidator;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\ConstraintValidatorInterface;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;
use Symfony\Component\Validator\Test\ConstraintValidatorTestCase;

/**
 * @internal
 *
 * @extends ConstraintValidatorTestCase<PasswordPolicyValidator>
 */
#[CoversClass(PasswordPolicyValidator::class)]
#[CoversClass(PasswordPolicy::class)]
final class PasswordPolicyValidatorTest extends ConstraintValidatorTestCase
{
    private const string GRINNING_FACE = "\u{1F600}";

    private const string NO_BREAK_SPACE = "\u{00A0}";

    private const string IDEOGRAPHIC_SPACE = "\u{3000}";

    #[DataProvider('provideAcceptsCases')]
    public function testAccepts(mixed $value): void
    {
        $this->validator->validate($value, new PasswordPolicy());

        $this->assertNoViolation();
    }

    /**
     * @return iterable<string, array{0: mixed}>
     */
    public static function provideAcceptsCases(): iterable
    {
        yield 'exactly the minimum' => [\str_repeat('a', PasswordPolicy::MIN_LENGTH)];
        yield 'exactly the maximum' => [\str_repeat('a', PasswordPolicy::MAX_LENGTH)];
        yield 'an ordinary passphrase with inner spaces' => ['correct horse battery staple'];
        yield 'spanish accents count one code point each' => ['contraseña-ñandú'];

        // The pair that makes the counting unit observable. Eight astral characters are eight code points
        // and sixteen UTF-16 units: measured the JavaScript way this would be sixteen, and the two ends of
        // the wire would disagree about a credential that is exactly at the floor.
        yield 'eight astral characters are eight code points' => [\str_repeat(self::GRINNING_FACE, 8)];

        yield 'null is skipped' => [null];

        // NotBlank owns the "required" vocabulary; this constraint stays silent so a missing password
        // produces one violation rather than three.
        yield 'the empty string belongs to NotBlank' => [''];
    }

    #[DataProvider('provideRejectsWhitespaceOnlyCases')]
    public function testRejectsWhitespaceOnly(string $value): void
    {
        $this->validator->validate($value, new PasswordPolicy());

        $this->buildViolation(PasswordPolicy::BLANK_MESSAGE)->assertRaised();
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function provideRejectsWhitespaceOnlyCases(): iterable
    {
        // All three are long enough to satisfy the length rule, which is exactly why NotBlank plus a length
        // bound admitted them before: the credential that results cannot be reliably retyped.
        yield 'eight ascii spaces' => [\str_repeat(' ', 8)];
        yield 'eight no-break spaces survive an ascii trim' => [\str_repeat(self::NO_BREAK_SPACE, 8)];
        yield 'eight ideographic spaces' => [\str_repeat(self::IDEOGRAPHIC_SPACE, 8)];
        yield 'a mix of unicode whitespace' => ["\t \n" . self::NO_BREAK_SPACE . "\r " . self::IDEOGRAPHIC_SPACE . ' '];
    }

    #[DataProvider('provideRejectsTooShortCases')]
    public function testRejectsTooShort(string $value): void
    {
        $this->validator->validate($value, new PasswordPolicy());

        $this->buildViolation(PasswordPolicy::TOO_SHORT_MESSAGE)
            ->setParameter('{{ limit }}', (string) PasswordPolicy::MIN_LENGTH)
            ->assertRaised()
        ;
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function provideRejectsTooShortCases(): iterable
    {
        yield 'one short of the minimum' => [\str_repeat('a', PasswordPolicy::MIN_LENGTH - 1)];

        // Ten UTF-16 units, five code points. The PWA used to accept this and the API refused it, under two
        // rules that both claimed to say "at least eight characters".
        yield 'five astral characters are five code points' => [\str_repeat(self::GRINNING_FACE, 5)];

        yield 'a single non-whitespace character' => ['x'];
        yield 'padded but short once trimmed is measured whole' => ['  ab  '];
    }

    #[DataProvider('provideRejectsTooLongCases')]
    public function testRejectsTooLong(string $value): void
    {
        $this->validator->validate($value, new PasswordPolicy());

        $this->buildViolation(PasswordPolicy::TOO_LONG_MESSAGE)
            ->setParameter('{{ limit }}', (string) PasswordPolicy::MAX_LENGTH)
            ->assertRaised()
        ;
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function provideRejectsTooLongCases(): iterable
    {
        yield 'one past the maximum' => [\str_repeat('a', PasswordPolicy::MAX_LENGTH + 1)];
        yield 'astral characters past the maximum' => [
            \str_repeat(self::GRINNING_FACE, PasswordPolicy::MAX_LENGTH + 1),
        ];
    }

    public function testWrongConstraintTypeRaisesUnexpectedTypeException(): void
    {
        $this->expectException(UnexpectedTypeException::class);

        $this->validator->validate('a-valid-password', new NotBlank());
    }

    public function testNonStringRaisesUnexpectedValueException(): void
    {
        $this->expectException(UnexpectedValueException::class);

        $this->validator->validate(12345678, new PasswordPolicy());
    }

    #[Override]
    protected function createValidator(): ConstraintValidatorInterface
    {
        return new PasswordPolicyValidator();
    }
}
