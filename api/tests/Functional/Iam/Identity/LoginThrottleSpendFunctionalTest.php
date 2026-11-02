<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Iam\Identity;

use Erpify\Iam\Identity\Infrastructure\Security\ProblemDetailsAuthenticationFailureHandler;
use Erpify\Iam\Identity\Infrastructure\Security\UniformLoginFailureListener;
use Erpify\Shared\Uuid\Domain\Uuid;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use ReflectionProperty;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\RateLimiter\PeekableRequestRateLimiterInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;
use Symfony\Component\Security\Http\EventListener\LoginThrottlingListener;

/**
 * A failed login spends the `login_throttling` budget, read through the firewall's real wiring.
 *
 * That budget is peeked before the credentials are checked and spent only on `LoginFailureEvent`, which the
 * authenticator dispatches after the failure handler returns — so a failure path that throws before then leaves
 * the throttle declared and inert, with the 401 still uniform and every other test green. The spy sits between
 * the throttle and {@see UniformLoginFailureListener} and peeks the budget while the request is still live: the
 * test pool is an array cache the services resetter empties on `kernel.terminate`, so nothing after the response
 * could observe it.
 *
 * The limiter is read off the registered {@see LoginThrottlingListener} because the bundle registers it as a
 * private service with a single consumer, which the container inlines; a vendor rename of that property fails
 * this test loudly rather than passing it.
 *
 * @internal
 */
#[CoversClass(ProblemDetailsAuthenticationFailureHandler::class)]
#[CoversClass(UniformLoginFailureListener::class)]
final class LoginThrottleSpendFunctionalTest extends WebTestCase
{
    private const string LOGIN_PATH = '/api/v1/backoffice/login';

    private const string ORIGIN = 'http://localhost';

    private const string FIREWALL_DISPATCHER = 'security.event_dispatcher.main';

    private const int SPY_PRIORITY = -512;

    private KernelBrowser $client;

    #[Override]
    protected function setUp(): void
    {
        $this->client = self::createClient();
        // The spy is registered on this container, so the request must run on it rather than on a rebooted one.
        $this->client->disableReboot();
    }

    public function testAFailedLoginSpendsOneTokenOfTheThrottleAndStillAnswersTheUniform401(): void
    {
        $dispatcher = self::getContainer()->get(self::FIREWALL_DISPATCHER);
        $this->assertInstanceOf(EventDispatcherInterface::class, $dispatcher);

        $limiter = $this->loginLimiter($dispatcher);
        $this->assertGreaterThan(self::SPY_PRIORITY, $this->throttlePriority($dispatcher));
        $this->assertLessThan(self::SPY_PRIORITY, UniformLoginFailureListener::PRIORITY);

        $budgets = new class {
            /** @var list<array{remaining: int, limit: int}> */
            public array $seen = [];
        };
        $dispatcher->addListener(
            LoginFailureEvent::class,
            static function (LoginFailureEvent $event) use ($limiter, $budgets): void {
                $budget = $limiter->peek($event->getRequest());
                $budgets->seen[] = ['remaining' => $budget->getRemainingTokens(), 'limit' => $budget->getLimit()];
            },
            self::SPY_PRIORITY,
        );

        $this->client->request(
            Request::METHOD_POST,
            self::LOGIN_PATH,
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_ORIGIN' => self::ORIGIN,
            ],
            content: (string) \json_encode([
                'email' => 'throttle-' . Uuid::generate() . '@erpify.test',
                'password' => 'not-a-registered-password',
            ]),
        );

        $response = $this->client->getResponse();
        $raw = (string) $response->getContent();
        $this->assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode(), $raw);
        $this->assertStringContainsString('application/problem+json', (string) $response->headers->get('Content-Type'));

        $body = \json_decode($raw, true);
        $this->assertIsArray($body);
        $this->assertSame('unauthenticated', $body['type'] ?? null);
        $this->assertSame(UniformLoginFailureListener::GENERIC_FAILURE_MESSAGE, $body['title'] ?? null);

        $this->assertCount(1, $budgets->seen, 'LoginFailureEvent never reached a listener below the throttle.');
        $spent = $budgets->seen[0] ?? self::fail('LoginFailureEvent never reached a listener below the throttle.');
        $this->assertSame($spent['limit'] - 1, $spent['remaining']);
    }

    private function loginLimiter(EventDispatcherInterface $dispatcher): PeekableRequestRateLimiterInterface
    {
        $throttle = $this->throttleListener($dispatcher);
        $limiter = (new ReflectionProperty(LoginThrottlingListener::class, 'limiter'))->getValue($throttle);
        $this->assertInstanceOf(PeekableRequestRateLimiterInterface::class, $limiter);

        return $limiter;
    }

    private function throttlePriority(EventDispatcherInterface $dispatcher): int
    {
        $priority = $dispatcher->getListenerPriority(
            LoginFailureEvent::class,
            [$this->throttleListener($dispatcher), 'onFailedLogin'],
        );
        $this->assertIsInt($priority, 'login_throttling is not listening to LoginFailureEvent on the main firewall.');

        return $priority;
    }

    private function throttleListener(EventDispatcherInterface $dispatcher): LoginThrottlingListener
    {
        foreach ($dispatcher->getListeners(LoginFailureEvent::class) as $listener) {
            if (\is_array($listener) && $listener[0] instanceof LoginThrottlingListener) {
                return $listener[0];
            }
        }

        self::fail('login_throttling is not listening to LoginFailureEvent on the main firewall.');
    }
}
