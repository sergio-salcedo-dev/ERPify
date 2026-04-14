<?php

declare(strict_types=1);

namespace Erpify\Tests\Behat\Context;

use Behat\MinkExtension\Context\RawMinkContext;
use RuntimeException;

/**
 * Steps for Flysystem-backed content-addressable objects ({@code GET /api/v1/stored-objects/{hash}}).
 */
final class StoredObjectApiContext extends RawMinkContext
{
    private const string STORED_OBJECT_URL_PATTERN = '#api/v1/stored-objects/[a-f0-9]{64}#';

    /**
     * @Then the JSON field :field in the last response should be a stored object URL
     */
    public function theJsonFieldInTheLastResponseShouldBeAStoredObjectUrl(string $field): void
    {
        $value = $this->jsonFieldFromLastResponse($field);
        if (!preg_match(self::STORED_OBJECT_URL_PATTERN, $value)) {
            throw new RuntimeException(sprintf(
                'Field %s value %s does not look like a stored object URL (expected pattern %s)',
                $field,
                $value,
                self::STORED_OBJECT_URL_PATTERN,
            ));
        }
    }

    /**
     * @When I GET the URL from the JSON field :field in the last response
     */
    public function iGetTheUrlFromTheJsonFieldInTheLastResponse(string $field): void
    {
        $raw = $this->jsonFieldFromLastResponse($field);
        $path = $this->requestPathFromPossibleAbsoluteUrl($raw);

        $driver = $this->getSession()->getDriver();

        $driver->getClient()->request('GET', $this->locatePath($path));
    }

    /**
     * @param mixed $path
     */
    public function locatePath($path): string
    {
        return parent::locatePath(ScenarioRememberedValues::interpolate((string) $path));
    }

    private function jsonFieldFromLastResponse(string $field): string
    {
        $content = $this->getSession()->getPage()->getContent();
        /** @var array<string, mixed> $data */
        $data = json_decode((string) $content, true) ?? [];

        if (!\array_key_exists($field, $data)) {
            throw new RuntimeException(sprintf('JSON has no field %s', $field));
        }

        $value = $data[$field];
        if (!\is_string($value) || $value === '') {
            throw new RuntimeException(sprintf('JSON field %s must be a non-empty string', $field));
        }

        return $value;
    }

    private function requestPathFromPossibleAbsoluteUrl(string $url): string
    {
        if (preg_match('~^https?://[^/]+(/[^?#]*)(\?[^#]*)?~', $url, $m)) {
            return $m[1].($m[2] ?? '');
        }

        return $url;
    }
}
