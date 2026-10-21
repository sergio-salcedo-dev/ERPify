<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Iam\Session;

use Erpify\Iam\Session\Domain\Repository\SessionRepository;
use Erpify\Tests\Functional\AuthenticatesFunctionalRequests;
use Erpify\Tests\Functional\Iam\Session\Fixtures\UnavailableSessionRepository;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Fail-closed, end-to-end. With the session store unreachable, an authenticated `/api` request must not fall
 * open (200) and must not be mistaken for a re-login (401): it fails closed with the operational 503
 * `service-unavailable` (a 5xx that reaches Sentry, distinct from the 401 identity outcome). This asserts the
 * whole assembly — gate → `SessionStoreUnavailable` → error-contract pipeline → 503 — over a real HTTP request,
 * complementing the unit coverage of each part.
 *
 * @internal
 */
#[CoversNothing]
final class SessionStoreUnavailableAdmissionTest extends WebTestCase
{
    use AuthenticatesFunctionalRequests;

    private const int HTTP_SERVICE_UNAVAILABLE = 503;

    #[DataProvider('provideTheGateFailsClosedWith503WhenTheStoreIsUnreachableCases')]
    public function testTheGateFailsClosedWith503WhenTheStoreIsUnreachable(string $path): void
    {
        $client = self::createClient();

        // Register the read-down store BEFORE seating, so the service is not yet initialized (Symfony forbids
        // replacing an already-resolved service). Seating writes through it (a no-op), then every read fails.
        // disableReboot keeps the override in the container the next request resolves the gate from.
        self::getContainer()->set(SessionRepository::class, new UnavailableSessionRepository());
        $client->disableReboot();

        $this->authenticateClient($client);

        $client->request(Request::METHOD_GET, $path);

        $response = $client->getResponse();

        $this->assertSame(self::HTTP_SERVICE_UNAVAILABLE, $response->getStatusCode(), (string) $response->getContent());
        $this->assertStringContainsString('service-unavailable', (string) $response->getContent());
    }

    /**
     * The router decodes the path before dispatching, so a percent-encoded `/api` reaches the same controller
     * and must meet the same gate: the firewall authenticates it, and admission has to agree.
     *
     * @return iterable<string, array{string}>
     */
    public static function provideTheGateFailsClosedWith503WhenTheStoreIsUnreachableCases(): iterable
    {
        yield 'plain' => ['/api/v1/me'];
        yield 'percent-encoded' => ['/%61pi/v1/me'];
    }
}
