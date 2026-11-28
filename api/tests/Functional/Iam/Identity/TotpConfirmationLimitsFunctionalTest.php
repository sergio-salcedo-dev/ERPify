<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Iam\Identity;

use Erpify\Iam\Identity\Application\TotpSecretVault;
use Erpify\Iam\Identity\Domain\Entity\TotpFactor;
use Erpify\Iam\Identity\Domain\Repository\TotpFactorRepository;
use Erpify\Iam\Identity\Infrastructure\Controller\ConfirmTotpFactorController;
use Erpify\Iam\Identity\Infrastructure\Http\ConfirmTotpFactorRequest;
use Erpify\Iam\Identity\Infrastructure\Security\SecondFactorCodeThrottle;
use Erpify\Shared\Access\Domain\Role;
use Erpify\Shared\Uuid\Domain\Uuid;
use Erpify\Tests\Functional\AuthenticatesFunctionalRequests;
use Erpify\Tests\Functional\ResolvesContainerServices;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\LimiterInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * What bounds a confirmation of the enrolment at its route: the per-identity code budget the controller spends
 * BEFORE the use case, the per-enrolment cap counted on the factor row, the ten-minute window, and the 500 a sealed
 * secret that no longer opens answers.
 *
 * The budget is spent through the limiter the throttle consumes, from the container, before the one request that
 * meets it. Driving it with eleven requests instead is not possible: in the test environment the bucket lives in
 * the array cache pool, and the kernel resets its services (that pool included) before every request after the
 * first, rebooted or not, so no bucket survives from one request to the next.
 *
 * @internal
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects") the route, its payload and throttle, the factor seeded behind
 *                                                   the route, the vault that seals it and the limiter
 */
#[CoversClass(ConfirmTotpFactorController::class)]
#[CoversClass(ConfirmTotpFactorRequest::class)]
#[CoversClass(SecondFactorCodeThrottle::class)]
final class TotpConfirmationLimitsFunctionalTest extends WebTestCase
{
    use AuthenticatesFunctionalRequests;
    use DrivesTotpEnrolment;
    use ResolvesContainerServices;

    /** A dedicated identity, distinct from the enrolment flow's, so the two classes never share a factor row. */
    private const string EMAIL = 'totp-confirmation-limits@erpify.test';

    private const string CURRENT_PASSWORD = 'functional-password';

    private const int CODE_BUDGET = 10;

    /**
     * Once the identity's code budget is spent, a request carrying the CORRECT code is refused 429 and the factor
     * stays pending with nothing counted on it: no count kept inside the use case could produce that answer.
     */
    public function testASpentCodeBudgetRefusesEvenTheCorrectCodeBeforeTheUseCaseRuns(): void
    {
        $client = self::createClient();
        $this->purgeIdentity();
        $this->authenticateAs($client, self::EMAIL, [Role::VIEWER->value]);
        $secret = $this->seedPendingFactor();
        $budget = $this->codeBudget();

        for ($spent = 0; $spent < self::CODE_BUDGET; ++$spent) {
            $this->assertTrue($budget->consume()->isAccepted(), \sprintf('token %d of the budget', $spent + 1));
        }

        $this->assertFalse($budget->consume()->isAccepted(), 'the budget is spent before the request');

        $this->post($client, self::CONFIRM_ENDPOINT, ['code' => $this->currentCodeOf($secret)]);

        $this->assertStatus($client, Response::HTTP_TOO_MANY_REQUESTS);
        $this->assertStringContainsString('rate-limited', (string) $client->getResponse()->getContent());
        $this->assertGreaterThan(0, (int) $client->getResponse()->headers->get('Retry-After'));
        $this->assertSame(['pending'], $this->storedFactorStates(), 'the use case never ran on the refused code');
        $this->assertSame(0, $this->storedFailedConfirmAttempts(), 'and counted nothing on the enrolment');
    }

    /**
     * A body the mapper refuses (422) spends nothing, since mapping runs before the action: with nine tokens spent,
     * the tenth is still there after the refused request.
     */
    public function testABodyTheMapperRefusesSpendsNoCodeBudget(): void
    {
        $client = self::createClient();
        $this->purgeIdentity();
        $this->authenticateAs($client, self::EMAIL, [Role::VIEWER->value]);
        $budget = $this->codeBudget();

        for ($spent = 1; $spent < self::CODE_BUDGET; ++$spent) {
            $this->assertTrue($budget->consume()->isAccepted(), \sprintf('token %d of the budget', $spent));
        }

        $this->post($client, self::CONFIRM_ENDPOINT, ['code' => '']);

        $this->assertStatus($client, Response::HTTP_UNPROCESSABLE_ENTITY);
        $budget = $this->codeBudget();
        $this->assertTrue($budget->consume()->isAccepted(), 'the refused body spent no token, so the tenth is left');
        $this->assertFalse($budget->consume()->isAccepted(), 'and the budget held ten');
    }

    /**
     * The per-enrolment cap is counted on the row and commits although each request fails, so the fifth wrong code
     * closes the enrolment and the correct code that follows meets the 409 rather than a code check.
     */
    public function testTheFifthWrongCodeClosesTheEnrolment(): void
    {
        $client = self::createClient();
        $this->purgeIdentity();
        $this->authenticateAs($client, self::EMAIL, [Role::VIEWER->value]);
        $secret = $this->enrol($client);

        for ($attempt = 1; $attempt <= TotpFactor::MAX_FAILED_CONFIRMATIONS; ++$attempt) {
            $this->post($client, self::CONFIRM_ENDPOINT, ['code' => $this->wrongCode($secret)]);
            $this->assertSame(
                Response::HTTP_FORBIDDEN,
                $client->getResponse()->getStatusCode(),
                \sprintf('wrong code %d: %s', $attempt, (string) $client->getResponse()->getContent()),
            );
        }

        $this->assertSame(TotpFactor::MAX_FAILED_CONFIRMATIONS, $this->storedFailedConfirmAttempts());

        $this->post($client, self::CONFIRM_ENDPOINT, ['code' => $this->currentCode($secret)]);

        $this->assertStatus($client, Response::HTTP_CONFLICT);
        $this->assertStringContainsString('second-factor-not-enrolled', (string) $client->getResponse()->getContent());
        $this->assertSame(['pending'], $this->storedFactorStates());
        $this->assertSame(TotpFactor::MAX_FAILED_CONFIRMATIONS, $this->storedFailedConfirmAttempts(), 'it stops');
    }

    public function testAPendingEnrolmentOlderThanTenMinutesCannotBeConfirmed(): void
    {
        $client = self::createClient();
        $this->purgeIdentity();
        $this->authenticateAs($client, self::EMAIL, [Role::VIEWER->value]);
        $secret = $this->enrol($client);
        $aged = $this->functionalConnection()->executeStatement(
            "UPDATE identity_totp_factor SET enrolment_started_at = enrolment_started_at - INTERVAL '10 minutes' "
            . 'WHERE user_id = CAST(:id AS uuid)',
            ['id' => $this->identityId()],
        );
        $this->assertSame(1, $aged, 'the enrolment must really have been aged');

        $this->post($client, self::CONFIRM_ENDPOINT, ['code' => $this->currentCode($secret)]);

        $this->assertStatus($client, Response::HTTP_CONFLICT);
        $this->assertStringContainsString('second-factor-not-enrolled', (string) $client->getResponse()->getContent());
        $this->assertSame(['pending'], $this->storedFactorStates());

        // A new enrolment restarts it, with a new secret and a fresh window.
        $restarted = $this->enrol($client);
        $this->assertNotSame($secret, $restarted);

        $this->post($client, self::CONFIRM_ENDPOINT, ['code' => $this->currentCode($restarted)]);
        $this->assertStatus($client, Response::HTTP_OK);
    }

    #[TestDox('A sealed secret that no longer authenticates answers the 500 integrity failure at the route')]
    public function testASealedSecretThatNoLongerOpensIsTheIntegrityFailure(): void
    {
        $client = self::createClient();
        $this->purgeIdentity();
        $this->authenticateAs($client, self::EMAIL, [Role::VIEWER->value]);
        $secret = $this->enrol($client);
        $tampered = $this->functionalConnection()->executeStatement(
            'UPDATE identity_totp_factor SET sealed_secret = :tampered WHERE user_id = CAST(:id AS uuid)',
            ['tampered' => \str_repeat('A', 60), 'id' => $this->identityId()],
        );
        $this->assertSame(1, $tampered, 'the ciphertext must really have been altered');

        $this->post($client, self::CONFIRM_ENDPOINT, ['code' => $this->currentCode($secret)]);

        $this->assertStatus($client, Response::HTTP_INTERNAL_SERVER_ERROR);
        $this->assertStringContainsString(
            'second-factor-integrity-failure',
            (string) $client->getResponse()->getContent(),
        );
        $this->assertSame(['pending'], $this->storedFactorStates());
    }

    /**
     * Writes a pending factor behind the route, sealed by the real vault, so the budget can be spent before the
     * first request of the test: a request to enrol would itself reset the bucket before the next one.
     *
     * @return string the raw secret, from which the current code is computed
     */
    private function seedPendingFactor(): string
    {
        $userId = $this->identityId();
        $scopeId = Uuid::generate();
        $secret = \random_bytes(20);

        $this->service(TotpFactorRepository::class)->save(TotpFactor::enrol(
            Uuid::generate(),
            $userId,
            $scopeId,
            $this->service(TotpSecretVault::class)->seal($scopeId, $userId, $secret),
            $this->applicationClock()->now(),
        ));

        return $secret;
    }

    /**
     * The identity's bucket in the limiter {@see SecondFactorCodeThrottle} consumes, under the key it consumes it.
     */
    private function codeBudget(): LimiterInterface
    {
        $factory = self::getContainer()->get('limiter.second_factor_code_per_identity');
        $this->assertInstanceOf(RateLimiterFactoryInterface::class, $factory);

        return $factory->create($this->identityId());
    }
}
