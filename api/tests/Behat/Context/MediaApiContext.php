<?php

declare(strict_types=1);

namespace Erpify\Tests\Behat\Context;

use Behat\Gherkin\Node\TableNode;
use Behat\MinkExtension\Context\RawMinkContext;
use Behat\Step\Then;
use Behat\Step\When;
use JsonException;
use Override;
use RuntimeException;
use Symfony\Component\BrowserKit\Response as BrowserKitResponse;
use Symfony\Component\HttpFoundation\Response as HttpFoundationResponse;

/**
 * HTTP steps for multipart uploads, public media GET, and response header / JSON field checks.
 */
final class MediaApiContext extends RawMinkContext
{
    /**
     * Multipart POST. Use @relative/path under features/fixtures/ for file fields (e.g. @minimal-logo.png).
     */
    #[When('/^I send a POST multipart request to "(?P<url>[^"]+)" with fields:$/')]
    public function iSendPostMultipartRequestToWithFields(string $url, TableNode $tableNode): void
    {
        $url = ScenarioRememberedValues::interpolate($url);
        $fixturesDir = \dirname(__DIR__, 3) . '/features/fixtures';
        $parameters = [];
        $files = [];

        foreach ($tableNode->getHash() as $row) {
            $field = \trim((string) ($row['field'] ?? ''));
            $value = ScenarioRememberedValues::interpolate(\trim((string) ($row['value'] ?? '')));

            if ('' === $field) {
                continue;
            }

            if (!\str_starts_with($value, '@')) {
                $parameters[$field] = $value;

                continue;
            }

            // File processing
            $path = $fixturesDir . '/' . \substr($value, 1);

            if (!\is_file($path)) {
                throw new RuntimeException(\sprintf('Multipart fixture not found: %s', $path));
            }

            $files[$field] = [
                'name' => \basename($path),
                'type' => $this->guessMimeType($path),
                'tmp_name' => $path,
                'error' => UPLOAD_ERR_OK,
                'size' => \filesize($path) ?: 0,
            ];
        }

        $this->getSession()->getDriver()->getClient()->request(
            'POST',
            $this->locatePath($url),
            $parameters,
            $files,
        );
    }

    #[When('I send a GET request to the URL stored as :alias')]
    public function iSendGetRequestToUrlStoredAs(string $alias): void
    {
        $this->sendGetRequestToUrlStoredAsWithServer($alias, []);
    }

    #[When('I send a GET request to the URL stored as :alias with headers:')]
    public function iSendGetRequestToUrlStoredAsWithHeaders(string $alias, TableNode $tableNode): void
    {
        $server = [];

        foreach ($tableNode->getRowsHash() as $name => $value) {
            $server[$this->httpHeaderToServerKey(\trim((string) $name))] = ScenarioRememberedValues::interpolate(\trim((string) $value));
        }

        $this->sendGetRequestToUrlStoredAsWithServer($alias, $server);
    }

    #[Then('I remember the response header :headerName as :alias')]
    public function iRememberResponseHeaderAs(string $headerName, string $alias): void
    {
        $value = $this->getLastResponseHeader($headerName);

        if (null === $value) {
            throw new RuntimeException(\sprintf('Response has no header %s', $headerName));
        }

        ScenarioRememberedValues::set($alias, $value);
    }

    #[Then('the response header :headerName should be :expected')]
    public function theResponseHeaderShouldBe(string $headerName, string $expected): void
    {
        $actual = $this->getLastResponseHeader($headerName);

        if ($actual !== $expected) {
            throw new RuntimeException(\sprintf('Expected header %s=%s, got %s', $headerName, $expected, (string) $actual));
        }
    }

    #[Then('the response header :headerName should contain :substring')]
    public function theResponseHeaderShouldContain(string $headerName, string $substring): void
    {
        $actual = (string) $this->getLastResponseHeader($headerName);

        if (!\str_contains($actual, $substring)) {
            throw new RuntimeException(\sprintf('Expected header %s to contain %s, was %s', $headerName, $substring, $actual));
        }
    }

    #[Then('the response header :headerName should match :pattern')]
    public function theResponseHeaderShouldMatch(string $headerName, string $pattern): void
    {
        $headerValue = $this->getLastResponseHeader($headerName);
        $actual = \trim((string) $headerValue, '"');
        $matched = \preg_match($pattern, $actual);

        if (false === $matched) {
            throw new RuntimeException(\sprintf('Invalid regex pattern: %s', $pattern));
        }

        if (1 !== $matched) {
            throw new RuntimeException(\sprintf('Header %s value %s does not match %s', $headerName, $actual, $pattern));
        }
    }

    /**
     * @throws JsonException
     */
    #[Then('the JSON field :field in the last response should match :pattern')]
    public function theJsonFieldShouldMatch(string $field, string $pattern): void
    {
        $content = $this->getSession()->getPage()->getContent();

        /** @var array<string, mixed> $data */
        $data = \json_decode((string) $content, true, 512, JSON_THROW_ON_ERROR) ?? [];
        $value = (string) ($data[$field] ?? '');

        if (!\preg_match($pattern, $value)) {
            throw new RuntimeException(\sprintf('Field %s value %s does not match %s', $field, $value, $pattern));
        }
    }

    #[Override]
    public function locatePath($path): string
    {
        return parent::locatePath(ScenarioRememberedValues::interpolate((string) $path));
    }

    private function guessMimeType(string $path): string
    {
        return \mime_content_type($path) ?: match (\strtolower(\pathinfo($path, PATHINFO_EXTENSION))) {
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'webp' => 'image/webp',
            default => 'application/octet-stream',
        };
    }

    /**
     * @param array<string, string> $server
     */
    private function sendGetRequestToUrlStoredAsWithServer(string $alias, array $server): void
    {
        $raw = ScenarioRememberedValues::require($alias);
        $path = $this->requestPathFromPossibleAbsoluteUrl($raw);

        $driver = $this->getSession()->getDriver();

        $driver->getClient()->request('GET', $this->locatePath($path), [], [], $server);
    }

    private function requestPathFromPossibleAbsoluteUrl(string $url): string
    {
        if (\preg_match('~^https?://[^/]+(/[^?#]*)(\?[^#]*)?~', $url, $m)) {
            return $m[1] . ($m[2] ?? '');
        }

        return $url;
    }

    private function httpHeaderToServerKey(string $name): string
    {
        $normalized = \strtoupper(\str_replace('-', '_', $name));

        return \str_starts_with($normalized, 'HTTP_') ? $normalized : 'HTTP_' . $normalized;
    }

    private function getLastResponseHeader(string $headerName): ?string
    {
        $driver = $this->getSession()->getDriver();

        $response = $driver->getClient()->getResponse();

        if ($response instanceof BrowserKitResponse) {
            $value = $response->getHeader($headerName);

            return \is_string($value) ? $value : null;
        }

        if ($response instanceof HttpFoundationResponse) {
            return $response->headers->get($headerName);
        }

        return null;
    }
}
