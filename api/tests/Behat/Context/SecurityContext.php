<?php

declare(strict_types=1);

namespace Erpify\Tests\Behat\Context;

use Behat\Behat\Hook\Scope\BeforeScenarioScope;
use Behat\Hook\BeforeScenario;
use Behat\Step\Given;
use Erpify\Iam\Identity\Domain\Email;
use Erpify\Iam\Identity\Domain\Entity\User;
use Erpify\Iam\Identity\Domain\Repository\UserRepository;
use Erpify\Iam\Identity\Infrastructure\Security\SecurityUser;
use Erpify\Tests\Behat\Context\Abstraction\AbstractContext;
use FriendsOfBehat\SymfonyExtension\Context\Environment\InitializedSymfonyExtensionEnvironment;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Establishes a session for the default fixture user before every scenario, so the suite exercises the
 * default-deny firewall the way production runs — authenticated. The whole business API is protected, so
 * without this each scenario's first request would 401 rather than reach its controller. Scenarios that must
 * observe the unauthenticated edge (the login handshake, the 401 baseline) opt out with the `@anonymous` tag.
 *
 * The default user (Alice) carries `ROLE_AUDIT_READER`, which grants the `auditTrail.read` permission the
 * audit routes require. Scenarios that must observe the gate from the other side — an authenticated caller
 * who is not granted the permission, so the route answers 403 rather than 401 — switch either to the
 * role-less fixture user ("I am logged in as a user without the audit-reader role") or to a fully-tiered
 * MANAGER ("I am logged in as a generic-tier user without the audit-reader role"), still denied because the
 * trail opts out of tier auto-grant. The bank access-control scenarios add two tier-boundary users — a VIEWER
 * ("I am logged in as a viewer", read only) and an EDITOR ("I am logged in as an editor", read+write, no
 * delete) — to pin that each bank route demands its exact permission tier, not merely an authenticated session.
 *
 * `loginUser()` seats a session token directly rather than replaying `POST /login`, so it adds no HTTP round
 * trip and no counted query: the firewall's per-request `refreshUser` lookup is raised outside any controller,
 * so {@see \Erpify\Tests\Doctrine\TestDebugDataHolder} already excludes it from the per-connection budgets that
 * scenarios assert. Runs after {@see FixturesContext} so the fixture user exists and the DB is restored.
 */
final class SecurityContext extends AbstractContext
{
    private const string DEFAULT_USER_EMAIL = 'alice@erpify.test';

    private const string ROLELESS_USER_EMAIL = 'mallory@erpify.test';

    private const string GENERIC_TIER_USER_EMAIL = 'trent@erpify.test';

    private const string VIEWER_USER_EMAIL = 'victor@erpify.test';

    private const string EDITOR_USER_EMAIL = 'edith@erpify.test';

    private const string FIREWALL = 'main';

    private const string ANONYMOUS_TAG = 'anonymous';

    private const string SESSION_BAG_KEY = 'iamSessionId';

    /**
     * The deterministic registry-session id seeded per user in Session.yaml. Seating the matching id into the
     * request session at login is what carries each authenticated scenario through the admission gate; keeping
     * the rows in fixtures (not minting here) means a mid-scenario "reload fixtures" restores them.
     */
    private const array SESSION_ID_BY_EMAIL = [
        self::DEFAULT_USER_EMAIL => '0190d1e2-f3a4-7b5c-8d6e-1f2a3b4c5d01',
        self::ROLELESS_USER_EMAIL => '0190d1e2-f3a4-7b5c-8d6e-1f2a3b4c5d02',
        self::GENERIC_TIER_USER_EMAIL => '0190d1e2-f3a4-7b5c-8d6e-1f2a3b4c5d03',
        self::VIEWER_USER_EMAIL => '0190d1e2-f3a4-7b5c-8d6e-1f2a3b4c5d04',
        self::EDITOR_USER_EMAIL => '0190d1e2-f3a4-7b5c-8d6e-1f2a3b4c5d05',
    ];

    private ?InitializedSymfonyExtensionEnvironment $environment = null;

    public function __construct(private readonly UserRepository $users)
    {
    }

    #[BeforeScenario]
    public function authenticateDefaultUser(BeforeScenarioScope $scope): void
    {
        $environment = $scope->getEnvironment();
        self::assertInstanceOf(InitializedSymfonyExtensionEnvironment::class, $environment);
        $this->environment = $environment;

        if ($this->optsOutOfAuthentication($scope)) {
            return;
        }

        $this->logInAs(self::DEFAULT_USER_EMAIL);
    }

    #[Given('I am logged in as a user without the audit-reader role')]
    public function logInAsAUserWithoutTheAuditReaderRole(): void
    {
        $this->logInAs(self::ROLELESS_USER_EMAIL);
    }

    #[Given('I am logged in as a generic-tier user without the audit-reader role')]
    public function logInAsAGenericTierUserWithoutTheAuditReaderRole(): void
    {
        $this->logInAs(self::GENERIC_TIER_USER_EMAIL);
    }

    #[Given('I am logged in as a viewer')]
    public function logInAsAViewer(): void
    {
        $this->logInAs(self::VIEWER_USER_EMAIL);
    }

    #[Given('I am logged in as an editor')]
    public function logInAsAnEditor(): void
    {
        $this->logInAs(self::EDITOR_USER_EMAIL);
    }

    private function logInAs(string $email): void
    {
        $user = $this->users->findByEmail(Email::from($email));

        if (!$user instanceof User) {
            throw new RuntimeException(\sprintf(
                'The Behat user "%s" is missing; the User fixture must load before authentication.',
                $email,
            ));
        }

        $sessionId = self::SESSION_ID_BY_EMAIL[$email]
            ?? throw new RuntimeException(\sprintf('No fixture registry session is seeded for "%s".', $email));

        $client = $this->client();
        $client->loginUser(new SecurityUser($user), self::FIREWALL);

        // Production parity: an authenticated request must carry a live registry session or the admission gate
        // 401s it. Stash the (fixture-seeded) session id in the very session the login seated the token in, so
        // the next request the gate reads finds it — the same correlation real login's minting writes.
        $httpSession = $client->getSession();
        self::assertNotNull($httpSession);
        $httpSession->set(self::SESSION_BAG_KEY, $sessionId);
        $httpSession->save();
    }

    private function client(): KernelBrowser
    {
        self::assertInstanceOf(InitializedSymfonyExtensionEnvironment::class, $this->environment);

        return $this->environment->getContext(HttpRequestContext::class)->getClient();
    }

    private function optsOutOfAuthentication(BeforeScenarioScope $scope): bool
    {
        $tags = [...$scope->getFeature()->getTags(), ...$scope->getScenario()->getTags()];

        return \in_array(self::ANONYMOUS_TAG, $tags, true);
    }
}
