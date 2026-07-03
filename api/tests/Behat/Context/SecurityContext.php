<?php

declare(strict_types=1);

namespace Erpify\Tests\Behat\Context;

use Behat\Behat\Hook\Scope\BeforeScenarioScope;
use Behat\Hook\BeforeScenario;
use Erpify\Backoffice\Identity\Domain\Email;
use Erpify\Backoffice\Identity\Domain\Entity\User;
use Erpify\Backoffice\Identity\Domain\Repository\UserRepository;
use Erpify\Backoffice\Identity\Infrastructure\Security\SecurityUser;
use Erpify\Tests\Behat\Context\Abstraction\AbstractContext;
use FriendsOfBehat\SymfonyExtension\Context\Environment\InitializedSymfonyExtensionEnvironment;
use RuntimeException;

/**
 * Establishes a session for the default fixture user before every scenario, so the suite exercises the
 * default-deny firewall the way production runs — authenticated. The whole business API is protected, so
 * without this each scenario's first request would 401 rather than reach its controller. Scenarios that must
 * observe the unauthenticated edge (the login handshake, the 401 baseline) opt out with the `@anonymous` tag.
 *
 * `loginUser()` seats a session token directly rather than replaying `POST /login`, so it adds no HTTP round
 * trip and no counted query: the firewall's per-request `refreshUser` lookup is raised outside any controller,
 * so {@see \Erpify\Tests\Doctrine\TestDebugDataHolder} already excludes it from the per-connection budgets that
 * scenarios assert. Runs after {@see FixturesContext} so the fixture user exists and the DB is restored.
 */
final class SecurityContext extends AbstractContext
{
    private const string DEFAULT_USER_EMAIL = 'alice@erpify.test';

    private const string FIREWALL = 'main';

    private const string ANONYMOUS_TAG = 'anonymous';

    public function __construct(private readonly UserRepository $users)
    {
    }

    #[BeforeScenario]
    public function authenticateDefaultUser(BeforeScenarioScope $scope): void
    {
        if ($this->optsOutOfAuthentication($scope)) {
            return;
        }

        $user = $this->users->findByEmail(Email::from(self::DEFAULT_USER_EMAIL));

        if (!$user instanceof User) {
            throw new RuntimeException(\sprintf(
                'The default Behat user "%s" is missing; the User fixture must load before authentication.',
                self::DEFAULT_USER_EMAIL,
            ));
        }

        $environment = $scope->getEnvironment();
        self::assertInstanceOf(InitializedSymfonyExtensionEnvironment::class, $environment);

        $environment->getContext(HttpRequestContext::class)
            ->getClient()
            ->loginUser(new SecurityUser($user), self::FIREWALL)
        ;
    }

    private function optsOutOfAuthentication(BeforeScenarioScope $scope): bool
    {
        $tags = [...$scope->getFeature()->getTags(), ...$scope->getScenario()->getTags()];

        return \in_array(self::ANONYMOUS_TAG, $tags, true);
    }
}
