<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Infrastructure\Http\EventListener;

use Erpify\Shared\Application\Problem\ProblemDetailsFactory;
use Erpify\Shared\Domain\Exception\DomainException;
use Erpify\Shared\Domain\Exception\NotFound;
use Erpify\Shared\Infrastructure\Http\EventListener\ExceptionResponder;
use Erpify\Shared\Infrastructure\Http\ProblemDetailsResponder;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Throwable;

/**
 * @internal
 */
#[CoversClass(ExceptionResponder::class)]
final class ExceptionResponderTest extends TestCase
{
    private const string UUID_V7_REGEX = '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    public function testReturnsEarlyWhenResponseAlreadySetByEarlierListener(): void
    {
        $exceptionResponder = $this->makeListener();
        $exceptionEvent = $this->makeEvent('/api/v1/anything', new RuntimeException('boom'));
        $exceptionEvent->setResponse(new Response('preset', Response::HTTP_I_AM_A_TEAPOT));

        $exceptionResponder($exceptionEvent);

        $response = $exceptionEvent->getResponse();
        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(Response::HTTP_I_AM_A_TEAPOT, $response->getStatusCode(), (string) $response->getContent());
        $this->assertSame('preset', $response->getContent());
    }

    public function testReturnsEarlyForNonApiPath(): void
    {
        $exceptionResponder = $this->makeListener();
        $exceptionEvent = $this->makeEvent('/_profiler/something', new RuntimeException('boom'));

        $exceptionResponder($exceptionEvent);

        $this->assertFalse($exceptionEvent->hasResponse(), 'Listener must not act on paths outside /api/.');
    }

    public function testDomainExceptionMappedToProblemDetailsResponse(): void
    {
        $exceptionResponder = $this->makeListener();
        $exception = new class ('', 'Bank not found', ['bank_id' => '01JABC']) extends DomainException implements NotFound {
        };
        $exceptionEvent = $this->makeEvent('/api/v1/anything', $exception);

        $exceptionResponder($exceptionEvent);

        $response = $exceptionEvent->getResponse();
        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode(), (string) $response->getContent());
        $this->assertSame('application/problem+json', $response->headers->get('Content-Type'));
        $cacheControl = $response->headers->get('Cache-Control');
        $this->assertNotNull($cacheControl);
        $this->assertStringContainsString('no-store', $cacheControl);

        $body = $this->decodeBody($response->getContent());

        $this->assertBodyEquals('not-found', $body, 'type');
        $this->assertBodyEquals('Bank not found', $body, 'title');
        $this->assertBodyEquals(404, $body, 'status');
        $this->assertBodyEquals('01JABC', $body, 'bank_id');
        $this->assertBodyMatchesRegex(self::UUID_V7_REGEX, $body, 'correlation-id');
        $this->assertBodyMatchesRegex(self::UUID_V7_REGEX, $body, 'instance');
    }

    public function testCorrelationIdIsRespectedWhenAlreadyOnRequestAttributes(): void
    {
        $exceptionResponder = $this->makeListener();
        $exception = new class ('', 'x') extends DomainException implements NotFound {
        };
        $exceptionEvent = $this->makeEvent('/api/v1/anything', $exception);
        $exceptionEvent->getRequest()->attributes->set('correlation-id', 'preset-correlation');

        $exceptionResponder($exceptionEvent);

        $response = $exceptionEvent->getResponse();
        $this->assertInstanceOf(Response::class, $response);

        $body = $this->decodeBody($response->getContent());
        $this->assertBodyEquals('preset-correlation', $body, 'correlation-id');
    }

    public function testCorrelationIdMintedAsUuidV7WhenAttributeMissing(): void
    {
        $exceptionResponder = $this->makeListener();
        $exception = new class ('', 'x') extends DomainException implements NotFound {
        };
        $exceptionEvent = $this->makeEvent('/api/v1/anything', $exception);

        $exceptionResponder($exceptionEvent);

        $response = $exceptionEvent->getResponse();
        $this->assertInstanceOf(Response::class, $response);

        $body = $this->decodeBody($response->getContent());
        $this->assertBodyMatchesRegex(self::UUID_V7_REGEX, $body, 'correlation-id');
    }

    public function testCorrelationIdMintedAsUuidV7WhenAttributeIsNonString(): void
    {
        $exceptionResponder = $this->makeListener();
        $exception = new class ('', 'x') extends DomainException implements NotFound {
        };
        $exceptionEvent = $this->makeEvent('/api/v1/anything', $exception);
        $exceptionEvent->getRequest()->attributes->set('correlation-id', 12345);

        $exceptionResponder($exceptionEvent);

        $response = $exceptionEvent->getResponse();
        $this->assertInstanceOf(Response::class, $response);

        $body = $this->decodeBody($response->getContent());
        $this->assertBodyMatchesRegex(self::UUID_V7_REGEX, $body, 'correlation-id');
    }

    public function testRuntimeExceptionMapsToFiveHundredUnhandledException(): void
    {
        $exceptionResponder = $this->makeListener();
        $exceptionEvent = $this->makeEvent('/api/v1/anything', new RuntimeException('boom'));

        $exceptionResponder($exceptionEvent);

        $response = $exceptionEvent->getResponse();
        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(Response::HTTP_INTERNAL_SERVER_ERROR, $response->getStatusCode(), (string) $response->getContent());

        $body = $this->decodeBody($response->getContent());
        $this->assertBodyEquals('unhandled-exception', $body, 'type');
        $this->assertBodyEquals('boom', $body, 'title');
    }

    public function testInstanceIsFreshUuidV7PerInvocation(): void
    {
        $exceptionResponder = $this->makeListener();

        $exceptionEvent = $this->makeEvent('/api/v1/x', new RuntimeException('a'));
        $exceptionResponder($exceptionEvent);
        $bodyA = $this->decodeBody($exceptionEvent->getResponse()?->getContent());

        $eventB = $this->makeEvent('/api/v1/x', new RuntimeException('b'));
        $exceptionResponder($eventB);
        $bodyB = $this->decodeBody($eventB->getResponse()?->getContent());

        $this->assertArrayHasKey('instance', $bodyA);
        $this->assertArrayHasKey('instance', $bodyB);
        $this->assertNotSame($bodyA['instance'], $bodyB['instance'], 'Listener must mint a fresh `instance` UUIDv7 per error occurrence.');
        $this->assertBodyMatchesRegex(self::UUID_V7_REGEX, $bodyA, 'instance');
        $this->assertBodyMatchesRegex(self::UUID_V7_REGEX, $bodyB, 'instance');
    }

    public function testListenerRegistrationAttributeIsKernelExceptionEvent(): void
    {
        $reflectionClass = new ReflectionClass(ExceptionResponder::class);
        $attributes = $reflectionClass->getAttributes(\Symfony\Component\EventDispatcher\Attribute\AsEventListener::class);

        $this->assertCount(1, $attributes, 'ExceptionResponder must declare exactly one #[AsEventListener] attribute.');

        $arguments = $attributes[0]->getArguments();
        $this->assertSame('kernel.exception', $arguments['event'] ?? $arguments[0] ?? null);
        $this->assertArrayNotHasKey('priority', $arguments, 'Story 4.1 (FR42, FR43) owns the priority constant — Story 1.4 must not declare one.');
    }

    public function testSourceFileContainsNoBannedImports(): void
    {
        $sourcePath = \dirname(__DIR__, 6) . '/src/Shared/Infrastructure/Http/EventListener/ExceptionResponder.php';
        $this->assertFileExists($sourcePath);

        $contents = \file_get_contents($sourcePath);
        $this->assertNotFalse($contents);

        $banned = [
            'use Symfony\Component\HttpKernel\Exception\\',
            'use Doctrine\\',
            'use Symfony\Component\Messenger\\',
            'use Psr\Log\\',
            'use App\\',
        ];

        foreach ($banned as $needle) {
            $this->assertStringNotContainsString(
                $needle,
                $contents,
                \sprintf('ExceptionResponder.php must not contain "%s" — Story 1.4 AC #14.', $needle),
            );
        }
    }

    private function makeListener(): ExceptionResponder
    {
        return new ExceptionResponder(
            new ProblemDetailsFactory(),
            new ProblemDetailsResponder(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeBody(false|string|null $content): array
    {
        $this->assertIsString($content);
        $decoded = \json_decode($content, true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function assertBodyEquals(mixed $expected, array $body, string $key): void
    {
        $this->assertArrayHasKey($key, $body);
        $this->assertSame($expected, $body[$key]);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function assertBodyMatchesRegex(string $regex, array $body, string $key): void
    {
        $this->assertArrayHasKey($key, $body);
        $value = $body[$key];
        $this->assertIsString($value);
        $this->assertMatchesRegularExpression($regex, $value);
    }

    private function makeEvent(string $path, Throwable $exception): ExceptionEvent
    {
        $request = Request::create($path);
        $kernel = new class implements HttpKernelInterface {
            public function handle(Request $request, int $type = HttpKernelInterface::MAIN_REQUEST, bool $catch = true): Response
            {
                throw new LogicException('Test kernel: handle() must not be called by the listener.');
            }
        };

        return new ExceptionEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $exception);
    }
}
