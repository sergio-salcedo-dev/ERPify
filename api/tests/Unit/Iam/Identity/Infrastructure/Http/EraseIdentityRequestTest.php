<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Infrastructure\Http;

use Erpify\Iam\Identity\Infrastructure\Http\EraseIdentityRequest;
use Erpify\Iam\Identity\Infrastructure\Http\ExistingCredential;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * The boundary contract of the erase payload: a present password passes, an absent or blank one is a violation on
 * `currentPassword` (so `#[StrictRequestPayload]` answers 422 before the budget is spent), and a value beyond the
 * credential ceiling is refused instead of being handed to the hasher.
 *
 * @internal
 */
#[CoversClass(EraseIdentityRequest::class)]
final class EraseIdentityRequestTest extends TestCase
{
    private ValidatorInterface $validator;

    protected function setUp(): void
    {
        $this->validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();
    }

    #[Test]
    public function aPresentPasswordPasses(): void
    {
        $this->assertCount(0, $this->validator->validate(new EraseIdentityRequest('the-current-password')));
    }

    #[Test]
    public function anAbsentPasswordIsRejectedOnItsField(): void
    {
        $violations = $this->validator->validate(new EraseIdentityRequest());

        $this->assertCount(1, $violations);
        $this->assertSame('currentPassword', $violations->get(0)->getPropertyPath());
    }

    #[Test]
    public function aPasswordBeyondTheCredentialCeilingIsRejected(): void
    {
        $oversized = \str_repeat('a', ExistingCredential::LENGTH_CEILING + 1);

        $violations = $this->validator->validate(new EraseIdentityRequest($oversized));

        $this->assertCount(1, $violations);
        $this->assertSame('currentPassword', $violations->get(0)->getPropertyPath());
    }
}
