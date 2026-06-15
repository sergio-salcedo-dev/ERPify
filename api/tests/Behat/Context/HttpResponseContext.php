<?php

declare(strict_types=1);

namespace Erpify\Tests\Behat\Context;

use Behat\Gherkin\Node\PyStringNode;
use Behat\Step\Then;
use DateTime;
use Erpify\Tests\Behat\Context\Abstraction\AbstractContext;
use Erpify\Tests\Behat\State\HttpResponseContainer;
use Erpify\Tests\Behat\Support\Transport\HttpResponseAwareTrait;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Uid\Uuid;

/**
 * Assertions over the last HTTP response: status code, body, headers and streaming,
 * plus response-oriented debug steps. Request building lives in {@see HttpRequestContext}.
 *
 * @SuppressWarnings("PHPMD.TooManyPublicMethods")
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 */
class HttpResponseContext extends AbstractContext
{
    use HttpResponseAwareTrait;

    private const string HEADER_NOT_NULL_MESSAGE = 'The header "%s" should not be null';

    public function __construct(
        protected readonly HttpResponseContainer $httpResponseContainer,
        protected ?int $serverPort = null,
    ) {
    }

    /**
     * Validate that the response code is matching `responseCode`.
     */
    #[Then('the response status code should be :responseCode')]
    public function theResponseStatusCodeShouldBe(int $responseCode): void
    {
        $response = $this->getLastResponse();

        self::assertEquals(
            $response->getStatusCode(),
            $responseCode,
            \sprintf(
                'Response status code is %d, expected was %d',
                $response->getStatusCode(),
                $responseCode,
            ),
        );
    }

    /**
     * Validate that the response code is not matching `responseCode`.
     */
    #[Then('/^the response status code should not be (?P<responseCode>\d+)$/')]
    public function thenTheResponseCodeShouldNotBe(int $responseCode): void
    {
        $response = $this->getLastResponse();

        self::assertNotEquals(
            $response->getStatusCode(),
            $responseCode,
            \sprintf(
                'Response status code is %d but should not',
                $response->getStatusCode(),
            ),
        );
    }

    /**
     * Checks whether the response content is equal to the given text.
     */
    #[Then('the response should be equal to')]
    #[Then('the response should be equal to:')]
    public function theResponseShouldBeEqualTo(PyStringNode $pyStringNode): void
    {
        $response = $this->getLastResponse();

        $pyStringNode = \str_replace('\"', '"', $pyStringNode->getRaw());
        $actual = $response->getContent();

        self::assertEquals(
            $pyStringNode,
            $actual,
            \sprintf('Actual response is "%s", but expected "%s"', $actual, $pyStringNode),
        );
    }

    /**
     * Checks whether the header name is equal to the given text.
     */
    #[Then('the header :name should be equal to :value')]
    public function theHeaderShouldBeEqualTo(string $name, string $value): void
    {
        $response = $this->getLastResponse();

        $actual = $response->headers->get($name);
        self::assertNotNull($actual, \sprintf(self::HEADER_NOT_NULL_MESSAGE, $name));
        self::assertEquals(
            \strtolower($value),
            \strtolower($actual),
            \sprintf('The header "%s" should not be equal to "%s", but it is: "%s"', $name, $value, $actual),
        );
    }

    /**
     * Checks whether the header name is not equal to the given text.
     */
    #[Then('the header :name should not be equal to :value')]
    public function theHeaderShouldNotBeEqualTo(string $name, string $value): void
    {
        $response = $this->getLastResponse();

        $actual = $response->headers->get($name);
        self::assertNotNull($actual, \sprintf(self::HEADER_NOT_NULL_MESSAGE, $name));
        self::assertNotEquals(
            \strtolower($value),
            \strtolower($actual),
            \sprintf('The header "%s" should not be equal to "%s", but it is: "%s"', $name, $value, $actual),
        );
    }

    /**
     * Checks whether the header name contains the given text.
     */
    #[Then('the header :name should contain :value')]
    public function theHeaderShouldContain(string $name, string $value): void
    {
        $response = $this->getLastResponse();

        $actual = $response->headers->get($name);
        self::assertNotNull($actual, \sprintf(self::HEADER_NOT_NULL_MESSAGE, $name));
        self::assertStringContainsStringIgnoringCase(
            $value,
            $actual,
            \sprintf('The header "%s" should contain value "%s", but it is: "%s"', $name, $value, $actual),
        );
    }

    /**
     * Checks whether the header name does not contain the given text.
     */
    #[Then('the header :name should not contain :value')]
    public function theHeaderShouldNotContain(string $name, string $value): void
    {
        $response = $this->getLastResponse();

        $actual = $response->headers->get($name);
        self::assertNotNull(
            $actual,
            \sprintf("The header '%s' should be equal to '%s', but it is: '%s'", $name, $value, $actual),
        );
        self::assertStringNotContainsStringIgnoringCase(
            $value,
            $actual,
            \sprintf('The header "%s" should contain value "%s", but it is: "%s"', $name, $value, $actual),
        );
    }

    /**
     * Checks whether the response content is null or empty string.
     */
    #[Then('the response should be empty')]
    public function theResponseShouldBeEmpty(): void
    {
        $response = $this->getLastResponse();

        self::assertEmpty(
            $response->getContent(),
            \sprintf('The response of the current page is not empty, it is: %s', $response->getContent()),
        );
    }

    /**
     * Checks whether the response content is null or empty string.
     */
    #[Then('the header :name should exist')]
    public function theHeaderShouldExist(string $name): void
    {
        $response = $this->getLastResponse();

        $actual = $response->headers->get($name);
        self::assertNotNull(
            $actual,
            \sprintf('The header %s does not exists', $response->getContent()),
        );
    }

    /**
     * Checks whether the response content is null or empty string.
     */
    #[Then('the header :name should not exist')]
    public function theHeaderShouldNotExist(string $name): void
    {
        $response = $this->getLastResponse();

        $actual = $response->headers->get($name);
        self::assertNull(
            $actual,
            \sprintf('The header %s does not exists', $response->getContent()),
        );
    }

    /**
     * Check that the response header `name` match the given `regex`.
     */
    #[Then('the header :name should match :regex')]
    public function theHeaderShouldMatch(string $name, string $regex): void
    {
        $response = $this->getLastResponse();

        $actual = $response->headers->get($name);

        self::assertNotNull($actual);
        self::assertMatchesRegularExpression(
            $regex,
            $actual,
            \sprintf('The header "%s" should match "%s", but it is: "%s"', $name, $regex, $actual),
        );
    }

    /**
     * Check that the response header `name` is a valid RFC 9562 UUID — any version when `version` is
     * omitted, or that exact version otherwise (e.g. the UUID v7 `X-Correlation-Id` minted via
     * {@see \Erpify\Shared\Domain\Uuid\Uuid::generate()}). `Uuid::isValid()` checks format and variant
     * in one pass — stricter than a regex.
     */
    #[Then('the header :name should be a valid UUID')]
    #[Then('the header :name should be a valid UUID version :version')]
    public function theHeaderShouldBeAValidUuid(string $name, ?string $version = null): void
    {
        $response = $this->getLastResponse();

        $actual = $response->headers->get($name);

        self::assertNotNull($actual, \sprintf(self::HEADER_NOT_NULL_MESSAGE, $name));
        self::assertTrue(
            Uuid::isValid($actual),
            \sprintf('The header "%s" should be a valid UUID, but it is: "%s"', $name, $actual),
        );

        if (null === $version) {
            return;
        }

        // Uuid::fromString() resolves the version-specific subclass (UuidV7, …); comparing its class
        // pins the version through the library rather than by parsing offsets by hand.
        self::assertSame(
            Uuid::fromString($actual)::class,
            \sprintf('Symfony\Component\Uid\UuidV%s', $version),
            \sprintf('The header "%s" should be a UUID v%s, but it is: "%s"', $name, $version, $actual),
        );
    }

    /**
     * Check that the response header `name` does not match the given `regex`.
     */
    #[Then('the header :name should not match :regex')]
    public function theHeaderShouldNotMatch(string $name, string $regex): void
    {
        $response = $this->getLastResponse();

        $actual = $response->headers->get($name);

        self::assertNotNull($actual);
        self::assertDoesNotMatchRegularExpression(
            $regex,
            $actual,
            \sprintf('The header "%s" should not match "%s", but it is: "%s"', $name, $regex, $actual),
        );
    }

    /**
     * Checks that the response header expires is in the future.
     */
    #[Then('the response should expire in the future')]
    public function theResponseShouldExpireInTheFuture(): void
    {
        $response = $this->getLastResponse();

        $this->theHeaderShouldExist('Date');
        $this->theHeaderShouldExist('Expires');

        $dateRaw = (string) $response->headers->get('Date');
        $expiresRaw = (string) $response->headers->get('Expires');

        $date = new DateTime($dateRaw);
        $expires = new DateTime($expiresRaw);

        self::assertTrue(
            (bool) $expires->diff($date)->invert,
            \sprintf("The response doesn't expire in the future (%s)", $expires->format(DATE_ATOM)),
        );
    }

    /**
     * Validate that the response is properly encoded.
     */
    #[Then('the response should be encoded in :encoding')]
    public function theResponseShouldBeEncodedIn(string $encoding): void
    {
        self::assertEquals(
            $encoding,
            $this->getLastResponse()->getCharset(),
            \sprintf('The response is not encoded in %s', $encoding),
        );
    }

    /**
     * Validate that the response is a streamed one.
     */
    #[Then('the response should be streamed')]
    public function theResponseShouldBeStreamed(): void
    {
        self::assertInstanceOf(
            StreamedResponse::class,
            $this->getLastResponse(),
            'The response is not streamed',
        );
    }

    /**
     * Validate that the response is a not streamed one.
     */
    #[Then('the response should not be streamed')]
    public function theResponseShouldNotBeStreamed(): void
    {
        self::assertNotInstanceOf(
            StreamedResponse::class,
            $this->getLastResponse(),
            'The response is streamed',
        );
    }

    /**
     * Validate that the response contains a given string.
     */
    #[Then('the response should contain :expected')]
    public function theResponseShouldContain(string $expected): void
    {
        $content = $this->getLastResponse()->getContent();

        if (false === $content) {
            self::fail('Last response does not have content');
        }

        self::assertStringContainsString(
            $expected,
            $content,
            \sprintf('The response does not contains "%s"', $expected),
        );
    }

    /**
     * Validate that the response does not contain given string.
     */
    #[Then('the response should not contain :expected')]
    public function theResponseShouldNotContain(string $expected): void
    {
        $content = $this->getLastResponse()->getContent();

        if (false === $content) {
            self::fail('Last response does not have content');
        }

        self::assertStringNotContainsString(
            $expected,
            $content,
            \sprintf('The response contains "%s", but should not', $expected),
        );
    }

    /**
     * print last request headers.
     */
    #[Then('print last response headers')]
    public function printLastResponseHeaders(): void
    {
        echo \implode(PHP_EOL, $this->getLastResponseHeaders());
    }

    /**
     * Debug scenario to print the web profiler link.
     */
    #[Then('print the web profiler link')]
    public function printTheWebProfilerLink(): void
    {
        /** @var string|null $link */
        $link = $this->getLastResponseHeaders()['x-debug-token-link'] ?? null;

        if (null === $link) {
            self::fail(
                'No web profiler token on the last response. Add the '
                . '"Given I enable the web profiler" step before the request you want to inspect.',
            );
        }

        if (null === $this->serverPort) {
            self::fail('Add $serverPort to the HttpResponseContext arguments in config/services_behat.yaml');
        }

        echo \str_replace('http://localhost', \sprintf('http://localhost:%s', $this->serverPort), $link);
    }
}
