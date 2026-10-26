<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Monitoring\Infrastructure\Sentry;

use Erpify\Iam\Session\Domain\Exception\SessionNoLongerActive;
use Erpify\Kernel;
use Erpify\Shared\ErrorContract\Domain\Exception\ClientError;
use Erpify\Shared\ErrorContract\Domain\Exception\Conflict;
use Erpify\Shared\ErrorContract\Domain\Exception\DomainException;
use Erpify\Shared\ErrorContract\Domain\Exception\InvalidInput;
use Erpify\Shared\ErrorContract\Domain\Exception\InvalidSearchCriteria;
use Erpify\Shared\ErrorContract\Domain\Exception\InvariantViolation;
use Erpify\Shared\ErrorContract\Domain\Exception\NotFound;
use Erpify\Shared\ErrorContract\Domain\Exception\ServiceUnavailable;
use Erpify\Shared\ErrorContract\Domain\Exception\Unauthenticated;
use Erpify\Shared\Monitoring\Domain\RoutineOutcome;
use Erpify\Shared\Monitoring\Infrastructure\Sentry\SentryEventFilter;
use FilesystemIterator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use Sentry\Event;
use Sentry\EventHint;
use SplFileInfo;
use Throwable;

/**
 * What {@see SentryEventFilter} drops on an HTTP request: one case per expected-outcome marker, so moving
 * one onto the signal list reds here, and the routine outcome, whose members are pinned as a set because
 * the marker outranks every security signal.
 *
 * The coupling is the corpus — one marker per case — plus the source walk that pins the set.
 *
 * @internal
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 */
#[CoversClass(SentryEventFilter::class)]
final class SentryEventFilterDroppedOutcomeTest extends TestCase
{
    #[DataProvider('provideDropsAnExpectedOutcomeOnAnHttpRequestCases')]
    public function testDropsAnExpectedOutcomeOnAnHttpRequest(DomainException $exception): void
    {
        $this->assertNotInstanceOf(Event::class, $this->filter($this->httpEvent(), $exception));
    }

    /**
     * @return iterable<string, array{DomainException}>
     */
    public static function provideDropsAnExpectedOutcomeOnAnHttpRequestCases(): iterable
    {
        yield 'not found (404)' => [
            new class ('not-found', 'gone') extends DomainException implements NotFound {
            },
        ];
        yield 'conflict (409)' => [
            new class ('conflict', 'in use') extends DomainException implements Conflict {
            },
        ];
        yield 'invariant violation (422)' => [
            new class ('invariant', 'broken') extends DomainException implements InvariantViolation {
            },
        ];
        yield 'invalid input (400)' => [
            new class ('invalid', 'bad') extends DomainException implements InvalidInput {
            },
        ];
        yield 'invalid search criteria (422)' => [
            new class ('invalid-search', 'bad') extends DomainException implements InvalidSearchCriteria {
            },
        ];
    }

    /**
     * The one routine 401: every returning user with an expired session raises it, and it outranks the
     * `Unauthenticated` signal it also implements.
     */
    public function testDropsASessionNoLongerActiveOnAnHttpRequest(): void
    {
        $result = $this->filter($this->httpEvent(), SessionNoLongerActive::forRequest());

        $this->assertNotInstanceOf(Event::class, $result);
    }

    public function testKeepsASessionNoLongerActiveRaisedOutsideAnHttpRequest(): void
    {
        $event = Event::createEvent();

        $this->assertSame($event, $this->filter($event, SessionNoLongerActive::forRequest()));
    }

    #[DataProvider('provideReportsARoutineOutcomeOnANonClientStatusCases')]
    public function testReportsARoutineOutcomeOnANonClientStatus(DomainException $exception): void
    {
        $event = $this->httpEvent();

        $this->assertSame($event, $this->filter($event, $exception));
    }

    /**
     * The routine marker maps no status, so it is dropped only beside a status-bearing 4xx marker. A bare
     * `ClientError` maps none either: the contract answers it 500.
     *
     * @return iterable<string, array{DomainException}>
     */
    public static function provideReportsARoutineOutcomeOnANonClientStatusCases(): iterable
    {
        yield 'routine without a client-error marker (500)' => [
            new class ('unexpected-state', 'corrupt') extends DomainException implements RoutineOutcome {
            },
        ];
        yield 'routine beside a bare client error (500)' => [
            new class ('unexpected-state', 'corrupt') extends DomainException implements
                RoutineOutcome,
                ClientError
            {
            },
        ];
        yield 'routine beside service unavailable (503)' => [
            new class ('down', 'store down') extends DomainException implements
                RoutineOutcome,
                Unauthenticated,
                ServiceUnavailable
            {
                public const string TYPE = 'store-down';
            },
        ];
    }

    /**
     * The marker silences whatever security signal sits beside it, so a new member is a monitoring decision
     * taken in review, never a side effect of a refactor. Read by text, so every path to the marker passes
     * through a file naming it: an intermediate interface extending it is a member in its own right and reds
     * here, which covers the classes implementing it. A `class_alias()` or a name built from strings escapes.
     */
    public function testTheRoutineOutcomesAreExactlyTheDecidedOnes(): void
    {
        $kernel = new ReflectionClass(Kernel::class);
        $sourceRoot = \dirname((string) $kernel->getFileName());
        $marker = new ReflectionClass(RoutineOutcome::class);
        $scanned = [];
        $members = [];

        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($sourceRoot, FilesystemIterator::SKIP_DOTS),
        );

        /** @var SplFileInfo $file */
        foreach ($files as $file) {
            if ('php' !== $file->getExtension()) {
                continue;
            }

            if (!\str_contains((string) \file_get_contents($file->getPathname()), 'RoutineOutcome')) {
                continue;
            }

            $scanned[] = $file->getPathname();
            $relative = \substr($file->getPathname(), \strlen($sourceRoot) + 1, -4);
            $class = 'Erpify\\' . \str_replace('/', '\\', $relative);

            $declared = \class_exists($class) || \interface_exists($class);

            if ($declared && \is_subclass_of($class, RoutineOutcome::class)) {
                $members[] = $class;
            }
        }

        $this->assertContains($marker->getFileName(), $scanned, 'The source walk did not reach the marker itself.');
        $this->assertSame(
            [SessionNoLongerActive::class],
            $members,
            'A RoutineOutcome is dropped from Sentry over any security signal it carries; adding one is a decision.',
        );
    }

    private function httpEvent(): Event
    {
        $event = Event::createEvent();
        $event->setRequest(['url' => 'https://erpify.test/api/v1/probe', 'method' => 'POST']);

        return $event;
    }

    private function filter(Event $event, Throwable $throwable): ?Event
    {
        return (new SentryEventFilter())($event, EventHint::fromArray(['exception' => $throwable]));
    }
}
