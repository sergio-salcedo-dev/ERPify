<?php

declare(strict_types=1);

namespace Erpify\Tests\Behat\Context;

use Behat\Gherkin\Node\PyStringNode;
use Behat\Gherkin\Node\TableNode;
use Behat\Step\Given;
use Behat\Step\Then;
use DateTime;
use DateTimeInterface;
use Erpify\Shared\Infrastructure\Serializer\JsonDecoder;
use Erpify\Tests\Behat\Context\Abstraction\AbstractContext;
use Erpify\Tests\Behat\State\HttpResponseContainer;
use Erpify\Tests\Behat\Support\Transport\HttpResponse;
use Faker\Provider\Lorem;
use JsonException;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * This context gives the capability to make http requests and check responses.
 *
 * @SuppressWarnings("PHPMD.TooManyMethods")
 * @SuppressWarnings("PHPMD.TooManyPublicMethods")
 * @SuppressWarnings("PHPMD.ExcessiveClassComplexity")
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 * @SuppressWarnings("PHPMD.ExcessiveClassLength")
 */
class HttpRequestContext extends AbstractContext
{
    protected ?KernelBrowser $client = null;

    protected array $headers = [];

    public function __construct(
        #[Autowire(service: 'test.service_container')]
        protected readonly Container $container,
        protected readonly HttpResponseContainer $httpResponseContainer,
        protected ?string $baseUrl = null,
        protected ?int $serverPort = null,
    ) {
    }

    public function setBaseUrl(?string $baseUrl): void
    {
        $this->baseUrl = $baseUrl;
    }

    public function getClient(): KernelBrowser
    {
        if (!$this->client instanceof KernelBrowser) {
            /** @var KernelBrowser $client */
            $client = $this->container->get('test.client');
            $this->client = $client;
        }

        return $this->client;
    }

    public function getLastResponse(): Response
    {
        $result = $this->httpResponseContainer->getResult();
        self::assertNotNull($result, 'You cannot call this method without a request made previously');

        /** @var Response $response */
        $response = $result->getValue();

        return $response;
    }

    public function getLastRequest(): Request
    {
        $result = $this->httpResponseContainer->getResult();
        self::assertNotNull($result, 'You cannot call this method without a request made previously');

        return $this->getClient()->getRequest();
    }

    public function replaceExpression(string $body, string $expression, callable $action): string
    {
        $matches = null;
        \preg_match_all('/"' . $expression . ':(.*)"/', $body, $matches);

        $iterations = \count($matches[0]);

        for ($i = 0; $i < $iterations; ++$i) {
            $body = \str_replace(
                $matches[0][$i],
                '"' . $action($matches[1][$i]) . '"',
                $body,
            );
        }

        return $body;
    }

    public function getLastResponseHeaders(): array
    {
        $output = [];

        /**
         * @var string            $name
         * @var list<string|null> $values
         */
        foreach ($this->getLastResponse()->headers->all() as $name => $values) {
            $output[$name] = $values[0];
        }

        return $output;
    }

    public function getLastRequestCurlCommand(): string
    {
        $request = $this->getLastRequest();

        $method = $request->getMethod();
        $url = $request->getUri();

        $headers = '';

        foreach ($request->headers as $name => $value) {
            if (!\str_starts_with($name, 'HTTP_') && 'HTTPS' !== $name) {
                $headers .= \sprintf(" -H '%s: %s'", $name, $value[0]);
            }
        }

        $data = '';

        if ('' !== $request->getContent()) {
            $data = \sprintf(" --data '%s'", $request->getContent());
        }

        return \sprintf("curl -X %s%s%s '%s'", $method, $data, $headers, $url);
    }

    // GIVEN SCENARIOS
    /**
     * Send a simple HTTP Request to an uri.
     *
     * @SuppressWarnings("PHPMD.UnusedFormalParameters")
     */
    #[Given('I send a :method request to :url')]
    public function iSendARequestTo(
        string $method,
        string $url,
        ?TableNode $tableNode = null,
        ?PyStringNode $body = null,
        array $files = [],
    ): void {
        $parameters = [];

        if ($tableNode instanceof TableNode) {
            foreach ($tableNode->getHash() as $row) {
                self::assertArrayHasKey('key', $row, "You must provide a 'key' and 'value' column in your table node.");
                self::assertArrayHasKey(
                    'value',
                    $row,
                    "You must provide a 'key' and 'value' column in your table node.",
                );
                $parameters[$row['key']] = $row['value'];

                if (1 === \preg_match('/(date:([^&]+))&?/', $row['value'], $matches)) {
                    $parameters[$row['key']] = (new DateTime($matches[0][0]))->format(DateTimeInterface::ATOM);
                }
            }
        }

        $headers = [];

        foreach ($this->headers as $name => $value) {
            if ('content-type' === \strtolower((string) $name)) {
                $headers[\strtoupper(\str_ireplace('-', '_', $name))] = $value;

                continue;
            }

            $headers['HTTP_' . \strtoupper(\str_ireplace('-', '_', $name))] = $value;
        }

        $requestUrl = $url;

        if (!\str_starts_with($requestUrl, 'http')) {
            $requestUrl = (\rtrim($this->baseUrl ?? '', '/') . '/' . \ltrim($url, '/'));
        }

        \ob_start();
        $this->getClient()->request(
            method: $method,
            uri: $requestUrl,
            parameters: $parameters,
            files: $files,
            server: $headers,
            content: $body?->getRaw(),
        );
        $streamedResult = \ob_get_clean();
        \ob_flush();

        $this->httpResponseContainer->store(
            new HttpResponse(
                $this->getClient()->getResponse(),
                (string) $streamedResult,
            ),
        );
    }

    /**
     * Sends an HTTP request with parameters.
     */
    #[Given('I send a :method request to :url with parameters:')]
    public function iSendARequestToWithParameters(string $method, string $url, TableNode $tableNode): void
    {
        $this->iSendARequestTo(
            $method,
            $url,
            $tableNode,
        );
    }

    /**
     * Sends an HTTP request with a body.
     */
    #[Given('I send a :method request to :url with body:')]
    public function iSendARequestToWithBody(string $method, string $url, PyStringNode $pyStringNode): void
    {
        $this->iSendARequestTo($method, $url, body: $pyStringNode);
    }

    /**
     * Sends an HTTP request with a body having expressions that will be replaced by generated values :
     * * date: Create a new DateTime object and format it properly (ex: `date:now` will generate a now date)
     * * randStr: Create a new string with random characters (ex: `randStr:256` will generate 256 characters)
     */
    #[Given('I send a :method request to :url with body and expressions:')]
    #[Given('I send a :method request to :url with body and relative dates:')]
    public function iSendARequestWithBodyAndExpressions(string $method, string $url, PyStringNode $pyStringNode): void
    {
        $expressions = [
            'date' => static fn (string $match): string => (new DateTime($match))->format(DateTimeInterface::ATOM),
            'randStr' => static function (string $match): string {
                $str = '';

                for ($i = 0; $i < (int) $match; ++$i) {
                    $str .= Lorem::randomLetter();
                }

                return $str;
            },
        ];

        $bodyContent = $pyStringNode->getRaw();

        foreach ($expressions as $expression => $callback) {
            $bodyContent = $this->replaceExpression($bodyContent, $expression, $callback);
        }

        $this->iSendARequestToWithBody($method, $url, new PyStringNode([$bodyContent], 0));
    }

    /**
     * Sends an HTTP request with a query parameters and expressions that will be replaced by generated values :
     * * date: Create a new DateTime object and format it properly (ex: `date:now` will generate a now date)
     */
    #[Given('I send a :method request to :url with query params and relative dates')]
    public function iSendARequestWithQueryParamsAndRelativeDates(string $method, string $url): void
    {
        $matches = null;
        \preg_match_all('/(date:([^&]+))&?/', $url, $matches);

        $max = \count($matches[0]);

        if (0 < $max) {
            for ($i = 0; $i < $max; ++$i) {
                $date = \urlencode((new DateTime($matches[2][$i]))->format(DateTimeInterface::ATOM));
                $url = \str_replace($matches[1][$i], $date, $url);
            }
        }

        $this->iSendARequestTo($method, $url);
    }

    /**
     * Sends a HTTP request with a some parameters.
     */
    #[Given('I send a :method request to :url with parameters and relative dates:')]
    public function iSendARequestToWithParametersAndRelativeDates(string $method, string $url, TableNode $tableNode): void
    {
        $this->iSendARequestToWithParameters($method, $url, $tableNode);
    }

    /**
     * Then a new request using content of the previous one.
     *
     * @throws JsonException
     */
    #[Given('I send a :method request to :url using last response with body:')]
    public function iSendARequestToWithParametersUsingLastResponse(string $method, string $url, PyStringNode $pyStringNode): void
    {
        $lastResponse = JsonDecoder::decodeArray((string) $this->getLastResponse()->getContent())['data'];

        foreach (\explode('/', $url) as $value) {
            if (\str_starts_with($value, ':')) {
                $url = \str_replace($value, $lastResponse[\str_replace(':', '', $value)], $url);
            }
        }

        $this->iSendARequestTo($method, $url, body: $pyStringNode);
    }

    // THEN SCENARIOS

    /**
     * Add a header element in a request.
     */
    #[Then('I add :name header equal to :value')]
    public function iAddHeaderEqualTo(string $name, mixed $value): void
    {
        $this->headers[$name] = $value;
    }

    /**
     * Remove a header element in a request.
     */
    #[Then('I remove :name header')]
    public function iRemoveHeaderNamed(string $name): void
    {
        if (isset($this->headers[$name])) {
            unset($this->headers[$name]);
        }
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
        self::assertNotNull($actual, \sprintf('The header "%s" should not be null', $name));
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
        self::assertNotNull($actual, \sprintf('The header "%s" should not be null', $name));
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
        self::assertNotNull($actual, \sprintf('The header "%s" should not be null', $name));
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
        self::assertNotNull($actual, \sprintf("The header '%s' should be equal to '%s', but it is: '%s'", $name, $value, $actual));
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

    // DEBUG SCENARIOS
    /**
     * print last request headers.
     */
    #[Then('print last response headers')]
    public function printLastResponseHeaders(): void
    {
        echo \implode(PHP_EOL, $this->getLastResponseHeaders());
    }

    /**
     * print last request curl command.
     */
    #[Then('print the corresponding curl command')]
    public function printTheCorrespondingCurlCommand(): void
    {
        echo $this->getLastRequestCurlCommand();
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
            self::fail('Web profiler bundle not configured correctly. Enable `toolbar: true`.');
        }

        if (null === $this->serverPort) {
            self::fail('Add server port in your config/services_test.yaml');
        }

        echo \str_replace('http://localhost', \sprintf('http://localhost:%s', $this->serverPort), $link);
    }
}
