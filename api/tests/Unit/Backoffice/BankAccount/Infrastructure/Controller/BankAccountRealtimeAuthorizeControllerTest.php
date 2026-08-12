<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Backoffice\BankAccount\Infrastructure\Controller;

use Erpify\Backoffice\BankAccount\Domain\MercureBankAccountTopic;
use Erpify\Backoffice\BankAccount\Infrastructure\Controller\BankAccountRealtimeAuthorizeController;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mercure\Authorization;
use Symfony\Component\Mercure\Hub;
use Symfony\Component\Mercure\HubRegistry;
use Symfony\Component\Mercure\Jwt\LcobucciFactory;
use Symfony\Component\Mercure\Jwt\StaticTokenProvider;

/**
 * The endpoint mints the Mercure subscriber cookie and returns no body. The cookie's signed JWT is
 * decoded here to prove it authorizes exactly the three back-office bank-account topics (global
 * collection + per-bank template + per-account template) — the contract the PWA EventSource relies on.
 *
 * @internal
 */
#[CoversClass(BankAccountRealtimeAuthorizeController::class)]
final class BankAccountRealtimeAuthorizeControllerTest extends TestCase
{
    /** Same host as the default `Request::create` (`localhost`) so the cookie domain resolves cleanly. */
    private const string HUB_URL = 'https://localhost/.well-known/mercure';

    /** HS256 needs a >=256-bit key; this fixed test secret is not a real credential. */
    private const string JWT_SECRET = 'test-secret-not-a-real-credential-0123456789abcdef';

    public function testReturnsNoContentWithAnHttpOnlyMercureAuthorizationCookieAndNoBody(): void
    {
        $response = $this->authorize();

        $this->assertSame(Response::HTTP_NO_CONTENT, $response->getStatusCode(), (string) $response->getContent());
        $this->assertSame('', (string) $response->getContent());
        $this->assertTrue($this->mercureCookie($response)->isHttpOnly());
    }

    public function testCookieAuthorizesExactlyTheThreeBankAccountTopicsAndPublishesNothing(): void
    {
        $token = $this->mercureCookie($this->authorize())->getValue();
        $this->assertNotNull($token);
        $claims = $this->decodedClaims($token);

        $this->assertSame(
            [
                MercureBankAccountTopic::COLLECTION,
                MercureBankAccountTopic::COLLECTION_TEMPLATE,
                MercureBankAccountTopic::DETAIL_TEMPLATE,
            ],
            $claims['mercure']['subscribe'] ?? null,
        );
        // The security invariant is that a subscriber cookie grants no publish topic — not the shape
        // of the claim. Mercure 0.8 omits `publish` entirely when no publish grant is present, where
        // 0.7 emitted an empty list; under the 0.x protocol both deny publishing, and only a
        // non-empty list would widen the cookie.
        $this->assertSame([], $claims['mercure']['publish'] ?? [], 'the subscriber cookie must grant no publish topic');
    }

    private function authorize(): Response
    {
        $authorization = new Authorization(new HubRegistry(new Hub(
            self::HUB_URL,
            new StaticTokenProvider('subscriber-token-unused-by-createCookie'),
            new LcobucciFactory(self::JWT_SECRET),
        )));

        $controller = new BankAccountRealtimeAuthorizeController($authorization);

        return $controller(Request::create('/bank-accounts/realtime/authorize'));
    }

    private function mercureCookie(Response $response): Cookie
    {
        foreach ($response->headers->getCookies() as $cookie) {
            if ('mercureAuthorization' === $cookie->getName()) {
                return $cookie;
            }
        }

        $this->fail('The response carries no mercureAuthorization cookie.');
    }

    /**
     * @return array{mercure?: array{subscribe?: list<string>, publish?: list<string>}}
     */
    private function decodedClaims(string $jwt): array
    {
        $segments = \explode('.', $jwt);
        $this->assertCount(3, $segments, 'a JWT has three dot-separated segments');
        $base64 = \strtr($segments[1], '-_', '+/');
        $padded = $base64 . \str_repeat('=', (4 - \strlen($base64) % 4) % 4);
        $decoded = \json_decode((string) \base64_decode($padded, true), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);

        /** @var array{mercure?: array{subscribe?: list<string>, publish?: list<string>}} $decoded */
        return $decoded;
    }
}
