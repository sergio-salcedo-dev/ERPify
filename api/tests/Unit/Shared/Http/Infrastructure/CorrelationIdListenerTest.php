<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Http\Infrastructure;

use Erpify\Shared\Http\Infrastructure\CorrelationIdListener;
use LogicException;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
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
 *
 * @SuppressWarnings("PHPMD.TooManyMethods")
 * @SuppressWarnings("PHPMD.TooManyPublicMethods")
 * @SuppressWarnings("PHPMD.ExcessiveClassLength")
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
        $this->assertNotInstanceOf(
            ReflectionMethod::class,
            $reflectionClass->getConstructor(),
            'CorrelationIdListener must have no constructor (worker-mode safe).',
        );
    }

    public function testListenerPriorityIsPinnedAtClassConstantValue(): void
    {
        // Read via reflection so the assertion is not statically tautological — the test must
        // catch a future edit that changes the constant, even when static analysis can resolve
        // CorrelationIdListener::PRIORITY at parse time.
        $priority = (new ReflectionClass(CorrelationIdListener::class))->getConstant('PRIORITY');

        $this->assertSame(
            1024,
            $priority,
            'Priority must remain 1024; bumping requires updating both the constant and this test.',
        );
    }

    public function testAttributeKeyConstantValueIsExactlyUnderscoreCorrelationUnderscoreId(): void
    {
        $attributeKey = (new ReflectionClass(CorrelationIdListener::class))->getConstant('ATTRIBUTE_KEY');

        $this->assertSame('_correlation_id', $attributeKey, 'ATTRIBUTE_KEY must be `_correlation_id`.');
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

    public function testCanonicalInboundHeaderIsIgnoredAndAFreshUuidV7IsMinted(): void
    {
        $request = Request::create('/api/anything', server: ['HTTP_X_CORRELATION_ID' => self::VALID_UUID_V7]);
        $requestEvent = $this->makeMainRequestEvent($request);

        (new CorrelationIdListener())($requestEvent);

        $stored = $request->attributes->get(CorrelationIdListener::ATTRIBUTE_KEY);
        $this->assertIsString($stored);
        $this->assertMatchesRegularExpression(self::UUID_V7_REGEX, $stored);
        $this->assertNotSame(
            self::VALID_UUID_V7,
            $stored,
            'The canonical value is the one an attacker sends — the malformed shapes never were the risk. '
            . 'Adopting it hands the caller the column the audit trail is grouped by, and no check applied '
            . 'to one request can tell a reused id from a fresh one.',
        );
    }

    /**
     * The hostile shapes are kept as data rather than deleted along with the branch that used to tell
     * them apart: each was measured once against a listener that had to, and the property they pin now is
     * the stronger one — nothing is read, so nothing reaches the attribute, the canonical value included.
     *
     * @param string|list<string> $inbound
     */
    #[DataProvider('provideNoInboundHeaderShapeReachesTheCorrelationAttributeCases')]
    public function testNoInboundHeaderShapeReachesTheCorrelationAttribute(array|string $inbound): void
    {
        $request = \is_array($inbound)
            ? Request::create('/api/anything')
            : Request::create('/api/anything', server: ['HTTP_X_CORRELATION_ID' => $inbound]);

        if (\is_array($inbound)) {
            $request->headers->set(CorrelationIdListener::HEADER_NAME, $inbound);
        }

        (new CorrelationIdListener())($this->makeMainRequestEvent($request));

        $stored = $request->attributes->get(CorrelationIdListener::ATTRIBUTE_KEY);
        $this->assertIsString($stored);
        $this->assertMatchesRegularExpression(self::UUID_V7_REGEX, $stored);

        foreach ((array) $inbound as $candidate) {
            $this->assertNotSame($candidate, $stored, 'An inbound value reached the correlation attribute.');
        }
    }

    /**
     * @return iterable<string, array{0: string|list<string>}>
     */
    public static function provideNoInboundHeaderShapeReachesTheCorrelationAttributeCases(): iterable
    {
        yield 'canonical lowercase UUIDv7' => [self::VALID_UUID_V7];
        yield 'uppercase' => ['0190E9C2-7B5A-7D40-9C8F-2F9B5D3E1A2C'];
        yield 'empty string' => [''];
        // UUIDv4: version-nibble `4`, not `7`.
        yield 'wrong version bits' => ['0190e9c2-7b5a-4d40-9c8f-2f9b5d3e1a2c'];
        // Variant-nibble `7` instead of `[89ab]`.
        yield 'wrong variant bits' => ['0190e9c2-7b5a-7d40-7c8f-2f9b5d3e1a2c'];
        yield 'extra garbage' => [self::VALID_UUID_V7 . '<script>alert(1)</script>'];
        yield 'embedded newline' => [self::VALID_UUID_V7 . "\nX-Forwarded-For: evil"];
        // 35 chars — missing trailing nibble.
        yield 'length mismatch' => ['0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1a2'];
        yield 'lone trailing newline' => [self::VALID_UUID_V7 . "\n"];
        yield 'leading whitespace' => [' ' . self::VALID_UUID_V7];
        yield 'trailing tab' => [self::VALID_UUID_V7 . "\t"];
        yield 'embedded NUL byte' => [self::VALID_UUID_V7 . "\0"];
        // HTTP allows duplicate header names; both values here are individually well-formed.
        yield 'multiple headers' => [[self::VALID_UUID_V7, '0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1a2d']];
    }

    public function testSubRequestIsIgnoredAndAttributeIsNotSet(): void
    {
        $request = Request::create('/api/anything');
        $requestEvent = new RequestEvent($this->makeKernel(), $request, HttpKernelInterface::SUB_REQUEST);

        (new CorrelationIdListener())($requestEvent);

        $this->assertFalse(
            $request->attributes->has(CorrelationIdListener::ATTRIBUTE_KEY),
            'Sub-requests must not mint their own correlation-id.',
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
        $this->assertNotSame(
            $idA,
            $idB,
            'Listener must mint a distinct UUIDv7 per invocation — no caching, no static state.',
        );
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

        $this->assertSame(
            -1024,
            $priority,
            'RESPONSE_PRIORITY must remain -1024; bumping requires updating both the constant and this test.',
        );
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
        $this->assertNotSame(
            $uppercase,
            $headerValue,
            'Uppercase attribute must be re-validated and replaced — defense-in-depth.',
        );
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
        $this->assertStringNotContainsString(
            "\n",
            $headerValue,
            'Header value must not contain newlines — HTTP response-splitting defense.',
        );
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
            'Listener must emit exactly one X-Correlation-Id header — '
            . 'duplicate emission via add()/set(replace:false) is a regression.',
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
            'Mint-on-miss path must overwrite a pre-existing junk header, not preserve it.',
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
            'Sub-responses must not carry X-Correlation-Id — only main responses propagate to the wire.',
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
        $this->assertNotSame(
            $headerA,
            $headerB,
            'Listener must mint a distinct UUIDv7 per response when attribute is missing — '
            . 'no caching, no static state.',
        );
    }

    private function makeMainRequestEvent(Request $request): RequestEvent
    {
        return new RequestEvent($this->makeKernel(), $request, HttpKernelInterface::MAIN_REQUEST);
    }

    private function makeMainResponseEvent(Request $request, Response $response): ResponseEvent
    {
        return new ResponseEvent($this->makeKernel(), $request, HttpKernelInterface::MAIN_REQUEST, $response);
    }

    /**
     * @SuppressWarnings("PHPMD.UnusedFormalParameter")
     * @SuppressWarnings("PHPMD.BooleanArgumentFlag")
     */
    private function makeKernel(): HttpKernelInterface
    {
        return new class implements HttpKernelInterface {
            #[Override]
            public function handle(
                Request $request,
                int $type = HttpKernelInterface::MAIN_REQUEST,
                bool $catch = true,
            ): Response {
                throw new LogicException('Test kernel: handle() must not be called by the listener.');
            }
        };
    }
}
