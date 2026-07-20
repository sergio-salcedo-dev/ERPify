<?php

declare(strict_types=1);

namespace Erpify\Shared\Http\Infrastructure;

use Erpify\Shared\ErrorContract\Infrastructure\Http\EventListener\ExceptionResponder;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Serializer\Exception\ExtraAttributesException;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\Exception\ValidationFailedException;
use Throwable;

/**
 * Gives {@see StrictRequestPayload} its answer: a body carrying members the payload does not declare becomes
 * `422 validation-failed`, naming each surplus member, instead of an unhandled 500.
 *
 * The translation needs a listener because the serializer raises {@see ExtraAttributesException} from an
 * unconditional throw, while Symfony's payload resolver only catches `PartialDenormalizationException` and
 * `Serializer\Exception\InvalidArgumentException` — neither of which it is. Left alone it escapes argument
 * resolution entirely.
 *
 * It classifies rather than formats, which is why it stops at a {@see ValidationFailedException}:
 * {@see \Erpify\Shared\ErrorContract\Application\ProblemDetailsFactory} already answers any throwable carrying
 * one in its chain with the `validation-failed` envelope and a `violations[]` extension. So the error contract
 * gains a producer, not a case — no marker interface, no new problem type, and nothing in the formatter that
 * knows a serializer exists.
 *
 * Surplus member names are echoed back as violation property paths. They are the caller's own input, so there
 * is nothing to disclose, and naming them is the difference between a response the caller can act on and one
 * that only says "no".
 */
#[AsEventListener(event: KernelEvents::EXCEPTION, priority: self::PRIORITY)]
final readonly class UnknownPayloadMemberListener
{
    /**
     * Above {@see ExceptionResponder}, which builds the Problem Details envelope and would otherwise answer
     * this as an unrecognised throwable before the exception has been classified. Its docblock reserves the
     * band above its own priority for exactly this kind of narrow carve-out.
     */
    public const int PRIORITY = ExceptionResponder::PRIORITY + 8;

    private const string VIOLATION_MESSAGE = 'This field is not part of this request.';

    public function __invoke(ExceptionEvent $event): void
    {
        $extraAttributes = $this->extraAttributesIn($event->getThrowable());

        if (!$extraAttributes instanceof ExtraAttributesException) {
            return;
        }

        $event->setThrowable(new HttpException(
            Response::HTTP_UNPROCESSABLE_ENTITY,
            $extraAttributes->getMessage(),
            new ValidationFailedException(null, $this->violationsFor($extraAttributes)),
        ));
    }

    private function extraAttributesIn(Throwable $throwable): ?ExtraAttributesException
    {
        for ($candidate = $throwable; $candidate instanceof Throwable; $candidate = $candidate->getPrevious()) {
            if ($candidate instanceof ExtraAttributesException) {
                return $candidate;
            }
        }

        return null;
    }

    private function violationsFor(ExtraAttributesException $exception): ConstraintViolationList
    {
        $violations = new ConstraintViolationList();

        foreach ($exception->getExtraAttributes() as $member) {
            $violations->add(new ConstraintViolation(
                self::VIOLATION_MESSAGE,
                self::VIOLATION_MESSAGE,
                [],
                null,
                $member,
                null,
            ));
        }

        return $violations;
    }
}
