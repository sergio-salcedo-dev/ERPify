<?php

declare(strict_types=1);

namespace Erpify\Tests\Behat\Context;

use Behat\Behat\Hook\Scope\BeforeScenarioScope;
use Behat\Hook\BeforeScenario;
use Behat\Step\Given;
use Erpify\Backoffice\Identity\Domain\Email;
use Erpify\Backoffice\Identity\Domain\Entity\User;
use Erpify\Backoffice\Identity\Domain\Repository\UserRepository;
use Erpify\Backoffice\Identity\Infrastructure\Security\SecurityUser;
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
 * The default user (Alice) carries `ROLE_AUDIT_READER`. Scenarios that must observe the role gate from the
 * other side — an authenticated caller who lacks the role, so the route answers 403 rather than 401 — switch
 * to the role-less fixture user with "I am logged in as a user without the audit-reader role".
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

    private const string FIREWALL = 'main';

    private const string ANONYMOUS_TAG = 'anonymous';

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

    private function logInAs(string $email): void
    {
        $user = $this->users->findByEmail(Email::from($email));

        if (!$user instanceof User) {
            throw new RuntimeException(\sprintf(
                'The Behat user "%s" is missing; the User fixture must load before authentication.',
                $email,
            ));
        }

        $this->client()->loginUser(new SecurityUser($user), self::FIREWALL);
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
