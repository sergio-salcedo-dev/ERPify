<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Iam\Identity;

use DateInterval;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Erpify\Iam\Identity\Domain\Entity\PasswordResetToken;
use Erpify\Iam\Identity\Domain\Entity\RecoverySecret;
use Erpify\Iam\Identity\Domain\Repository\PasswordResetTokenRepository;
use Erpify\Iam\Identity\Infrastructure\Http\CompletePasswordResetController;
use Erpify\Iam\Identity\Infrastructure\Http\RedeemRecoverySecretController;
use Erpify\Shared\Clock\Domain\Clock;
use Erpify\Shared\Token\Domain\SingleUseToken;
use Erpify\Shared\Uuid\Domain\Uuid;
use Erpify\Tests\Functional\AssertsSessionIdRotation;
use Erpify\Tests\Functional\ResolvesContainerServices;
use Erpify\Tests\Functional\SeedsAnOrganizationMember;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The two anonymous flows that end by signing the device in — completing a password reset and redeeming a
 * recovery secret — each answered on a browser carrying a session id an attacker planted beforehand. Both have
 * to hand back an id the browser did not bring, and leave the planted one admitting nobody.
 *
 * Driven through the kernel with a real organization member, because both halves are properties of the whole
 * request: the rotation is the firewall's, and the planted id is read back through the admission gate.
 *
 * @internal
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects") — two flows each seeded through their own aggregate and
 * repository; the coupling is the flows', not an avoidable dependency of the test.
 */
#[CoversClass(CompletePasswordResetController::class)]
#[CoversClass(RedeemRecoverySecretController::class)]
final class AnonymousReauthenticationSessionFixationFunctionalTest extends WebTestCase
{
    use AssertsSessionIdRotation;
    use ResolvesContainerServices;
    use SeedsAnOrganizationMember;

    private const string RESET_PATH = '/api/v1/backoffice/reset-password';

    private const string REDEEM_PATH = '/api/v1/backoffice/recovery/redeem';

    private const string PASSWORD = 'the-password-of-a-fixated-member';

    private const string CSRF_TOKEN = 'a-32-char-stateless-csrf-nonce!!';

    private KernelBrowser $client;

    private Connection $connection;

    private string $userId = '';

    private string $email = '';

    #[Override]
    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();

        $this->connection = $this->service(EntityManagerInterface::class)->getConnection();

        $this->userId = Uuid::generate();
        $this->email = \sprintf('fixation-%s@erpify.test', \str_replace('-', '', $this->userId));
        $this->seedAnOrganizationMember($this->userId, $this->email, self::PASSWORD);
    }

    protected function tearDown(): void
    {
        foreach (['identity_password_reset_token', 'identity_recovery_secret', 'iam_session'] as $table) {
            $this->connection->executeStatement(
                \sprintf('DELETE FROM %s WHERE user_id = CAST(:id AS uuid)', $table),
                ['id' => $this->userId],
            );
        }

        $this->forgetTheOrganizationMember($this->userId, $this->email);

        parent::tearDown();
    }

    public function testCompletingAResetSignsTheDeviceInOnASessionIdItDidNotBring(): void
    {
        $generated = SingleUseToken::mint($this->service(Clock::class)->now()->add(new DateInterval('PT1H')));
        $tokenId = Uuid::generate();
        $this->service(PasswordResetTokenRepository::class)->save(
            PasswordResetToken::issue($tokenId, $this->userId, $generated->token),
        );

        $planted = $this->plantSessionId($this->client);
        $this->post(self::RESET_PATH, [
            'token' => $tokenId . '.' . $generated->plaintext(),
            'password' => 'a-replacement-for-the-fixated-password',
        ]);

        $this->assertSucceeded();
        $this->assertSessionIdRotatedAwayFrom($this->client, $planted);
    }

    public function testRedeemingARecoverySecretSignsTheDeviceInOnASessionIdItDidNotBring(): void
    {
        $generated = RecoverySecret::mint($this->userId, $this->service(Clock::class)->now());
        $generated->secret->pullDomainEvents();

        $entityManager = $this->service(EntityManagerInterface::class);
        $entityManager->persist($generated->secret);
        $entityManager->flush();
        $entityManager->clear();

        $planted = $this->plantSessionId($this->client);
        $this->post(self::REDEEM_PATH, ['secret' => $generated->plaintext()]);

        $this->assertSucceeded();
        $this->assertSessionIdRotatedAwayFrom($this->client, $planted);
    }

    /**
     * @param array<string, string> $body
     */
    private function post(string $path, array $body): void
    {
        $this->client->request(
            Request::METHOD_POST,
            $path,
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_ORIGIN' => 'http://localhost',
                'HTTP_X_CSRF_TOKEN' => self::CSRF_TOKEN,
            ],
            content: \json_encode($body, JSON_THROW_ON_ERROR),
        );
    }

    private function assertSucceeded(): void
    {
        $response = $this->client->getResponse();
        $this->assertSame(Response::HTTP_NO_CONTENT, $response->getStatusCode(), (string) $response->getContent());
    }
}
