<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Monitoring\Infrastructure\Sentry;

use ArrayObject;
use Erpify\Shared\Monitoring\Infrastructure\Sentry\SentryBeforeSend;
use Erpify\Shared\Monitoring\Infrastructure\Sentry\SentryBeforeSendTransaction;
use Erpify\Shared\Monitoring\Infrastructure\Sentry\SentryEventFilter;
use Erpify\Shared\Monitoring\Infrastructure\Sentry\SentryEventScrubber;
use Erpify\Tests\Support\Dig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Sentry\ClientBuilder;
use Sentry\Event;
use Sentry\Integration\RequestIntegration;
use Sentry\Options;
use Sentry\SentryBundle\Integration\RequestFetcher;
use Sentry\Serializer\PayloadSerializer;
use Sentry\State\Hub;
use Sentry\Tracing\Span;
use Sentry\Transport\Result;
use Sentry\Transport\ResultStatus;
use Sentry\Transport\TransportInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Yaml\Yaml;

/**
 * The evidence PUT carries the caller's original filename in its query and the original itself as its body, and
 * Sentry has two ways past the error hook's scrubber: transactions (a second event stream `before_send` never
 * sees) and a raw request body (up to 10,000 bytes on an error event at the SDK's default). Each is fed through
 * the hooks exactly as the SDK would, and the SERIALISED event is searched, because that is what leaves.
 *
 * @internal
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 */
#[CoversClass(SentryBeforeSendTransaction::class)]
#[CoversClass(SentryEventScrubber::class)]
final class SentryUploadRedactionTest extends TestCase
{
    private const string SECRET = 'SECRET-name-of-a-person.pdf';

    private const string UPLOAD_URL
        = 'https://erpify.test/api/v1/documents/uploads/0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1a2c/content';

    public function testATransactionCarryingTheFilenameLeavesWithoutIt(): void
    {
        $event = Event::createTransaction();
        $event->setRequest([
            'url' => self::UPLOAD_URL . '?filename=' . self::SECRET,
            'query_string' => 'filename=' . self::SECRET,
            'method' => 'PUT',
        ]);
        $event->setContext('trace', [
            'trace_id' => \str_repeat('a', 32),
            'span_id' => \str_repeat('b', 16),
            'data' => [
                'http.url' => self::UPLOAD_URL . '?filename=' . self::SECRET,
                'http.query' => 'filename=' . self::SECRET,
            ],
        ]);
        $span = new Span();
        $span->setData(['url.full' => self::UPLOAD_URL . '?file_name=' . self::SECRET]);

        $event->setSpans([$span]);

        $sent = (new SentryBeforeSendTransaction(new SentryEventScrubber()))($event);

        $this->assertStringNotContainsString(self::SECRET, $this->serialised($sent));
    }

    public function testAnErrorOnAFiveBytePutLeavesWithoutTheBodyOrTheFilename(): void
    {
        $requests = new RequestStack([Request::create(
            self::UPLOAD_URL . '?filename=' . self::SECRET,
            'PUT',
            server: ['CONTENT_TYPE' => 'text/plain', 'CONTENT_LENGTH' => '5'],
            content: 'BODY5',
        )]);
        /** @var ArrayObject<int, Event> $sent */
        $sent = new ArrayObject();

        $hub = new Hub(ClientBuilder::create($this->deployedOptions() + [
            'dsn' => 'https://public@sentry.invalid/1',
            'default_integrations' => false,
            'integrations' => [new RequestIntegration(new RequestFetcher($requests))],
            'before_send' => new SentryBeforeSend(new SentryEventFilter(), new SentryEventScrubber()),
        ])->setTransport($this->recordingTransport($sent))->getClient());

        $hub->captureException(new RuntimeException('a fault'));

        $events = $sent->getArrayCopy();
        $this->assertCount(1, $events);
        $event = $events[0] ?? null;
        $this->assertInstanceOf(Event::class, $event);
        $serialised = $this->serialised($event);
        $this->assertStringNotContainsString(self::SECRET, $serialised);
        $this->assertStringNotContainsString('BODY5', $serialised);
    }

    public function testAStringBodyThatArrivesAnywayIsDropped(): void
    {
        $event = Event::createEvent();
        $event->setRequest(['url' => self::UPLOAD_URL, 'data' => 'BODY5']);

        $scrubbed = (new SentryEventScrubber())($event);

        $this->assertArrayNotHasKey('data', $scrubbed->getRequest());
    }

    public function testBothDeployedEnvironmentsWireTheTransactionHookAndNeverAttachABody(): void
    {
        $config = Yaml::parseFile(\dirname(__DIR__, 6) . '/config/packages/sentry.yaml');
        $this->assertIsArray($config);

        foreach (['when@dev', 'when@prod'] as $environment) {
            $options = Dig::array($config, $environment, 'sentry', 'options');
            $this->assertSame(
                SentryBeforeSendTransaction::class,
                $options['before_send_transaction'] ?? null,
                $environment,
            );
            $this->assertSame('never', $options['max_request_body_size'] ?? null, $environment);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function deployedOptions(): array
    {
        $config = Yaml::parseFile(\dirname(__DIR__, 6) . '/config/packages/sentry.yaml');
        $this->assertIsArray($config);
        $size = Dig::string($config, 'when@prod', 'sentry', 'options', 'max_request_body_size');

        return ['max_request_body_size' => $size];
    }

    private function serialised(Event $event): string
    {
        return (new PayloadSerializer(new Options(['dsn' => 'https://public@sentry.invalid/1'])))->serialize($event);
    }

    /**
     * @param ArrayObject<int, Event> $sent
     */
    private function recordingTransport(ArrayObject $sent): TransportInterface
    {
        $transport = $this->createStub(TransportInterface::class);
        $transport->method('send')->willReturnCallback(static function (Event $event) use ($sent): Result {
            $sent->append($event);

            return new Result(ResultStatus::success(), $event);
        });
        $transport->method('close')->willReturn(new Result(ResultStatus::success()));

        return $transport;
    }
}
