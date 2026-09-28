<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Infrastructure\Security;

use Erpify\Iam\Identity\Infrastructure\Security\ProblemDetailsAuthenticationFailureHandler;
use Erpify\Shared\Persistence\Domain\Exception\ReferentialIntegrityViolation;
use Erpify\Shared\Persistence\Domain\Exception\TransientTransactionFailure;
use Erpify\Tests\Unit\Iam\Identity\Domain\Entity\Mother\UserMother;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Throwable;

/**
 * The faults the transaction manager translates out of a failed unit of work are faults only an EXISTING identity
 * can meet — it alone locks a row and writes — so each must end in the same uniform 401 an unknown address gets.
 *
 * @internal
 */
#[CoversClass(ProblemDetailsAuthenticationFailureHandler::class)]
final class ProblemDetailsAuthenticationFailureHandlerTransactionFaultTest extends TestCase
{
    use BuildsFailureHandler;

    #[DataProvider('provideAbsorbsATranslatedFaultCases')]
    public function testAbsorbsATranslatedFault(
        Throwable $fault,
    ): void {
        // Only an address that exists locks a row and writes, so only it can meet these; answering them as a
        // 503 or 409 would name the account on the wire while an unknown address gets the 401.
        $caught = null;

        try {
            $this->handlerWhoseTransactionFails($fault)->onAuthenticationFailure(
                $this->loginRequest(UserMother::DEFAULT_EMAIL),
                new BadCredentialsException('wrong'),
            );
        } catch (Throwable $throwable) {
            $caught = $throwable;
        }

        $this->assertInstanceOf(AuthenticationException::class, $caught);
        $this->assertSame('Invalid credentials.', $caught->getMessage());
    }

    /**
     * @return iterable<string, array{Throwable}>
     */
    public static function provideAbsorbsATranslatedFaultCases(): iterable
    {
        yield 'deadlock or lock timeout (503)' => [new TransientTransactionFailure(new RuntimeException('deadlock'))];
        yield 'referential fault (409)' => [new ReferentialIntegrityViolation(new RuntimeException('fk'))];
    }
}
