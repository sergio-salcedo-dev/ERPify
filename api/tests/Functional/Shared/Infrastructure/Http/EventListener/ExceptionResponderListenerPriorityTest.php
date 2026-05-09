<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Shared\Infrastructure\Http\EventListener;

use Closure;
use Erpify\Shared\Infrastructure\Http\EventListener\ExceptionResponder;
use Nelmio\CorsBundle\EventListener\CorsListener;
use PHPUnit\Framework\Attributes\CoversNothing;
use ReflectionClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Pins the {@see ExceptionResponder} listener priority
 * relative to the rest of the kernel.exception chain, and pins NelmioCorsBundle's
 * kernel.response listener priority so CORS headers continue to attach AFTER the Problem
 * Details body has been built. The two pins together guarantee that a cross-origin request
 * which fails inside the kernel still receives both `Access-Control-Allow-Origin` and a
 * conforming `application/problem+json` body.
 *
 * The test fails with a clear diagnostic if either side drifts:
 *   - Editing {@see ExceptionResponder::PRIORITY} flips
 *     `testRegisteredListenerPriorityMatchesPublicConstant`.
 *   - Upgrading NelmioCorsBundle to a version that re-prioritises its response listener flips
 *     `testNelmioCorsResponseListenerPriorityRemainsAtExpectedBaseline`.
 *
 * Lives in `Functional/` (matches existing convention; the project has no `Integration/`
 * tier) but DOES boot the Symfony kernel via {@see KernelTestCase} / {@see WebTestCase} —
 * the tests below are kernel-level integration tests in everything but folder name.
 *
 * @internal
 */
#[CoversNothing]
final class ExceptionResponderListenerPriorityTest extends WebTestCase
{
    /**
     * Pinned baseline copied verbatim from
     * `vendor/nelmio/cors-bundle/Resources/config/services.php`. The
     * `nelmio_cors.cors_listener` service registers `onKernelResponse` at priority `0` on
     * `kernel.response`. If a future bundle release re-prioritises that listener, this
     * constant must be bumped IN LOCKSTEP with a re-evaluation of
     * {@see ExceptionResponder::PRIORITY} so the documented invariant — Problem Details
     * body produced before CORS headers attach — still holds.
     */
    private const int EXPECTED_NELMIO_RESPONSE_PRIORITY = 0;

    public function testListenerPriorityIsDeclaredAsPublicClassConstant(): void
    {
        $reflectionClass = new ReflectionClass(ExceptionResponder::class);

        $this->assertTrue(
            $reflectionClass->hasConstant('PRIORITY'),
            'ExceptionResponder must declare a `PRIORITY` class constant.',
        );

        $constantReflection = $reflectionClass->getReflectionConstant('PRIORITY');
        $this->assertNotFalse($constantReflection);
        $this->assertTrue(
            $constantReflection->isPublic(),
            'ExceptionResponder::PRIORITY must be `public` so external regression tests can pin it.',
        );

        $this->assertSame(
            16,
            $reflectionClass->getConstant('PRIORITY'),
            'ExceptionResponder::PRIORITY must remain 16. Bumping requires '
            . 'updating both the constant and this test, plus re-checking that the value sits '
            . 'ABOVE Symfony HttpKernel ExceptionListener (-128) while leaving headroom for any '
            . 'future per-context carve-out listener at a higher positive priority.',
        );
    }

    public function testAsEventListenerAttributeReferencesPublicConstantViaSelfPriority(): void
    {
        $reflectionClass = new ReflectionClass(ExceptionResponder::class);
        $attributes = $reflectionClass->getAttributes(AsEventListener::class);

        $this->assertNotEmpty(
            $attributes,
            'ExceptionResponder must carry the `#[AsEventListener]` attribute.',
        );

        $asEventListener = $attributes[0]->newInstance();
        $this->assertSame(KernelEvents::EXCEPTION, $asEventListener->event);
        $this->assertSame(
            ExceptionResponder::PRIORITY,
            $asEventListener->priority,
            'The `#[AsEventListener]` attribute must read its priority from `self::PRIORITY` so '
            . 'the constant is the single source of truth.',
        );
    }

    public function testRegisteredListenerPriorityMatchesPublicConstant(): void
    {
        self::bootKernel();
        $dispatcher = self::getContainer()->get('event_dispatcher');
        $this->assertInstanceOf(EventDispatcherInterface::class, $dispatcher);

        $priority = $this->priorityOf($dispatcher, KernelEvents::EXCEPTION, ExceptionResponder::class);

        $this->assertNotNull(
            $priority,
            'ExceptionResponder must be registered on `kernel.exception`.',
        );
        $this->assertSame(
            ExceptionResponder::PRIORITY,
            $priority,
            'EventDispatcher must report ExceptionResponder at exactly `ExceptionResponder::PRIORITY`. '
            . 'A drift here means the `#[AsEventListener]` attribute or services.yaml has decoupled '
            . 'from the constant — fix at the source, not at this test.',
        );
    }

    public function testNelmioCorsResponseListenerPriorityRemainsAtExpectedBaseline(): void
    {
        self::bootKernel();
        $dispatcher = self::getContainer()->get('event_dispatcher');
        $this->assertInstanceOf(EventDispatcherInterface::class, $dispatcher);

        $priority = $this->priorityOf($dispatcher, KernelEvents::RESPONSE, CorsListener::class);

        $this->assertNotNull(
            $priority,
            'NelmioCorsBundle\EventListener\CorsListener must be registered on `kernel.response`. '
            . 'If the bundle was removed or its service ID changed, update '
            . 'ExceptionResponderListenerPriorityTest accordingly.',
        );
        $this->assertSame(
            self::EXPECTED_NELMIO_RESPONSE_PRIORITY,
            $priority,
            'NelmioCorsBundle response listener priority drifted from expected value '
            . self::EXPECTED_NELMIO_RESPONSE_PRIORITY . '. '
            . 'Update ExceptionResponder::PRIORITY relative to the new value — the invariant is '
            . 'that the Problem Details body is built on `kernel.exception` BEFORE the CORS '
            . 'response listener attaches `Access-Control-Allow-Origin`.',
        );
    }

    public function testCrossOriginGetWithOriginHeaderReturnsBothCorsAndProblemDetails(): void
    {
        $kernelBrowser = self::createClient();
        $kernelBrowser->catchExceptions(true);
        $kernelBrowser->request(
            Request::METHOD_GET,
            '/api/test/_throw-not-found',
            server: [
                'HTTP_ORIGIN' => 'http://localhost:3000',
            ],
        );

        $response = $kernelBrowser->getResponse();

        $this->assertSame(
            Response::HTTP_NOT_FOUND,
            $response->getStatusCode(),
            (string) $response->getContent(),
        );
        $this->assertSame(
            'application/problem+json',
            $response->headers->get('Content-Type'),
            'Cross-origin failing request must still carry the Problem Details media type.',
        );
        $this->assertSame(
            'http://localhost:3000',
            $response->headers->get('Access-Control-Allow-Origin'),
            'NelmioCorsBundle must echo the allowed Origin back on the Problem Details error '
            . 'response. Failure here means the CORS response listener no '
            . 'longer fires after ExceptionResponder set the response on kernel.exception; '
            . 'inspect the listener priorities pinned in this test.',
        );

        /** @var array<string, mixed> $body */
        $body = (array) \json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('not-found', $body['type'] ?? null);
        $this->assertSame(404, $body['status'] ?? null);
    }

    public function testCrossOriginPreflightOptionsWorksOnApiTestRoute(): void
    {
        $kernelBrowser = self::createClient();
        $kernelBrowser->catchExceptions(true);
        $kernelBrowser->request(
            Request::METHOD_OPTIONS,
            '/api/test/_throw-not-found',
            server: [
                'HTTP_ORIGIN' => 'http://localhost:3000',
                'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
            ],
        );

        $response = $kernelBrowser->getResponse();

        $this->assertContains(
            $response->getStatusCode(),
            [Response::HTTP_OK, Response::HTTP_NO_CONTENT],
            'Preflight OPTIONS must short-circuit with 200/204 — NelmioCorsBundle owns this path.',
        );
        $this->assertSame(
            'http://localhost:3000',
            $response->headers->get('Access-Control-Allow-Origin'),
            'Preflight response must echo the Origin back.',
        );
        $this->assertNotNull(
            $response->headers->get('Access-Control-Allow-Methods'),
            'Preflight response must advertise allowed methods.',
        );
    }

    private function priorityOf(
        EventDispatcherInterface $dispatcher,
        string $event,
        string $listenerClass,
    ): ?int {
        /** @var list<callable> $listeners */
        $listeners = $dispatcher->getListeners($event);

        foreach ($listeners as $listener) {
            $candidate = null;

            if (\is_array($listener) && \is_object($listener[0])) {
                $candidate = $listener[0];
            } elseif (\is_object($listener) && !$listener instanceof Closure) {
                $candidate = $listener;
            }

            if (null === $candidate) {
                continue;
            }

            if (!$candidate instanceof $listenerClass) {
                continue;
            }

            $priority = $dispatcher->getListenerPriority($event, $listener);

            if (null === $priority) {
                continue;
            }

            return $priority;
        }

        return null;
    }
}
