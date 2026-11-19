<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Documents\Document\Infrastructure\Http;

use Erpify\Documents\Document\Domain\Erasure\ErasureReason;
use Erpify\Documents\Document\Infrastructure\Http\EraseDocumentRequest;
use Erpify\Iam\Identity\Infrastructure\Http\ExistingCredential;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\ConstraintViolationListInterface;
use Symfony\Component\Validator\Validation;

/**
 * The erase payload's own constraints: the password the caller re-proves is present and bounded like every other
 * credential being verified. The reason is an enum, so a value outside it never reaches an instance — the strict
 * payload mapper answers it 422 before this class is built.
 *
 * @internal
 */
#[CoversClass(EraseDocumentRequest::class)]
final class EraseDocumentRequestTest extends TestCase
{
    #[Test]
    public function aPresentPasswordWithinTheCeilingIsValid(): void
    {
        $request = new EraseDocumentRequest(ErasureReason::LegalObligation, 'the-current-password');

        $this->assertSame(ErasureReason::LegalObligation, $request->reason);
        $this->assertCount(0, $this->violations($request));
    }

    #[Test]
    #[DataProvider('provideAnAbsentOrOversizedPasswordIsRefusedOnTheCurrentPasswordFieldCases')]
    public function anAbsentOrOversizedPasswordIsRefusedOnTheCurrentPasswordField(string $password): void
    {
        $violations = $this->violations(new EraseDocumentRequest(ErasureReason::DataSubjectRequest, $password));

        $this->assertGreaterThan(0, \count($violations));
        $this->assertSame('currentPassword', $violations->get(0)->getPropertyPath());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideAnAbsentOrOversizedPasswordIsRefusedOnTheCurrentPasswordFieldCases(): iterable
    {
        yield 'omitted' => [''];
        yield 'past the ceiling' => [\str_repeat('x', ExistingCredential::LENGTH_CEILING + 1)];
    }

    #[Test]
    public function thePasswordDefaultsToTheEmptyStringSoAMissingMemberFailsTheSameWay(): void
    {
        $violations = $this->violations(new EraseDocumentRequest(ErasureReason::DataSubjectRequest));

        $this->assertGreaterThan(0, \count($violations));
    }

    private function violations(EraseDocumentRequest $request): ConstraintViolationListInterface
    {
        return Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator()->validate($request);
    }
}
