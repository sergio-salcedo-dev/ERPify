<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Monitoring\Infrastructure\Sentry;

use ArrayObject;
use Erpify\Shared\ErrorContract\Domain\Exception\DomainException;
use Erpify\Shared\ErrorContract\Domain\Exception\NotFound;
use Erpify\Shared\Monitoring\Infrastructure\Sentry\SentryBeforeSend;
use Erpify\Shared\Monitoring\Infrastructure\Sentry\SentryEventFilter;
use Erpify\Shared\Monitoring\Infrastructure\Sentry\SentryEventScrubber;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Sentry\ClientBuilder;
use Sentry\Event;
use Sentry\Integration\RequestIntegration;
use Sentry\SentryBundle\Integration\RequestFetcher;
use Sentry\SentrySdk;
use Sentry\State\Hub;
use Sentry\State\HubInterface;
use Sentry\Transport\Result;
use Sentry\Transport\ResultStatus;
use Sentry\Transport\TransportInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Yaml\Yaml;

/**
 * The filter's "only on an HTTP request" condition reads request data off the event, which nothing in
 * the filter itself puts there: the SDK's `RequestIntegration`, fed by the bundle's `RequestFetcher`,
 * fills it in a global event processor that `Client::prepareEvent()` runs before `before_send`. This
 * runs that real pipeline — SDK client, integration, fetcher over a request stack — so an SDK release
 * reordering the two, or a configuration dropping the integration, reds here rather than silently
 * reporting every domain 4xx, or none.
 *
 * The coupling is the pipeline itself — client, hub, integration, fetcher, transport and the two
 * `before_send` halves are what is under test.
 *
 * @internal
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 */
#[CoversClass(SentryEventFilter::class)]
final class SentryClientErrorPipelineTest extends TestCase
{
    /**
     * One stack for the whole class: `RequestIntegration::setupOnce()` registers its processor once per
     * process, bound to the FIRST integration instance and therefore to that instance's fetcher.
     */
    private static ?RequestStack $requestStack = null;

    private HubInterface $previousHub;

    /** @var ArrayObject<int, Event> */
    private ArrayObject $sent;

    protected function setUp(): void
    {
        $this->previousHub = SentrySdk::getCurrentHub();
        $this->sent = new ArrayObject();
        while ($this->requestStack()->getCurrentRequest() instanceof Request) {
            $this->requestStack()->pop();
        }
    }

    protected function tearDown(): void
    {
        SentrySdk::setCurrentHub($this->previousHub);
    }

    public function testADomainClientErrorRaisedWhileARequestIsInFlightIsNotSent(): void
    {
        $this->requestStack()->push(Request::create('https://erpify.test/api/v1/banks/x', 'GET'));

        $this->hub()->captureException($this->domainNotFound());

        $this->assertCount(0, $this->sent);
    }

    public function testTheSameErrorWithNoRequestInFlightIsSent(): void
    {
        $this->hub()->captureException($this->domainNotFound());

        $this->assertCount(1, $this->sent);
    }

    public function testTheDeployedConfigurationKeepsTheRequestIntegration(): void
    {
        // The bundle adds `RequestIntegration` only while `default_integrations` is on and no custom
        // `integrations:` list replaces the defaults (`SentryExtension::configureRequestIntegration()`).
        $config = Yaml::parseFile(\dirname(__DIR__, 6) . '/config/packages/sentry.yaml');
        $this->assertIsArray($config);

        foreach (['when@dev', 'when@prod'] as $environment) {
            $block = $config[$environment] ?? null;
            $this->assertIsArray($block, $environment);
            $sentry = $block['sentry'] ?? null;
            $this->assertIsArray($sentry, $environment);
            $options = $sentry['options'] ?? null;
            $this->assertIsArray($options, \sprintf('%s declares no sentry options.', $environment));
            $this->assertTrue($options['default_integrations'] ?? true, $environment);
            $this->assertArrayNotHasKey('integrations', $options, $environment);
        }
    }

    private function requestStack(): RequestStack
    {
        self::$requestStack ??= new RequestStack();

        return self::$requestStack;
    }

    private function hub(): HubInterface
    {
        $client = ClientBuilder::create([
            'dsn' => 'https://public@sentry.invalid/1',
            'default_integrations' => false,
            'integrations' => [new RequestIntegration(new RequestFetcher($this->requestStack()))],
            'before_send' => new SentryBeforeSend(new SentryEventFilter(), new SentryEventScrubber()),
        ])->setTransport($this->recordingTransport())->getClient();
        $hub = new Hub($client);
        SentrySdk::setCurrentHub($hub);

        return $hub;
    }

    private function recordingTransport(): TransportInterface
    {
        $sent = $this->sent;
        $transport = $this->createStub(TransportInterface::class);
        $transport->method('send')->willReturnCallback(static function (Event $event) use ($sent): Result {
            $sent->append($event);

            return new Result(ResultStatus::success(), $event);
        });
        $transport->method('close')->willReturn(new Result(ResultStatus::success()));

        return $transport;
    }

    private function domainNotFound(): DomainException
    {
        return new class ('not-found', 'gone') extends DomainException implements NotFound {
        };
    }
}
