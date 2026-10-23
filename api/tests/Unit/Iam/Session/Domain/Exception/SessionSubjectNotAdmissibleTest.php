<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Session\Domain\Exception;

use Erpify\Iam\Identity\Infrastructure\Security\ProblemDetailsAuthenticationFailureHandler;
use Erpify\Iam\Session\Domain\Exception\SessionSubjectNotAdmissible;
use Erpify\Iam\Session\Domain\SubjectAdmission;
use Erpify\Shared\ErrorContract\Domain\Exception\Unauthenticated;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionClassConstant;

/**
 * @internal
 */
#[CoversClass(SessionSubjectNotAdmissible::class)]
final class SessionSubjectNotAdmissibleTest extends TestCase
{
    /**
     * An identity erased while its login was in flight must read exactly like a credential that never matched,
     * so the body is the firewall's own uniform failure, word for word.
     */
    #[DataProvider('provideCarriesItsReasonBehindTheFirewallsUniformFailureCases')]
    public function testCarriesItsReasonBehindTheFirewallsUniformFailure(SubjectAdmission $reason): void
    {
        $refusal = SessionSubjectNotAdmissible::atMint($reason);

        $this->assertSame($reason, $refusal->reason());
        $this->assertTrue(
            (new ReflectionClass(SessionSubjectNotAdmissible::class))->implementsInterface(Unauthenticated::class),
        );
        $this->assertSame('unauthenticated', $refusal->type());
        $this->assertSame(
            (new ReflectionClassConstant(
                ProblemDetailsAuthenticationFailureHandler::class,
                'GENERIC_FAILURE_MESSAGE',
            ))->getValue(),
            $refusal->title(),
        );
        $this->assertSame([], $refusal->context(), 'the identity is a person, so nothing about it travels');
    }

    /**
     * @return iterable<string, array{SubjectAdmission}>
     */
    public static function provideCarriesItsReasonBehindTheFirewallsUniformFailureCases(): iterable
    {
        yield 'suspended' => [SubjectAdmission::Suspended];
        yield 'deactivated' => [SubjectAdmission::Deactivated];
        yield 'gone' => [SubjectAdmission::Gone];
    }

    public function testAnAdmissibleIdentityIsNotARefusal(): void
    {
        $this->expectException(LogicException::class);

        SessionSubjectNotAdmissible::atMint(SubjectAdmission::Admissible);
    }
}
