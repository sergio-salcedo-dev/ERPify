<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Infrastructure\Http;

use Erpify\Shared\Infrastructure\Http\CorrelationIdListener;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * @internal
 */
#[CoversClass(CorrelationIdListener::class)]
final class CorrelationIdListenerTest extends TestCase
{
    private const string UUID_V7_REGEX = '/\A[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/';

    private const string VALID_UUID_V7 = '0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1a2c';

    public function testListenerHasNoConstructorAndIsFinalReadonly(): void
    {
        $reflectionClass = new ReflectionClass(CorrelationIdListener::class);

        $this->assertTrue($reflectionClass->isFinal(), 'CorrelationIdListener must be `final`.');
        $this->assertTrue($reflectionClass->isReadOnly(), 'CorrelationIdListener must be `readonly`.');
        $this->assertNotInstanceOf(ReflectionMethod::class, $reflectionClass->getConstructor(), 'CorrelationIdListener must have no constructor (worker-mode safe, AR4).');
    }

    public function testListenerPriorityIsPinnedAtClassConstantValue(): void
    {
        // Read via reflection so the assertion is not statically tautological — the test must
        // catch a future edit that changes the constant, even when static analysis can resolve
        // CorrelationIdListener::PRIORITY at parse time.
        $priority = (new ReflectionClass(CorrelationIdListener::class))->getConstant('PRIORITY');

        $this->assertSame(1024, $priority, 'Priority must remain 1024 — Story 2.1 AC #2; bumping requires updating both the constant and this test.');
    }

    public function testAttributeKeyConstantValueIsExactlyUnderscoreCorrelationUnderscoreId(): void
    {
        $attributeKey = (new ReflectionClass(CorrelationIdListener::class))->getConstant('ATTRIBUTE_KEY');

        $this->assertSame('_correlation_id', $attributeKey, 'ATTRIBUTE_KEY must be `_correlation_id` — Stories 2.2/2.3/2.4 reference this constant.');
    }

    public function testAbsentHeaderMintsAFreshUuidV7AndStoresItOnTheRequest(): void
    {
        $request = Request::create('/api/anything');
        $requestEvent = $this->makeMainRequestEvent($request);

        (new CorrelationIdListener())($requestEvent);

        $stored = $request->attributes->get(CorrelationIdListener::ATTRIBUTE_KEY);
        $this->assertIsString($stored);
        $this->assertMatchesRegularExpression(self::UUID_V7_REGEX, $stored);
    }

    public function testWellFormedUuidV7InboundHeaderPropagatesVerbatim(): void
    {
        $request = Request::create('/api/anything', server: ['HTTP_X_CORRELATION_ID' => self::VALID_UUID_V7]);
        $requestEvent = $this->makeMainRequestEvent($request);

        (new CorrelationIdListener())($requestEvent);

        $this->assertSame(self::VALID_UUID_V7, $request->attributes->get(CorrelationIdListener::ATTRIBUTE_KEY));
    }

    public function testWellFormedUuidV7InUppercaseIsRejectedAndFreshIdIsMinted(): void
    {
        $uppercase = '0190E9C2-7B5A-7D40-9C8F-2F9B5D3E1A2C';
        $request = Request::create('/api/anything', server: ['HTTP_X_CORRELATION_ID' => $uppercase]);
        $requestEvent = $this->makeMainRequestEvent($request);

        (new CorrelationIdListener())($requestEvent);

        $stored = $request->attributes->get(CorrelationIdListener::ATTRIBUTE_KEY);
        $this->assertIsString($stored);
        $this->assertMatchesRegularExpression(self::UUID_V7_REGEX, $stored);
        $this->assertNotSame($uppercase, $stored, 'Uppercase UUIDv7 must NOT be propagated verbatim — NFR11 defense-in-depth.');
    }

    public function testEmptyStringInboundHeaderIsRejectedAndFreshIdIsMinted(): void
    {
        $request = Request::create('/api/anything', server: ['HTTP_X_CORRELATION_ID' => '']);
        $requestEvent = $this->makeMainRequestEvent($request);

        (new CorrelationIdListener())($requestEvent);

        $stored = $request->attributes->get(CorrelationIdListener::ATTRIBUTE_KEY);
        $this->assertIsString($stored);
        $this->assertMatchesRegularExpression(self::UUID_V7_REGEX, $stored);
        $this->assertNotSame('', $stored);
    }

    public function testMalformedInboundHeaderWithWrongVersionBitsIsRejected(): void
    {
        // UUIDv4: version-nibble `4`, not `7`.
        $uuidV4 = '0190e9c2-7b5a-4d40-9c8f-2f9b5d3e1a2c';
        $request = Request::create('/api/anything', server: ['HTTP_X_CORRELATION_ID' => $uuidV4]);
        $requestEvent = $this->makeMainRequestEvent($request);

        (new CorrelationIdListener())($requestEvent);

        $stored = $request->attributes->get(CorrelationIdListener::ATTRIBUTE_KEY);
        $this->assertIsString($stored);
        $this->assertMatchesRegularExpression(self::UUID_V7_REGEX, $stored);
        $this->assertNotSame($uuidV4, $stored);
    }

    public function testMalformedInboundHeaderWithWrongVariantBitsIsRejected(): void
    {
        // Variant-nibble `7` instead of `[89ab]`.
        $bad = '0190e9c2-7b5a-7d40-7c8f-2f9b5d3e1a2c';
        $request = Request::create('/api/anything', server: ['HTTP_X_CORRELATION_ID' => $bad]);
        $requestEvent = $this->makeMainRequestEvent($request);

        (new CorrelationIdListener())($requestEvent);

        $stored = $request->attributes->get(CorrelationIdListener::ATTRIBUTE_KEY);
        $this->assertIsString($stored);
        $this->assertMatchesRegularExpression(self::UUID_V7_REGEX, $stored);
        $this->assertNotSame($bad, $stored);
    }

    public function testMalformedInboundHeaderWithExtraGarbageIsRejected(): void
    {
        $bad = self::VALID_UUID_V7 . '<script>alert(1)</script>';
        $request = Request::create('/api/anything', server: ['HTTP_X_CORRELATION_ID' => $bad]);
        $requestEvent = $this->makeMainRequestEvent($request);

        (new CorrelationIdListener())($requestEvent);

        $stored = $request->attributes->get(CorrelationIdListener::ATTRIBUTE_KEY);
        $this->assertIsString($stored);
        $this->assertMatchesRegularExpression(self::UUID_V7_REGEX, $stored);
        $this->assertNotSame($bad, $stored);
    }

    public function testMalformedInboundHeaderWithEmbeddedNewlineIsRejected(): void
    {
        $bad = self::VALID_UUID_V7 . "\nX-Forwarded-For: evil";
        $request = Request::create('/api/anything', server: ['HTTP_X_CORRELATION_ID' => $bad]);
        $requestEvent = $this->makeMainRequestEvent($request);

        (new CorrelationIdListener())($requestEvent);

        $stored = $request->attributes->get(CorrelationIdListener::ATTRIBUTE_KEY);
        $this->assertIsString($stored);
        $this->assertMatchesRegularExpression(self::UUID_V7_REGEX, $stored);
        $this->assertNotSame($bad, $stored);
        $this->assertStringNotContainsString("\n", $stored, 'Stored value must not contain newlines — HTTP response-splitting defense (NFR11).');
    }

    public function testMalformedInboundHeaderWithLengthMismatchIsRejected(): void
    {
        // 35 chars — missing trailing nibble.
        $short = '0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1a2';
        $request = Request::create('/api/anything', server: ['HTTP_X_CORRELATION_ID' => $short]);
        $requestEvent = $this->makeMainRequestEvent($request);

        (new CorrelationIdListener())($requestEvent);

        $stored = $request->attributes->get(CorrelationIdListener::ATTRIBUTE_KEY);
        $this->assertIsString($stored);
        $this->assertMatchesRegularExpression(self::UUID_V7_REGEX, $stored);
        $this->assertNotSame($short, $stored);
    }

    public function testMalformedInboundHeaderWithLoneTrailingNewlineIsRejected(): void
    {
        // PHP's default `$` matches before a final `\n`; the listener uses `\A…\z` to forbid it.
        // Pin: a value of exactly `<valid-uuidv7>\n` must be rejected.
        $bad = self::VALID_UUID_V7 . "\n";
        $request = Request::create('/api/anything', server: ['HTTP_X_CORRELATION_ID' => $bad]);
        $requestEvent = $this->makeMainRequestEvent($request);

        (new CorrelationIdListener())($requestEvent);

        $stored = $request->attributes->get(CorrelationIdListener::ATTRIBUTE_KEY);
        $this->assertIsString($stored);
        $this->assertMatchesRegularExpression(self::UUID_V7_REGEX, $stored);
        $this->assertNotSame($bad, $stored);
        $this->assertStringNotContainsString("\n", $stored);
    }

    public function testMalformedInboundHeaderWithLeadingWhitespaceIsRejected(): void
    {
        $bad = ' ' . self::VALID_UUID_V7;
        $request = Request::create('/api/anything', server: ['HTTP_X_CORRELATION_ID' => $bad]);
        $requestEvent = $this->makeMainRequestEvent($request);

        (new CorrelationIdListener())($requestEvent);

        $stored = $request->attributes->get(CorrelationIdListener::ATTRIBUTE_KEY);
        $this->assertIsString($stored);
        $this->assertMatchesRegularExpression(self::UUID_V7_REGEX, $stored);
        $this->assertNotSame($bad, $stored);
    }

    public function testMalformedInboundHeaderWithTrailingTabIsRejected(): void
    {
        $bad = self::VALID_UUID_V7 . "\t";
        $request = Request::create('/api/anything', server: ['HTTP_X_CORRELATION_ID' => $bad]);
        $requestEvent = $this->makeMainRequestEvent($request);

        (new CorrelationIdListener())($requestEvent);

        $stored = $request->attributes->get(CorrelationIdListener::ATTRIBUTE_KEY);
        $this->assertIsString($stored);
        $this->assertMatchesRegularExpression(self::UUID_V7_REGEX, $stored);
        $this->assertNotSame($bad, $stored);
    }

    public function testMalformedInboundHeaderWithEmbeddedNulByteIsRejected(): void
    {
        $bad = self::VALID_UUID_V7 . "\0";
        $request = Request::create('/api/anything', server: ['HTTP_X_CORRELATION_ID' => $bad]);
        $requestEvent = $this->makeMainRequestEvent($request);

        (new CorrelationIdListener())($requestEvent);

        $stored = $request->attributes->get(CorrelationIdListener::ATTRIBUTE_KEY);
        $this->assertIsString($stored);
        $this->assertMatchesRegularExpression(self::UUID_V7_REGEX, $stored);
        $this->assertNotSame($bad, $stored);
        $this->assertStringNotContainsString("\0", $stored);
    }

    public function testMultipleInboundHeadersAreRejectedAndFreshIdIsMinted(): void
    {
        // HTTP allows duplicate header names; the listener must treat any non-singleton list
        // as malformed and mint fresh — defense-in-depth (NFR11). Even when both values are
        // individually well-formed, the ambiguity of "which one wins?" is rejected at the door.
        $request = Request::create('/api/anything');
        $request->headers->set(
            CorrelationIdListener::HEADER_NAME,
            [self::VALID_UUID_V7, '0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1a2d'],
        );
        $requestEvent = $this->makeMainRequestEvent($request);

        (new CorrelationIdListener())($requestEvent);

        $stored = $request->attributes->get(CorrelationIdListener::ATTRIBUTE_KEY);
        $this->assertIsString($stored);
        $this->assertMatchesRegularExpression(self::UUID_V7_REGEX, $stored);
        $this->assertNotSame(self::VALID_UUID_V7, $stored, 'Multi-value inbound must mint fresh, never echo a candidate.');
        $this->assertNotSame('0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1a2d', $stored);
    }

    public function testSubRequestIsIgnoredAndAttributeIsNotSet(): void
    {
        $request = Request::create('/api/anything');
        $requestEvent = new RequestEvent($this->makeKernel(), $request, HttpKernelInterface::SUB_REQUEST);

        (new CorrelationIdListener())($requestEvent);

        $this->assertFalse(
            $request->attributes->has(CorrelationIdListener::ATTRIBUTE_KEY),
            'Sub-requests must not mint their own correlation-id (FR40 / NFR16).',
        );
    }

    public function testEachInvocationOnFreshRequestMintsADistinctUuidV7(): void
    {
        $correlationIdListener = new CorrelationIdListener();

        $requestA = Request::create('/api/anything');
        $correlationIdListener($this->makeMainRequestEvent($requestA));

        $requestB = Request::create('/api/anything');
        $correlationIdListener($this->makeMainRequestEvent($requestB));

        $idA = $requestA->attributes->get(CorrelationIdListener::ATTRIBUTE_KEY);
        $idB = $requestB->attributes->get(CorrelationIdListener::ATTRIBUTE_KEY);

        $this->assertIsString($idA);
        $this->assertIsString($idB);
        $this->assertMatchesRegularExpression(self::UUID_V7_REGEX, $idA);
        $this->assertMatchesRegularExpression(self::UUID_V7_REGEX, $idB);
        $this->assertNotSame($idA, $idB, 'Listener must mint a distinct UUIDv7 per invocation — no caching, no static state (NFR3 / NFR16).');
    }

    public function testListenerProducesNoErrorWhenSymfonyUuidIsAvailable(): void
    {
        $request = Request::create('/api/anything');
        $requestEvent = $this->makeMainRequestEvent($request);

        (new CorrelationIdListener())($requestEvent);

        $this->assertTrue($request->attributes->has(CorrelationIdListener::ATTRIBUTE_KEY));
    }

    public function testResponseListenerPriorityIsPinnedAtClassConstantValue(): void
    {
        // Read via reflection so the assertion is not statically tautological — the test must
        // catch a future edit that changes the constant, even when static analysis can resolve
        // CorrelationIdListener::RESPONSE_PRIORITY at parse time.
        $priority = (new ReflectionClass(CorrelationIdListener::class))->getConstant('RESPONSE_PRIORITY');

        $this->assertSame(-1024, $priority, 'RESPONSE_PRIORITY must remain -1024 — Story 2.2 AC #4; bumping requires updating both the constant and this test.');
    }

    public function testResponseHeaderEchoesAttributeValueWhenAttributeIsValidUuidV7(): void
    {
        $request = Request::create('/api/anything');
        $request->attributes->set(CorrelationIdListener::ATTRIBUTE_KEY, self::VALID_UUID_V7);

        $response = new Response();
        $responseEvent = $this->makeMainResponseEvent($request, $response);

        (new CorrelationIdListener())->onResponse($responseEvent);

        $this->assertSame(self::VALID_UUID_V7, $response->headers->get(CorrelationIdListener::HEADER_NAME));
    }

    public function testResponseHeaderIsMintedFreshWhenAttributeMissing(): void
    {
        $request = Request::create('/api/anything');
        $response = new Response();
        $responseEvent = $this->makeMainResponseEvent($request, $response);

        (new CorrelationIdListener())->onResponse($responseEvent);

        $headerValue = $response->headers->get(CorrelationIdListener::HEADER_NAME);
        $this->assertIsString($headerValue);
        $this->assertMatchesRegularExpression(self::UUID_V7_REGEX, $headerValue);
    }

    public function testResponseHeaderIsMintedFreshWhenAttributeIsNotAString(): void
    {
        $request = Request::create('/api/anything');
        $request->attributes->set(CorrelationIdListener::ATTRIBUTE_KEY, 42);

        $response = new Response();
        $responseEvent = $this->makeMainResponseEvent($request, $response);

        (new CorrelationIdListener())->onResponse($responseEvent);

        $headerValue = $response->headers->get(CorrelationIdListener::HEADER_NAME);
        $this->assertIsString($headerValue);
        $this->assertMatchesRegularExpression(self::UUID_V7_REGEX, $headerValue);
        $this->assertNotSame('42', $headerValue);
    }

    public function testResponseHeaderIsMintedFreshWhenAttributeContainsUppercase(): void
    {
        $uppercase = '0190E9C2-7B5A-7D40-9C8F-2F9B5D3E1A2C';
        $request = Request::create('/api/anything');
        $request->attributes->set(CorrelationIdListener::ATTRIBUTE_KEY, $uppercase);

        $response = new Response();
        $responseEvent = $this->makeMainResponseEvent($request, $response);

        (new CorrelationIdListener())->onResponse($responseEvent);

        $headerValue = $response->headers->get(CorrelationIdListener::HEADER_NAME);
        $this->assertIsString($headerValue);
        $this->assertMatchesRegularExpression(self::UUID_V7_REGEX, $headerValue);
        $this->assertNotSame($uppercase, $headerValue, 'Uppercase attribute must be re-validated and replaced — NFR11 defense-in-depth.');
    }

    public function testResponseHeaderIsMintedFreshWhenAttributeContainsEmbeddedNewline(): void
    {
        $bad = self::VALID_UUID_V7 . "\nX-Forwarded-For: evil";
        $request = Request::create('/api/anything');
        $request->attributes->set(CorrelationIdListener::ATTRIBUTE_KEY, $bad);

        $response = new Response();
        $responseEvent = $this->makeMainResponseEvent($request, $response);

        (new CorrelationIdListener())->onResponse($responseEvent);

        $headerValue = $response->headers->get(CorrelationIdListener::HEADER_NAME);
        $this->assertIsString($headerValue);
        $this->assertMatchesRegularExpression(self::UUID_V7_REGEX, $headerValue);
        $this->assertStringNotContainsString("\n", $headerValue, 'Header value must not contain newlines — HTTP response-splitting defense (NFR11).');
    }

    public function testResponseHeaderIsMintedFreshWhenAttributeContainsLengthMismatch(): void
    {
        // 35 chars — missing trailing nibble.
        $short = '0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1a2';
        $request = Request::create('/api/anything');
        $request->attributes->set(CorrelationIdListener::ATTRIBUTE_KEY, $short);

        $response = new Response();
        $responseEvent = $this->makeMainResponseEvent($request, $response);

        (new CorrelationIdListener())->onResponse($responseEvent);

        $headerValue = $response->headers->get(CorrelationIdListener::HEADER_NAME);
        $this->assertIsString($headerValue);
        $this->assertMatchesRegularExpression(self::UUID_V7_REGEX, $headerValue);
        $this->assertNotSame($short, $headerValue);
    }

    public function testResponseHeaderOverwritesPreExistingHeaderValue(): void
    {
        $request = Request::create('/api/anything');
        $request->attributes->set(CorrelationIdListener::ATTRIBUTE_KEY, self::VALID_UUID_V7);

        $response = new Response();
        $response->headers->set(CorrelationIdListener::HEADER_NAME, 'junk-value-injected-elsewhere');

        $responseEvent = $this->makeMainResponseEvent($request, $response);

        (new CorrelationIdListener())->onResponse($responseEvent);

        $this->assertSame(
            self::VALID_UUID_V7,
            $response->headers->get(CorrelationIdListener::HEADER_NAME),
            'Pre-existing X-Correlation-Id header must be overwritten by the request-attribute-derived value.',
        );
        $this->assertCount(
            1,
            $response->headers->all(CorrelationIdListener::HEADER_NAME),
            'Listener must emit exactly one X-Correlation-Id header — duplicate emission via add()/set(replace:false) is a regression.',
        );
    }

    public function testResponseHeaderIsMintedFreshAndOverwritesJunkWhenAttributeMissing(): void
    {
        $junk = 'junk-value-injected-elsewhere';
        $request = Request::create('/api/anything');

        $response = new Response();
        $response->headers->set(CorrelationIdListener::HEADER_NAME, $junk);

        $responseEvent = $this->makeMainResponseEvent($request, $response);

        (new CorrelationIdListener())->onResponse($responseEvent);

        $headerValue = $response->headers->get(CorrelationIdListener::HEADER_NAME);
        $this->assertIsString($headerValue);
        $this->assertMatchesRegularExpression(self::UUID_V7_REGEX, $headerValue);
        $this->assertNotSame(
            $junk,
            $headerValue,
            'Mint-on-miss path must overwrite a pre-existing junk header, not preserve it (NFR14).',
        );
        $this->assertCount(
            1,
            $response->headers->all(CorrelationIdListener::HEADER_NAME),
            'Mint-on-miss must still emit exactly one X-Correlation-Id header.',
        );
    }

    public function testSubRequestResponseDoesNotSetXCorrelationIdHeader(): void
    {
        $request = Request::create('/api/anything');
        $request->attributes->set(CorrelationIdListener::ATTRIBUTE_KEY, self::VALID_UUID_V7);

        $response = new Response();
        $responseEvent = new ResponseEvent($this->makeKernel(), $request, HttpKernelInterface::SUB_REQUEST, $response);

        (new CorrelationIdListener())->onResponse($responseEvent);

        $this->assertFalse(
            $response->headers->has(CorrelationIdListener::HEADER_NAME),
            'Sub-responses must not carry X-Correlation-Id — only main responses propagate to the wire (FR40 / NFR16).',
        );
    }

    public function testEachInvocationOnFreshRequestEmitsADistinctMintedHeaderWhenAttributeMissing(): void
    {
        $correlationIdListener = new CorrelationIdListener();

        $requestA = Request::create('/api/anything');
        $responseA = new Response();
        $correlationIdListener->onResponse($this->makeMainResponseEvent($requestA, $responseA));

        $requestB = Request::create('/api/anything');
        $responseB = new Response();
        $correlationIdListener->onResponse($this->makeMainResponseEvent($requestB, $responseB));

        $headerA = $responseA->headers->get(CorrelationIdListener::HEADER_NAME);
        $headerB = $responseB->headers->get(CorrelationIdListener::HEADER_NAME);

        $this->assertIsString($headerA);
        $this->assertIsString($headerB);
        $this->assertMatchesRegularExpression(self::UUID_V7_REGEX, $headerA);
        $this->assertMatchesRegularExpression(self::UUID_V7_REGEX, $headerB);
        $this->assertNotSame($headerA, $headerB, 'Listener must mint a distinct UUIDv7 per response when attribute is missing — no caching, no static state (NFR3 / NFR16).');
    }

    private function makeMainRequestEvent(Request $request): RequestEvent
    {
        return new RequestEvent($this->makeKernel(), $request, HttpKernelInterface::MAIN_REQUEST);
    }

    private function makeMainResponseEvent(Request $request, Response $response): ResponseEvent
    {
        return new ResponseEvent($this->makeKernel(), $request, HttpKernelInterface::MAIN_REQUEST, $response);
    }

    private function makeKernel(): HttpKernelInterface
    {
        return new class implements HttpKernelInterface {
            public function handle(Request $request, int $type = HttpKernelInterface::MAIN_REQUEST, bool $catch = true): Response
            {
                throw new LogicException('Test kernel: handle() must not be called by the listener.');
            }
        };
    }
}
