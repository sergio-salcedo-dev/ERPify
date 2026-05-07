<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Shared\Infrastructure\Http;

use Erpify\Shared\Infrastructure\Http\CorrelationIdListener;
use PHPUnit\Framework\Attributes\CoversNothing;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Integration test for the `CorrelationIdListener` listener. Exercises the full pipeline through
 * a real Symfony kernel: the listener fires on `kernel.request` *before* the controller runs (or
 * throws), so we assert against the request the kernel handed to the dispatcher.
 *
 * Reuses Story 1.4's `/api/test/_throw-not-found` route — the controller throws, but the listener
 * has already populated `$request->attributes->get(CorrelationIdListener::ATTRIBUTE_KEY)` by then.
 *
 * @internal
 */
#[CoversNothing]
final class CorrelationIdListenerFunctionalTest extends WebTestCase
{
    private const string UUID_V7_REGEX = '/\A[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/';

    private const string VALID_UUID_V7 = '0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1a2c';

    public function testRequestWithoutInboundHeaderHasMintedCorrelationIdInAttributes(): void
    {
        $kernelBrowser = self::createClient();
        $kernelBrowser->catchExceptions(true);
        $kernelBrowser->request(Request::METHOD_GET, '/api/test/_throw-not-found');

        $stored = $kernelBrowser->getRequest()->attributes->get(CorrelationIdListener::ATTRIBUTE_KEY);

        $this->assertIsString($stored);
        $this->assertMatchesRegularExpression(self::UUID_V7_REGEX, $stored);
    }

    public function testRequestWithValidInboundHeaderPropagatesItVerbatim(): void
    {
        $kernelBrowser = self::createClient();
        $kernelBrowser->catchExceptions(true);
        $kernelBrowser->request(
            method: Request::METHOD_GET,
            uri: '/api/test/_throw-not-found',
            server: ['HTTP_X_CORRELATION_ID' => self::VALID_UUID_V7],
        );

        $this->assertSame(
            self::VALID_UUID_V7,
            $kernelBrowser->getRequest()->attributes->get(CorrelationIdListener::ATTRIBUTE_KEY),
        );
    }

    public function testRequestWithMalformedInboundHeaderHasFreshlyMintedCorrelationId(): void
    {
        $kernelBrowser = self::createClient();
        $kernelBrowser->catchExceptions(true);
        $kernelBrowser->request(
            method: Request::METHOD_GET,
            uri: '/api/test/_throw-not-found',
            server: ['HTTP_X_CORRELATION_ID' => 'not-a-uuid'],
        );

        $stored = $kernelBrowser->getRequest()->attributes->get(CorrelationIdListener::ATTRIBUTE_KEY);

        $this->assertIsString($stored);
        $this->assertMatchesRegularExpression(self::UUID_V7_REGEX, $stored);
        $this->assertNotSame('not-a-uuid', $stored);
    }

    public function testListenerIsRegisteredOnKernelRequestWithExpectedPriority(): void
    {
        self::createClient();

        $eventDispatcher = self::getContainer()->get('event_dispatcher');
        $this->assertInstanceOf(EventDispatcherInterface::class, $eventDispatcher);

        $found = null;

        foreach ($eventDispatcher->getListeners(KernelEvents::REQUEST) as $listener) {
            $target = \is_array($listener) ? ($listener[0] ?? null) : $listener;

            if ($target instanceof CorrelationIdListener) {
                $found = $listener;

                break;
            }
        }

        $this->assertNotNull($found, 'CorrelationIdListener must be registered on kernel.request.');
        \assert(\is_callable($found));

        $priority = $eventDispatcher->getListenerPriority(KernelEvents::REQUEST, $found);
        $this->assertSame(
            CorrelationIdListener::PRIORITY,
            $priority,
            'Listener priority must match CorrelationIdListener::PRIORITY (1024) — FR43-style pin.',
        );
    }

    public function testResponseCarriesXCorrelationIdHeaderWithMintedUuidWhenInboundAbsent(): void
    {
        $kernelBrowser = self::createClient();
        $kernelBrowser->catchExceptions(true);
        $kernelBrowser->request(Request::METHOD_GET, '/api/test/_throw-not-found');

        $headerValue = $kernelBrowser->getResponse()->headers->get(CorrelationIdListener::HEADER_NAME);

        $this->assertIsString($headerValue);
        $this->assertMatchesRegularExpression(self::UUID_V7_REGEX, $headerValue);
    }

    public function testResponseEchoesValidInboundXCorrelationIdHeaderVerbatim(): void
    {
        $kernelBrowser = self::createClient();
        $kernelBrowser->catchExceptions(true);
        $kernelBrowser->request(
            method: Request::METHOD_GET,
            uri: '/api/test/_throw-not-found',
            server: ['HTTP_X_CORRELATION_ID' => self::VALID_UUID_V7],
        );

        $this->assertSame(
            self::VALID_UUID_V7,
            $kernelBrowser->getResponse()->headers->get(CorrelationIdListener::HEADER_NAME),
        );
    }

    public function testResponseHasFreshlyMintedXCorrelationIdHeaderWhenInboundIsMalformed(): void
    {
        $kernelBrowser = self::createClient();
        $kernelBrowser->catchExceptions(true);
        $kernelBrowser->request(
            method: Request::METHOD_GET,
            uri: '/api/test/_throw-not-found',
            server: ['HTTP_X_CORRELATION_ID' => 'not-a-uuid'],
        );

        $headerValue = $kernelBrowser->getResponse()->headers->get(CorrelationIdListener::HEADER_NAME);

        $this->assertIsString($headerValue);
        $this->assertMatchesRegularExpression(self::UUID_V7_REGEX, $headerValue);
        $this->assertNotSame('not-a-uuid', $headerValue);
    }

    public function testTwoXxResponseCarriesXCorrelationIdHeader(): void
    {
        $kernelBrowser = self::createClient();
        $kernelBrowser->request(Request::METHOD_GET, '/api/v1/health');

        $this->assertSame(\Symfony\Component\HttpFoundation\Response::HTTP_OK, $kernelBrowser->getResponse()->getStatusCode(), (string) $kernelBrowser->getResponse()->getContent());
        $headerValue = $kernelBrowser->getResponse()->headers->get(CorrelationIdListener::HEADER_NAME);

        $this->assertIsString($headerValue);
        $this->assertMatchesRegularExpression(self::UUID_V7_REGEX, $headerValue);
    }

    public function testTwoXxResponseEchoesValidInboundHeaderVerbatim(): void
    {
        $kernelBrowser = self::createClient();
        $kernelBrowser->request(
            method: Request::METHOD_GET,
            uri: '/api/v1/health',
            server: ['HTTP_X_CORRELATION_ID' => self::VALID_UUID_V7],
        );

        $this->assertSame(\Symfony\Component\HttpFoundation\Response::HTTP_OK, $kernelBrowser->getResponse()->getStatusCode(), (string) $kernelBrowser->getResponse()->getContent());
        $this->assertSame(
            self::VALID_UUID_V7,
            $kernelBrowser->getResponse()->headers->get(CorrelationIdListener::HEADER_NAME),
        );
    }

    public function testResponseListenerIsRegisteredOnKernelResponseWithExpectedPriority(): void
    {
        self::createClient();

        $eventDispatcher = self::getContainer()->get('event_dispatcher');
        $this->assertInstanceOf(EventDispatcherInterface::class, $eventDispatcher);
        $found = \array_find($eventDispatcher->getListeners(KernelEvents::RESPONSE), static fn ($candidate): bool => \is_array($candidate)
        && ($candidate[0] ?? null) instanceof CorrelationIdListener
        && ($candidate[1] ?? null) === 'onResponse');

        $this->assertNotNull($found, 'CorrelationIdListener::onResponse must be registered on kernel.response.');
        \assert(\is_callable($found));

        $priority = $eventDispatcher->getListenerPriority(KernelEvents::RESPONSE, $found);
        $this->assertSame(
            CorrelationIdListener::RESPONSE_PRIORITY,
            $priority,
            'Listener priority must match CorrelationIdListener::RESPONSE_PRIORITY (-1024) — FR43-style pin.',
        );
    }
}
