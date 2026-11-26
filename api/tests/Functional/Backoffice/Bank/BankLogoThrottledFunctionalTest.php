<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Backoffice\Bank;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Erpify\Backoffice\Bank\Infrastructure\Controller\BankLogoDeleteController;
use Erpify\Backoffice\Bank\Infrastructure\Controller\BankLogoPutController;
use Erpify\Shared\Images\Infrastructure\Http\ImageRequestThrottle;
use Erpify\Shared\Uuid\Domain\Uuid;
use Erpify\Tests\Functional\AuthenticatesFunctionalRequests;
use Erpify\Tests\Support\Dig;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use ReflectionProperty;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * The write budget, hit through the REAL logo routes: once an identity has spent `image_write_per_identity_route` on a
 * route, its next `PUT` or `DELETE` there is a `429 rate-limited` with `Retry-After`, and it is refused BEFORE anything
 * is read or written — the bank still holds the logo it held, no `image` row appears and nothing is queued. The
 * throttle's own behaviour is route-agnostic and pinned in its unit test; what this holds is that each controller
 * actually spends it, and spends it ahead of the body.
 *
 * The budget is keyed per route, so each verb's bucket is spent separately and reset afterwards: the limiter pool
 * outlives the test.
 *
 * @internal
 */
#[CoversClass(BankLogoPutController::class)]
#[CoversClass(BankLogoDeleteController::class)]
#[CoversClass(ImageRequestThrottle::class)]
final class BankLogoThrottledFunctionalTest extends WebTestCase
{
    use AuthenticatesFunctionalRequests;

    private const string PUT_ROUTE = 'backoffice_bank_logo_set';

    private const string DELETE_ROUTE = 'backoffice_bank_logo_remove';

    private KernelBrowser $client;

    private string $bankId = '';

    /** @var list<string> the limiter keys spent, reset in the teardown */
    private array $spentKeys = [];

    #[Override]
    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->authenticateClient($this->client);
    }

    protected function tearDown(): void
    {
        $limiter = $this->writeLimiter();

        foreach ($this->spentKeys as $key) {
            $limiter->create($key)->reset();
        }

        if ('' !== $this->bankId) {
            $this->connection()->executeStatement('DELETE FROM audit_log WHERE resource_id = ?', [$this->bankId]);
            $this->connection()->executeStatement('DELETE FROM bank WHERE id = ?', [$this->bankId]);
        }

        parent::tearDown();
    }

    #[Test]
    #[DataProvider('provideASpentWriteBudgetIsA429WithRetryAfterAndNothingIsTouchedCases')]
    public function aSpentWriteBudgetIsA429WithRetryAfterAndNothingIsTouched(
        string $method,
        string $route,
        string $length,
    ): void {
        $held = Uuid::generate();
        $this->bankId = $this->seedBankHolding($held);
        $this->spendTheWriteBudgetOn($route);
        $images = $this->rows('SELECT COUNT(*) FROM image');
        $auditRows = "SELECT COUNT(*) FROM audit_log WHERE resource_id = '" . $this->bankId . "'";
        $audit = $this->rows($auditRows);

        $png = (string) \file_get_contents(\dirname(__DIR__, 3) . '/Fixtures/Images/valid.png');
        $this->assertNotSame('', $png, 'the fixture exists, so a PUT that read it would store an image');

        // A body whose declared length is missing or wrong is a 411 the moment anything reads it: answering 429
        // instead is the only way to tell that the budget was spent BEFORE the body was looked at.
        $declared = match ($length) {
            'exact' => ['CONTENT_LENGTH' => (string) \strlen($png)],
            'wrong' => ['CONTENT_LENGTH' => (string) (\strlen($png) + 7)],
            default => [],
        };
        $this->client->request(
            $method,
            '/api/v1/backoffice/banks/' . $this->bankId . '/logo',
            server: 'PUT' === $method ? ['CONTENT_TYPE' => 'image/png'] + $declared : [],
            content: 'PUT' === $method ? $png : null,
        );

        $response = $this->client->getResponse();
        $status = $response->getStatusCode();
        $this->assertSame(Response::HTTP_TOO_MANY_REQUESTS, $status, (string) $response->getContent());
        $this->assertGreaterThan(0, (int) $response->headers->get('Retry-After'), 'Retry-After on the refusal');
        $problem = \json_decode((string) $response->getContent(), true);
        $this->assertSame('rate-limited', Dig::string($problem, 'type'));

        $this->assertSame($held, $this->heldBy($this->bankId), 'the bank still holds the logo it held');
        $this->assertSame($images, $this->rows('SELECT COUNT(*) FROM image'), 'no image row: the body was not read');
        $this->assertSame($audit, $this->rows($auditRows), 'nothing audited');
        $this->assertSame([], $this->queued(), 'nothing was queued');
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function provideASpentWriteBudgetIsA429WithRetryAfterAndNothingIsTouchedCases(): iterable
    {
        yield 'PUT' => ['PUT', self::PUT_ROUTE, 'exact'];

        yield 'PUT declaring no length' => ['PUT', self::PUT_ROUTE, 'none'];

        yield 'PUT declaring the wrong length' => ['PUT', self::PUT_ROUTE, 'wrong'];

        yield 'DELETE' => ['DELETE', self::DELETE_ROUTE, 'none'];
    }

    private function seedBankHolding(string $logo): string
    {
        $id = Uuid::generate();
        // The LAST twelve hex digits: a UUID v7 opens with its millisecond timestamp, so two banks seeded in
        // one millisecond would share a prefix and collide on the unique normalized name.
        $token = \strtoupper(\substr(\str_replace('-', '', $id), -12));
        $this->connection()->executeStatement(
            'INSERT INTO bank (id, name, name_normalized, short_name, created_at, updated_at, logo_image_id) '
            . 'VALUES (?, ?, ?, ?, NOW(), NOW(), ?)',
            [$id, 'Throttled Bank ' . $token, 'throttled bank ' . \strtolower($token), 'TH' . $token, $logo],
        );

        return $id;
    }

    /**
     * Spends the bucket of the seated identity on one route to exhaustion, through the limiter the throttle consumes
     * and under the key it derives, so the next request on that route is the one over budget.
     */
    private function spendTheWriteBudgetOn(string $route): void
    {
        $actor = $this->connection()->fetchOne(
            'SELECT id FROM identity_user WHERE email = ?',
            [self::FUNCTIONAL_USER_EMAIL],
        );
        $this->assertIsString($actor);
        $throttle = self::getContainer()->get(ImageRequestThrottle::class);
        $this->assertInstanceOf(ImageRequestThrottle::class, $throttle);
        // The secret the throttle itself was built with, so the key spent here is the key it derives.
        $secret = (new ReflectionProperty($throttle, 'secret'))->getValue($throttle);
        $this->assertIsString($secret);

        $key = ImageRequestThrottle::keyFor('images.write', $actor, $route, $secret);
        $this->spentKeys[] = $key;
        $limiter = $this->writeLimiter()->create($key);

        for ($spent = 0; $spent < 10_000; ++$spent) {
            if (!$limiter->consume()->isAccepted()) {
                return;
            }
        }

        $this->fail('the write budget never ran out');
    }

    private function writeLimiter(): RateLimiterFactoryInterface
    {
        $limiter = self::getContainer()->get('limiter.image_write_per_identity_route');
        $this->assertInstanceOf(RateLimiterFactoryInterface::class, $limiter);

        return $limiter;
    }

    /**
     * @return list<object> what the in-memory `async` transport was handed since the container booted
     */
    private function queued(): array
    {
        $transport = self::getContainer()->get('messenger.transport.async');
        $this->assertInstanceOf(InMemoryTransport::class, $transport);

        $messages = [];

        foreach ($transport->getSent() as $envelope) {
            $messages[] = $envelope->getMessage();
        }

        return $messages;
    }

    private function heldBy(string $bankId): ?string
    {
        $held = $this->connection()->fetchOne('SELECT logo_image_id FROM bank WHERE id = ?', [$bankId]);

        return \is_string($held) ? $held : null;
    }

    private function rows(string $sql): int
    {
        $count = $this->connection()->fetchOne($sql);

        return \is_numeric($count) ? (int) $count : -1;
    }

    private function connection(): Connection
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->assertInstanceOf(EntityManagerInterface::class, $entityManager);

        return $entityManager->getConnection();
    }
}
