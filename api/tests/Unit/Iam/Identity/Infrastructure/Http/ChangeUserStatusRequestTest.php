<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Infrastructure\Http;

use Erpify\Iam\Identity\Domain\Enum\IdentityStatus;
use Erpify\Iam\Identity\Infrastructure\Http\ChangeUserStatusRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Pins the boundary contract of the change-status payload: the two legal targets pass, while a well-typed but
 * illegal target (`ACTIVE` / `INVITED`) raises a violation so `#[MapRequestPayload]` answers 422 before
 * {@see \Erpify\Iam\Identity\Application\ChangeUserStatus} runs. A value outside {@see IdentityStatus} is
 * rejected earlier, at enum denormalization — that path is a request-mapping concern, exercised over HTTP.
 *
 * @internal
 */
#[CoversClass(ChangeUserStatusRequest::class)]
final class ChangeUserStatusRequestTest extends TestCase
{
    private ValidatorInterface $validator;

    protected function setUp(): void
    {
        $this->validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();
    }

    #[Test]
    #[DataProvider('provideALegalTransitionTargetPassesValidationCases')]
    public function aLegalTransitionTargetPassesValidation(IdentityStatus $status): void
    {
        $this->assertCount(0, $this->validator->validate(new ChangeUserStatusRequest($status)));
    }

    /**
     * @return iterable<string, array{IdentityStatus}>
     */
    public static function provideALegalTransitionTargetPassesValidationCases(): iterable
    {
        yield 'suspend' => [IdentityStatus::SUSPENDED];
        yield 'deactivate' => [IdentityStatus::DEACTIVATED];
    }

    #[Test]
    #[DataProvider('provideAnIllegalTransitionTargetIsRejectedCases')]
    public function anIllegalTransitionTargetIsRejected(IdentityStatus $status): void
    {
        $this->assertGreaterThan(0, $this->validator->validate(new ChangeUserStatusRequest($status))->count());
    }

    /**
     * @return iterable<string, array{IdentityStatus}>
     */
    public static function provideAnIllegalTransitionTargetIsRejectedCases(): iterable
    {
        yield 'active' => [IdentityStatus::ACTIVE];
        yield 'invited' => [IdentityStatus::INVITED];
    }
}
