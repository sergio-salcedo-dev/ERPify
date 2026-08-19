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
 * records to `php://stderr`: the sink with no rotation, no TTL and no owner of erasure.
 *
 * **Both halves are read from the container**, because a test that names its own processors proves nothing
 * about the application's, and a test that builds its own activation strategy from a literal stays green while
 * the configured exclusion is deleted.
 *
 * **The channel sweep reads public AND private ids.** `TestContainer::getServiceIds()` answers with the public
 * container alone, and MonologBundle makes public only the channels named under `monolog.channels` — five of
 * the seventeen here, excluding both `app` (every `Erpify\` class) and `request` (where Symfony's
 * `ErrorListener` writes the very record this file fabricates). Enumerating through that API alone swept 29%
 * of the channels while reading as exhaustive, so the ids come from the public container UNION the test
 * private-services locator, and the presence of those two channels is asserted rather than assumed.
 *
 * What a green proves: no processor enrolled on any channel logger destroys the throwable or its status; every
 * configured excluded code stays out of the sink; and a code that is NOT excluded still reaches it, so that
 * emptiness is a working exclusion rather than an apparatus that never fires.
 *
 * What it does not prove: anything the DECLARATION half asserts — that every environment declares the same
 * non-empty exclusion, and that no processor is enrolled on the gate handler where no sweep here could see it
 * — which is `MonologExclusionDeclarationGateTest`, kernel-free because only the `test` declaration is
 * reachable from a booted one. Nor anything about a processor pushed onto a logger at runtime rather than
 * wired, nor about the path taken when no request is in the stack: off-request the strategy returns its inner
 * result and the exclusion is never consulted, which is a property of the deployed dependency.
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
        // Booted once: `bootKernel()` shuts the previous kernel down, and the gate holds its container.
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
            $sink = $this->gate()->flushRecordsFor($this->aRecordCarrying($code), $this->processorsOfTheAppChannel());

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

        $sink = $this->gate()->flushRecordsFor(
            $this->aRecordCarrying(self::UNEXCLUDED_STATUS),
            $this->processorsOfTheAppChannel(),
        );

        $this->assertNotSame(
            [],
            $sink->getRecords(),
            'the handler never activates at all, so the emptiness asserted elsewhere proves nothing',
        );
    }

    protected function tearDown(): void
    {
        // The stack is the container's, shared with whatever runs next in this kernel.
        $this->gate?->releaseRequests();
        $this->gate = null;
        parent::tearDown();
    }

    private function gate(): DeployedFingersCrossedGate
    {
        if (!$this->gate instanceof DeployedFingersCrossedGate) {
            self::bootKernel();
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
