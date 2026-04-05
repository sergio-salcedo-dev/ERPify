<?php

declare(strict_types=1);

namespace Erpify\Tests\Behat\Context;

use Behat\Behat\Hook\Scope\BeforeScenarioScope;
use Behat\Gherkin\Node\PyStringNode;
use Behat\Mink\Driver\BrowserKitDriver;
use Behat\MinkExtension\Context\MinkContext;
use JsonException;
use PDO;
use RuntimeException;

final class FeatureContext extends MinkContext
{
    use MessengerBehatTrait;

    /**
     * Reset and re-seed the database before every scenario so each test
     * starts from the same known state.
     *
     * @BeforeScenario
     */
    public function resetDatabase(BeforeScenarioScope $scope): void
    {
        ScenarioRememberedValues::reset();

        $console = $this->messengerBinConsole();

        exec(
            sprintf('php %s doctrine:fixtures:load --no-interaction --env=dev --purge-with-truncate 2>&1', escapeshellarg($console)),
            $output,
            $exitCode,
        );

        if ($exitCode !== 0) {
            throw new RuntimeException(
                sprintf("doctrine:fixtures:load failed (exit %d):\n%s", $exitCode, implode("\n", $output)),
            );
        }

        $this->purgeMessengerDoctrineQueue();
        $this->clearMailpitInbox();
    }

    /**
     * @Then /^a domain event named "(?P<eventName>[^"]+)" should be recorded for aggregate \{(?P<alias>[^}]+)\}$/
     */
    public function assertDomainEventRecordedForAggregate(string $eventName, string $alias): void
    {
        $aggregateId = ScenarioRememberedValues::require($alias);
        $this->assertValidUuid($aggregateId, $alias);
        $this->assertDomainEventNameFormat($eventName);

        $pdo = $this->pdoFromDatabaseUrl();
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM domain_event WHERE name = :name AND aggregate_id = :aggregate_id');
        $stmt->execute(['name' => $eventName, 'aggregate_id' => $aggregateId]);
        $count = (int) $stmt->fetchColumn();

        if ($count < 1) {
            throw new RuntimeException(
                sprintf(
                    'Expected domain_event row for name %s and aggregate_id %s, found %d.',
                    $eventName,
                    $aggregateId,
                    $count,
                ),
            );
        }
    }

    /**
     * Send any HTTP method with a JSON body.
     *
     * Example:
     *   When I send a POST request to "/api/v1/backoffice/banks" with body:
     *     """
     *     {"name": "Test", "short_name": "TST"}
     *     """
     *
     * @When I send a :method request to :url with body:
     */
    public function iSendARequestToWithBody(string $method, string $url, PyStringNode $body): void
    {
        $url = ScenarioRememberedValues::interpolate($url);

        $driver = $this->getSession()->getDriver();
        assert($driver instanceof BrowserKitDriver);
        $driver->getClient()->request(
            strtoupper($method),
            $this->locatePath($url),
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            $body->getRaw(),
        );
    }

    /**
     * Send any HTTP method without a body (useful for DELETE).
     *
     * Example:
     *   When I send a DELETE request to "/api/v1/backoffice/banks/{bankId}"
     *
     * @When I send a :method request to :url
     */
    public function iSendARequestTo(string $method, string $url): void
    {
        $url = ScenarioRememberedValues::interpolate($url);

        $driver = $this->getSession()->getDriver();

        assert($driver instanceof BrowserKitDriver);

        $driver
        ->getClient()
        ->request(
            strtoupper($method),
            $this->locatePath($url),
        );
    }

    /**
     * @Then the response should be JSON
     */
    public function theResponseShouldBeJson(): void
    {
        $content = $this->getSession()->getPage()->getContent();
        try {
            json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException(
                sprintf('Response is not valid JSON: %s', $e->getMessage()),
                0,
                $e,
            );
        }
    }

    /**
     * Store a top-level JSON field from the last response for use in later steps.
     *
     * Example:
     *   And I remember the JSON field "id" as "bankId"
     *
     * @Then I remember the JSON field :field as :alias
     */
    public function iRememberJsonFieldAs(string $field, string $alias): void
    {
        $content = $this->getSession()->getPage()->getContent();

        /** @var array<string, mixed> $data */
        $data = json_decode($content, true) ?? [];

        ScenarioRememberedValues::set($alias, (string) ($data[$field] ?? ''));
    }

    /**
     * Override locatePath so that ALL Mink path-based steps (including "I go to")
     * support {alias} placeholder interpolation automatically.
     *
     * @param mixed $path
     */
    public function locatePath($path): string
    {
        return parent::locatePath(ScenarioRememberedValues::interpolate((string) $path));
    }

    private function assertValidUuid(string $value, string $forAlias): void
    {
        if (!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[1-5][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/Di', $value)) {
            throw new RuntimeException(sprintf('Value for {%s} is not a UUID: %s', $forAlias, $value));
        }
    }

    private function pdoFromDatabaseUrl(): PDO
    {
        $url = getenv('DATABASE_URL');
        if ($url === false || $url === '') {
            throw new RuntimeException('DATABASE_URL is not set; cannot query domain_event from Behat.');
        }

        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host'], $parts['path'])) {
            throw new RuntimeException('DATABASE_URL could not be parsed for Behat DB assertions.');
        }

        $scheme = strtolower((string) $parts['scheme']);
        if (!in_array($scheme, ['postgresql', 'postgres'], true)) {
            throw new RuntimeException('DATABASE_URL must be a PostgreSQL DSN for Behat DB assertions.');
        }

        $dbName = ltrim((string) $parts['path'], '/');
        $port = isset($parts['port']) ? (int) $parts['port'] : 5432;
        $user = isset($parts['user']) ? rawurldecode((string) $parts['user']) : '';
        $pass = isset($parts['pass']) ? rawurldecode((string) $parts['pass']) : '';

        $dsn = sprintf('pgsql:host=%s;port=%d;dbname=%s', $parts['host'], $port, $dbName);

        return new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
    }
}
