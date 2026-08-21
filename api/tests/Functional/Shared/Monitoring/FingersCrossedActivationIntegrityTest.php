<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Shared\Monitoring;

use DateTimeImmutable;
use Erpify\Tests\Support\DeployedFingersCrossedGate;
use Monolog\Level;
use Monolog\Logger;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Contracts\Service\ServiceProviderInterface;
use Throwable;

/**
 * `excluded_http_codes` decides what stays out of an unrotated sink, and it decides it by reading an OBJECT.
 *
 * `HttpCodeActivationStrategy` asks whether `context['exception']` is an `HttpExceptionInterface`. Logger
 * processors run before any handler sees the record, so a processor that replaces that Throwable with an
 * array — a normalised map, a string, anything — leaves the strategy unable to recognise a 404. The handler
 * then activates on a request the configuration excludes by name and flushes its whole buffer of preceding
 * records to `php://stderr`: the sink with a size bound but no TTL and no owner of erasure.
 *
 * **The processors AND the activation strategy are read from the container**, because a test that names its
 * own processors proves nothing about the application's, and one that builds its own activation strategy from
 * a literal stays green while the configured exclusion is deleted.
 *
 * **The channel sweep reads public AND private ids.** `TestContainer::getServiceIds()` answers with the public
 * container alone, and MonologBundle makes public only the channels named under `monolog.channels` — five of
 * the seventeen here, excluding both `app` (every `Erpify\` class) and `request` (where Symfony's
 * `ErrorListener` writes the very record this file fabricates). Enumerating through that API alone swept 29%
 * of the channels while reading as exhaustive, so the ids come from the public container UNION the test
 * private-services locator, and the presence of those two channels is asserted rather than assumed.
 *
 * What a green proves: no processor enrolled on any channel logger destroys the throwable or its status; every
 * configured excluded code stays out of the sink; a code that is NOT excluded still reaches it, so that
 * emptiness is a working exclusion rather than an apparatus that never fires; and, with no request in the
 * stack, an excluded code reaches the sink too — the exclusion is consulted only while `getMainRequest()`
 * answers. **That last one pins a hole, not a protection**, and it is pinned rather than described because it
 * is what makes the request path named by the two measurements above load-bearing instead of incidental.
 *
 * The hole has no producer here: nothing in `api/src` raises a 404 or 405 `HttpExceptionInterface` at all — a
 * "404" in this codebase is the `NotFound` marker interface, and the status is applied by
 * `ProblemDetailsFactory` — and what the worker logs is whatever the bus threw, a `HandlerFailedException` on
 * the ordinary path, never an `HttpExceptionInterface`.
 *
 * The on-request population is likewise narrower than the surface a reader assumes, and it is not `/api/*`:
 * `ExceptionResponder` is subscribed to `kernel.exception` at priority 16 and calls `setResponse()`, which
 * `ExceptionEvent` inherits from `RequestEvent` and which stops propagation, so HttpKernel's
 * `ErrorListener::logKernelException` — subscribed to the same event at priority 0 — never runs there. The
 * exception surface then writes only the responder's own line, which carries `exception_class` /
 * `exception_message` but no `exception` key at all, so nothing there can match an exclusion whatever its
 * level (`warning` for a status-mapped 4xx anyway, below `action_level: error`).
 *
 * **That is not the same as saying nothing else logs a throwable during an `/api/*` request** — a dozen sites
 * in `api/src` pass `['exception' => …]`, and the `*BestEffort` collaborators do it on the `app` channel at
 * `error`, which activates the deployed handler. What makes the exclusion inert here is narrower and exact:
 * `logKernelException` is the only producer of an `HttpExceptionInterface` under that key, and it does not
 * run on this surface. What `excluded_http_codes` actually holds is therefore the routing 404/405 raised
 * OUTSIDE `/api/` — scanner traffic Caddy hands to PHP, where `RouterListener` throws a real
 * `NotFoundHttpException` and `ErrorListener` does log — and that path carries a main request by
 * construction.
 *
 * What it does not prove: anything the DECLARATION half asserts — that every environment declares the same
 * non-empty exclusion, and that no processor is enrolled on the gate handler where no sweep here could see it
 * — which is `MonologExclusionDeclarationGateTest`, kernel-free because only the `test` declaration is
 * reachable from a booted one. Nor anything about a processor pushed onto a logger at runtime rather than
 * wired.
 *
 * @internal
 */
#[CoversNothing]
final class FingersCrossedActivationIntegrityTest extends KernelTestCase
{
    /** Channels whose absence from the sweep is the failure this file exists to make impossible. */
    private const array MANDATORY_CHANNEL_IDS = ['monolog.logger', 'monolog.logger.request'];

    /** A status no environment excludes, used as the positive control. */
    private const int UNEXCLUDED_STATUS = 500;

    private ?DeployedFingersCrossedGate $gate = null;

    protected function setUp(): void
    {
        parent::setUp();
        // Booted here and nowhere else. `bootKernel()` shuts the previous kernel down, so a second boot inside
        // `gate()` would hand this test a container while the loggers it had already swept belonged to a dead
        // one.
        self::bootKernel();
    }

    #[Test]
    public function noEnrolledProcessorHidesAnHttpExceptionFromTheActivationStrategy(): void
    {
        $channels = $this->channelLoggers();

        foreach (self::MANDATORY_CHANNEL_IDS as $mandatory) {
            $this->assertArrayHasKey(
                $mandatory,
                $channels,
                \sprintf('"%s" was not swept, so nothing here asserts anything about it', $mandatory),
            );
        }

        $excluded = $this->gate()->unconditionallyExcludedCodes();
        $this->assertNotEmpty($excluded);

        foreach ($channels as $id => $logger) {
            // The record carries the channel of the logger about to process it: a processor that branches on
            // `$record->channel` would otherwise run here under a name it never sees in production, take the
            // wrong branch, and leave this assertion green over the very processor that blinds the strategy.
            $record = $this->aRecordCarrying($excluded[0], $logger->getName());

            foreach ($logger->getProcessors() as $processor) {
                $record = $processor($record);
            }

            $exception = $record->context['exception'] ?? null;

            // `HttpExceptionInterface` and not `Throwable`, because that is what the strategy asks for: a
            // processor that wrapped the 404 in a plain exception, preserving it as `previous`, would satisfy
            // the looser assertion and still blind the exclusion.
            $this->assertInstanceOf(
                HttpExceptionInterface::class,
                $exception,
                \sprintf('a processor on "%s" replaced the throwable the activation strategy reads', $id),
            );
            $this->assertSame(
                $excluded[0],
                $exception->getStatusCode(),
                \sprintf('a processor on "%s" changed the status the exclusion matches on', $id),
            );
        }
    }

    #[Test]
    public function theConfiguredExclusionKeepsEveryExcludedCodeOutOfTheSink(): void
    {
        $codes = $this->gate()->unconditionallyExcludedCodes();
        $this->assertNotEmpty($codes, 'the deployed strategy excludes nothing, so every 404 flushes the buffer');

        foreach ($codes as $code) {
            $sink = $this->gate()->flushOnRequest($this->aRecordCarrying($code), $this->processorsOfTheAppChannel());

            $this->assertSame(
                [],
                $sink->getRecords(),
                \sprintf('a %d flushed the buffer into the unrotated sink', $code),
            );
        }
    }

    /**
     * Without this, the emptiness above is satisfied by an apparatus that never fires at all — raising
     * `action_level` to `critical` leaves every assertion in the previous test green for a reason that has
     * nothing to do with `excluded_http_codes`.
     */
    #[Test]
    public function aStatusOutsideTheExclusionStillReachesTheSink(): void
    {
        $this->assertNotContains(
            self::UNEXCLUDED_STATUS,
            $this->gate()->unconditionallyExcludedCodes(),
            'the control status is itself excluded, so it cannot tell a working exclusion from an inert handler',
        );

        // On-request, and named here rather than arranged elsewhere: off-request every status reaches the
        // sink, so a control measured there would be green for a reason that has nothing to do with the
        // exclusion it is controlling for.
        $sink = $this->gate()->flushOnRequest(
            $this->aRecordCarrying(self::UNEXCLUDED_STATUS),
            $this->processorsOfTheAppChannel(),
        );

        $this->assertNotSame(
            [],
            $sink->getRecords(),
            'the handler never activates at all, so the emptiness asserted elsewhere proves nothing',
        );
    }

    /**
     * The scope note above says the exclusion is never consulted off-request. Pinned rather than left as prose:
     * this is the other half of the measurement two tests up — same excluded code, same handler, opposite
     * request path, opposite outcome — which is what makes naming that path a measurement rather than a
     * ceremony. It records a hole in the deployed dependency, one with no producer here, and it is what would
     * notice a release closing that hole, which would make this file's scope statement, and the paragraph
     * `api/CLAUDE.md` repeats from it, quietly false.
     */
    #[Test]
    public function offRequestTheExclusionIsNotConsultedAndAnExcludedCodeReachesTheSink(): void
    {
        $codes = $this->gate()->unconditionallyExcludedCodes();
        $this->assertNotEmpty($codes, 'the deployed strategy excludes nothing, so this measures no exclusion');

        $sink = $this->gate()->flushOffRequest(
            $this->aRecordCarrying($codes[0]),
            $this->processorsOfTheAppChannel(),
        );

        $this->assertNotSame(
            [],
            $sink->getRecords(),
            \sprintf(
                'a %d stayed out of the sink with no request in the stack, so the deployed strategy no longer '
                . 'needs one and this file\'s scope statement is now wrong — most likely a dependency upgrade '
                . 'closing the hole, which is an improvement to record rather than a regression to revert',
                $codes[0],
            ),
        );
    }

    protected function tearDown(): void
    {
        $this->gate = null;
        parent::tearDown();
    }

    private function gate(): DeployedFingersCrossedGate
    {
        if (!$this->gate instanceof DeployedFingersCrossedGate) {
            $this->gate = new DeployedFingersCrossedGate(self::getContainer());
        }

        return $this->gate;
    }

    /**
     * Public ids come from the public container, private ones from the test locator — the union is what the
     * application actually enrols processors on.
     *
     * @return array<string, Logger>
     */
    private function channelLoggers(): array
    {
        $container = self::getContainer();

        $privateLocator = $container->get('test.private_services_locator');
        $this->assertInstanceOf(
            ServiceProviderInterface::class,
            $privateLocator,
            'private services are unreachable, so this would sweep the public channels alone',
        );

        $ids = \array_unique([...$container->getServiceIds(), ...\array_keys($privateLocator->getProvidedServices())]);
        \sort($ids);

        $loggers = [];

        foreach ($ids as $id) {
            if (!\str_starts_with($id, 'monolog.logger')) {
                continue;
            }

            try {
                $service = $container->get($id);
            } catch (Throwable $throwable) {
                $this->fail(\sprintf('channel logger "%s" could not be read: %s', $id, $throwable->getMessage()));
            }

            if ($service instanceof Logger) {
                $loggers[$id] = $service;
            }
        }

        return $loggers;
    }

    /**
     * @return list<callable(LogRecord): LogRecord>
     */
    private function processorsOfTheAppChannel(): array
    {
        $logger = self::getContainer()->get('monolog.logger');
        $this->assertInstanceOf(Logger::class, $logger);

        return \array_values($logger->getProcessors());
    }

    private function aRecordCarrying(int $status, string $channel = 'app'): LogRecord
    {
        return new LogRecord(
            new DateTimeImmutable(),
            $channel,
            Level::Error,
            'Uncaught PHP Exception',
            ['exception' => new HttpException($status)],
        );
    }
}
