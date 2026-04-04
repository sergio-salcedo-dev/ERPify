<?php

declare(strict_types=1);

namespace Erpify\Tests\Behat\Context;

use Behat\Behat\Hook\Scope\BeforeScenarioScope;
use Behat\Gherkin\Node\PyStringNode;
use Behat\Mink\Driver\BrowserKitDriver;
use Behat\MinkExtension\Context\MinkContext;
use RuntimeException;

final class FeatureContext extends MinkContext
{
    /** @var array<string, string> */
    private array $rememberedValues = [];

    /**
     * Reset and re-seed the database before every scenario so each test
     * starts from the same known state.
     *
     * @BeforeScenario
     */
    public function resetDatabase(BeforeScenarioScope $scope): void
    {
        // Resolve bin/console relative to this file:
        // tests/Behat/Context/ → tests/Behat/ → tests/ → project root
        $console = dirname(__DIR__, 3) . '/bin/console';

        exec(
            sprintf('php %s doctrine:fixtures:load --no-interaction --env=dev --purge-with-truncate 2>&1', $console),
            $output,
            $exitCode,
        );

        if ($exitCode !== 0) {
            throw new RuntimeException(
                sprintf("doctrine:fixtures:load failed (exit %d):\n%s", $exitCode, implode("\n", $output)),
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
        $url = $this->interpolatePlaceholders($url);

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
        $url = $this->interpolatePlaceholders($url);

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

        $this->rememberedValues[$alias] = (string) ($data[$field] ?? '');
    }

    /**
     * Override locatePath so that ALL Mink path-based steps (including "I go to")
     * support {alias} placeholder interpolation automatically.
     *
     * @param mixed $path
     */
    public function locatePath($path): string
    {
        return parent::locatePath($this->interpolatePlaceholders((string) $path));
    }

    /**
     * Replace {alias} placeholders in a URL with previously remembered values.
     */
    private function interpolatePlaceholders(string $url): string
    {
        foreach ($this->rememberedValues as $key => $value) {
            $url = str_replace('{' . $key . '}', $value, $url);
        }

        return $url;
    }
}
