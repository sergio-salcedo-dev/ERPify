<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\ErrorContract\Infrastructure\Http\EventListener;

use Erpify\Shared\ErrorContract\Application\ProblemDetailsFactory;
use Erpify\Shared\ErrorContract\Infrastructure\Http\EventListener\ExceptionResponder;
use Erpify\Shared\ErrorContract\Infrastructure\Http\ProblemDetailsResponder;
use Erpify\Shared\Http\Infrastructure\ApiRequestMatcher;
use LogicException;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\ErrorHandler\BufferingLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\Exception\ValidationFailedException;
use Throwable;

/**
 * What a validation failure is allowed to contribute to the error log.
 *
 * A violation message is interpolated with the values it is about — the request payload — and this
 * application's payloads carry a natural person's IBAN, holder name and alias. The per-error log line
 * is a sink no erasure path reaches, and the denylist cannot see inside it: it strips by KEY, and the
 * key is `exception_message`. So the message is rebuilt from what stays diagnostic without carrying
 * input.
 *
 * These live apart from the listener's main suite because they are one subject, and because the main
 * suite is already at the complexity ceiling its own linter enforces.
 *
 * The suppressions mirror that sibling's: the anonymous kernel must implement
 * `HttpKernelInterface::handle()` exactly — parameters it never reads included — and driving the
 * listener end to end needs the listener's own collaborators.
 *
 * @internal
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 * @SuppressWarnings("PHPMD.UnusedFormalParameter")
 * @SuppressWarnings("PHPMD.BooleanArgumentFlag")
 */
#[CoversClass(ExceptionResponder::class)]
final class ExceptionResponderValidationRedactionTest extends TestCase
{
    private const string IBAN = 'ES9121000418450200051332';

    /**
     * The aggregate's path: `Validator::ensure()` throws the failure itself.
     */
    #[Test]
    public function itNeverCarriesAViolationValue(): void
    {
        $message = $this->bicMismatchMessage();

        $exceptionMessage = $this->exceptionMessageFor(
            new ValidationFailedException($this->validatedPayload(), $this->violations($message, 'bic')),
        );

        $this->assertStringNotContainsString(self::IBAN, $exceptionMessage, \sprintf(
            '`%s` renders its whole violation list as its message, so every value a constraint '
            . 'interpolates rides along into a file no erasure path touches.',
            ValidationFailedException::class,
        ));
        $this->assertStringContainsString(
            'bic',
            $exceptionMessage,
            'Only values are dropped: which field failed is what makes the line diagnostic at all.',
        );
    }

    /**
     * The shape the HTTP edge actually throws, and the one a top-level `instanceof` misses:
     * `#[MapRequestPayload]` wraps the failure in an `HttpException(422)` whose own message is the
     * violation list already imploded. Every command DTO arrives this way, so this is the path
     * holding the raw request rather than the aggregate's re-check of it.
     */
    #[Test]
    public function itNeverCarriesAViolationValueThroughTheMappingWrapper(): void
    {
        $message = $this->bicMismatchMessage();

        $exceptionMessage = $this->exceptionMessageFor(new HttpException(
            422,
            $message,
            new ValidationFailedException($this->validatedPayload(), $this->violations($message, 'bic')),
        ));

        $this->assertStringNotContainsString(self::IBAN, $exceptionMessage, \sprintf(
            'The wrapper carries the imploded violation list as its OWN message, so a top-level '
            . '`instanceof %s` leaves it unreduced — and this is the shape every mapped DTO throws.',
            ValidationFailedException::class,
        ));
        $this->assertStringContainsString('bic', $exceptionMessage);
        $this->assertStringContainsString(
            '29a2c3bb',
            $exceptionMessage,
            'The constraint code is a constant, not payload: it is what tells a country mismatch from '
            . 'a malformed BIC once the message is gone.',
        );
    }

    /**
     * A property path is not always shape. `UnknownPayloadMemberListener` raises violations whose path
     * IS the surplus member's name — caller-chosen bytes, unbounded in length and count. Unwrapping
     * the chain without bounding them would only move the leak from the message to the path.
     */
    #[Test]
    public function itBoundsCallerControlledViolationPaths(): void
    {
        $smuggled = self::IBAN . \str_repeat('x', 200);
        $violations = [];

        for ($i = 0; $i < 25; ++$i) {
            $violations[] = new ConstraintViolation('irrelevant', null, [], '', $smuggled, null);
        }

        $exceptionMessage = $this->exceptionMessageFor(
            new ValidationFailedException($this->validatedPayload(), new ConstraintViolationList($violations)),
        );

        $this->assertLessThan(
            1024,
            \strlen($exceptionMessage),
            'A caller must not be able to size the log record by padding member names.',
        );
        $this->assertStringNotContainsString(self::IBAN, $exceptionMessage, \sprintf(
            'A path is shape only when the validated type declares it. `%s` is not a member of the '
            . "payload, so writing it would put the caller's own bytes in the record.",
            $smuggled,
        ));
        $this->assertStringContainsString('25 violation(s)', $exceptionMessage);
        $this->assertStringContainsString('(+15 more)', $exceptionMessage);
        $this->assertStringContainsString('(unrecognised member)', $exceptionMessage);
    }

    /**
     * What actually reaches the listener: the mapped DTO or the aggregate, never a bare scalar. The
     * declared vocabulary of paths is read off it, so the fixture has to be an object to mean anything.
     */
    private function validatedPayload(): object
    {
        return new class {
            public ?string $bic = null;
        };
    }

    private function bicMismatchMessage(): string
    {
        return \sprintf(
            'This Business Identifier Code (BIC) is not associated with IBAN %s.',
            self::IBAN,
        );
    }

    private function violations(string $message, string $path): ConstraintViolationList
    {
        return new ConstraintViolationList([
            new ConstraintViolation($message, null, [], '', $path, null, null, '29a2c3bb'),
        ]);
    }

    private function exceptionMessageFor(Throwable $throwable): string
    {
        $bufferingLogger = new BufferingLogger();
        $exceptionResponder = new ExceptionResponder(
            new ProblemDetailsFactory('test', new NullLogger()),
            new ProblemDetailsResponder(),
            $bufferingLogger,
            new ApiRequestMatcher(),
        );

        $exceptionResponder($this->eventFor($throwable));

        $logs = \array_values($bufferingLogger->cleanLogs());
        $this->assertCount(1, $logs, 'Listener must emit exactly one log record per invocation.');

        /** @var array{0: string, 1: string, 2: array<string, mixed>} $first */
        $first = $logs[0];
        $context = $first[2];

        $this->assertArrayHasKey('exception_message', $context);
        $this->assertIsString($context['exception_message']);

        return $context['exception_message'];
    }

    private function eventFor(Throwable $throwable): ExceptionEvent
    {
        $kernel = new class implements HttpKernelInterface {
            #[Override]
            public function handle(
                Request $request,
                int $type = HttpKernelInterface::MAIN_REQUEST,
                bool $catch = true,
            ): Response {
                throw new LogicException('Test kernel: handle() must not be called by the listener.');
            }
        };

        return new ExceptionEvent(
            $kernel,
            Request::create('/api/v1/backoffice/bank-accounts'),
            HttpKernelInterface::MAIN_REQUEST,
            $throwable,
        );
    }
}
