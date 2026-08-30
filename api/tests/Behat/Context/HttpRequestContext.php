<?php

declare(strict_types=1);

namespace Erpify\Tests\Behat\Context;

use Behat\Gherkin\Node\PyStringNode;
use Behat\Gherkin\Node\TableNode;
use Behat\Step\Given;
use Behat\Step\Then;
use DateTime;
use DateTimeInterface;
use Erpify\Shared\Serialization\Infrastructure\JsonDecoder;
use Erpify\Tests\Behat\Context\Abstraction\AbstractContext;
use Erpify\Tests\Behat\State\HttpResponseContainer;
use Erpify\Tests\Behat\Support\Transport\HttpResponse;
use Erpify\Tests\Behat\Support\Transport\HttpResponseAwareTrait;
use Faker\Provider\Lorem;
use JsonException;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\Request;
use UnexpectedValueException;

/**
 * Builds and sends HTTP requests against the kernel and manages request headers for Behat scenarios.
 *
 * Response assertions live in {@see HttpResponseContext}.
 *
 * @SuppressWarnings("PHPMD.TooManyPublicMethods")
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 * @SuppressWarnings("PHPMD.ExcessiveClassComplexity")
 * @SuppressWarnings("PHPMD.ExcessiveClassLength")
 */
class HttpRequestContext extends AbstractContext
{
    use HttpResponseAwareTrait;

    protected ?KernelBrowser $client = null;

    protected array $headers = [];

    public function __construct(
        #[Autowire(service: 'test.service_container')]
        protected readonly Container $container,
        protected readonly HttpResponseContainer $httpResponseContainer,
        protected ?string $baseUrl = null,
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
     * Builds a `filters[0][...]` generic in-filter totalling N values — N−1 generated plus the
     * given real one — so boundary scenarios (e.g. 1 in-filter × 100 values) do not need a
     * kilometre-long literal URL in the feature file.
     */
    #[Given('I send a :method request to :url with a :field in-filter of :count values, the last being :real')]
    public function iSendARequestToWithAGeneratedInFilter(
        string $method,
        string $url,
        string $field,
        int $count,
        string $real,
    ): void {
        $queryParts = [
            'filters[0][field]=' . \rawurlencode($field),
            'filters[0][operator]=in',
        ];

        for ($i = 1; $i < $count; ++$i) {
            $queryParts[] = 'filters[0][value][]=' . \rawurlencode(\sprintf('generated-%03d', $i));
        }

        $queryParts[] = 'filters[0][value][]=' . \rawurlencode($real);

        $separator = \str_contains($url, '?') ? '&' : '?';

        $this->iSendARequestTo($method, $url . $separator . \implode('&', $queryParts));
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
    public function iSendARequestToWithParametersAndRelativeDates(
        string $method,
        string $url,
        TableNode $tableNode,
    ): void {
        $this->iSendARequestToWithParameters($method, $url, $tableNode);
    }

    /**
     * Then a new request using content of the previous one.
     *
     * The `:key` placeholders are looked up in the previous response's `data` node, which must
     * be an associative object (single resource) — not the list-shaped search envelope where
     * `data` is an array.
     *
     * @throws JsonException
     */
    #[Given('I send a :method request to :url using last response with body:')]
    public function iSendARequestToWithParametersUsingLastResponse(
        string $method,
        string $url,
        PyStringNode $pyStringNode,
    ): void {
        $lastResponse = JsonDecoder::decodeArray((string) $this->getLastResponse()->getContent())['data'];

        foreach (\explode('/', $url) as $value) {
            if (\str_starts_with($value, ':')) {
                $url = \str_replace($value, $lastResponse[\str_replace(':', '', $value)], $url);
            }
        }

        $this->iSendARequestTo($method, $url, body: $pyStringNode);
    }

    /**
     * Issue a request to `$url` after substituting `{value}` with the scalar JSON node
     * `$node` from the previous response. Useful for following pagination cursors,
     * resource ids, or any other value embedded in the previous response.
     *
     * Example: `When I send a "GET" request to "/backoffice/banks?cursor={value}"
     *           using the JSON node "pagination.cursor" from the previous response`
     *
     * @throws JsonException
     */
    #[Given('I send a :method request to :url using the JSON node :node from the previous response')]
    public function iSendARequestUsingJsonNodeFromPreviousResponse(string $method, string $url, string $node): void
    {
        $value = $this->jsonNodeFromPreviousResponse($node);

        if (!\is_scalar($value)) {
            throw new UnexpectedValueException(
                \sprintf('JSON node "%s" is not a scalar (got %s).', $node, \get_debug_type($value)),
            );
        }

        $this->iSendARequestTo($method, \str_replace('{value}', \rawurlencode((string) $value), $url));
    }

    /**
     * Follow a server-supplied navigation link VERBATIM (cursor-only pagination, W11): JSON node
     * `$node` (e.g. `pagination.links.next`) is a complete relative path already carrying the opaque
     * cursor and preserved params — navigated AS-IS, never decoded/re-encoded/rebuilt. It includes
     * the full route path (`/api/v1/...`), so `baseUrl` is NOT re-applied (sent host-relative). A
     * `null` link is unfollowable — the step fails loudly rather than degrade silently.
     *
     * @throws JsonException
     */
    #[Given('I follow the :node link from the previous response')]
    public function iFollowTheLinkFromThePreviousResponse(string $node): void
    {
        $this->iSendARequestTo('GET', 'http://localhost' . $this->followableLinkFromPreviousResponse($node));
    }

    /**
     * Follow a navigation link as above, but with EXACTLY ONE query param's value replaced.
     * Every other param — the opaque `after`/`before` cursor included — and the path travel
     * byte-identical: the query string is never decoded and rebuilt as a whole, so a rejection
     * of the resulting request provably targets the overridden-param MISMATCH (e.g. a cursor
     * fingerprint minted under a different `sort`), never accidental cursor corruption.
     */
    #[Given('I follow the :node link from the previous response overriding the :param query param with :value')]
    public function iFollowTheLinkFromThePreviousResponseOverridingAQueryParam(
        string $node,
        string $param,
        string $value,
    ): void {
        $link = $this->followableLinkFromPreviousResponse($node);

        $this->iSendARequestTo('GET', 'http://localhost' . $this->withOverriddenQueryParam($link, $param, $value));
    }

    /**
     * Follow a navigation link's query string — its opaque `after`/`before` cursor and every preserved
     * param — VERBATIM, but rebased onto a DIFFERENT route path. Models a client replaying a cursor
     * minted on one route against another route over the same aggregate root: the base-query
     * discriminant must reject it (422 `invalid-cursor`) instead of paginating a foreign scope. The
     * query string is spliced whole, never decoded/rebuilt, so a rejection provably targets the
     * cross-route base-query MISMATCH, not accidental cursor corruption. The path is host-relative
     * (`baseUrl` is applied by {@see iSendARequestTo}), so callers write it like any other request URL.
     */
    #[Given('I follow the :node link from the previous response rebased onto :path')]
    public function iFollowTheLinkFromThePreviousResponseRebasedOntoPath(string $node, string $path): void
    {
        $link = $this->followableLinkFromPreviousResponse($node);
        $separatorPosition = \strpos($link, '?');

        if (false === $separatorPosition) {
            throw new UnexpectedValueException(
                \sprintf('Link "%s" carries no query string (cursor) to rebase onto "%s".', $link, $path),
            );
        }

        $this->iSendARequestTo('GET', $path . \substr($link, $separatorPosition));
    }

    /**
     * Resolves JSON node `$node` from the previous response and validates it is a followable
     * (non-empty string) link. Shared by the verbatim and the param-overriding follow steps.
     */
    private function followableLinkFromPreviousResponse(string $node): string
    {
        $value = $this->jsonNodeFromPreviousResponse($node);

        if (!\is_string($value) || '' === $value) {
            throw new UnexpectedValueException(
                \sprintf('JSON node "%s" is not a followable link (got %s).', $node, \get_debug_type($value)),
            );
        }

        return $value;
    }

    /**
     * Replaces the value of every occurrence of `$param` in the link's raw query string,
     * splitting only on `&` and the first `=` of each pair so all other pairs keep their
     * original bytes. A link without the param fails loudly — silently appending would test a
     * different request than the scenario states.
     */
    private function withOverriddenQueryParam(string $link, string $param, string $value): string
    {
        $separatorPosition = \strpos($link, '?');

        if (false === $separatorPosition) {
            throw new UnexpectedValueException(
                \sprintf('Link "%s" carries no query string to override "%s" in.', $link, $param),
            );
        }

        $pairs = \explode('&', \substr($link, $separatorPosition + 1));
        $overridden = false;

        foreach ($pairs as $index => $pair) {
            if (\explode('=', $pair, 2)[0] !== $param) {
                continue;
            }

            $pairs[$index] = $param . '=' . \rawurlencode($value);
            $overridden = true;
        }

        if (!$overridden) {
            throw new UnexpectedValueException(
                \sprintf('Query param "%s" not found in link "%s".', $param, $link),
            );
        }

        return \substr($link, 0, $separatorPosition + 1) . \implode('&', $pairs);
    }

    /**
     * Resolve a (possibly nested, dot-separated) JSON node from the previous response's decoded
     * body — e.g. `pagination.links.next`. Throws if any path segment is missing. Shared by the
     * cursor-substitution and link-following steps above.
     *
     * @throws JsonException
     */
    private function jsonNodeFromPreviousResponse(string $node): mixed
    {
        $value = JsonDecoder::decodeArray((string) $this->getLastResponse()->getContent());

        foreach (\explode('.', $node) as $segment) {
            if (!\is_array($value) || !\array_key_exists($segment, $value)) {
                throw new UnexpectedValueException(
                    \sprintf('JSON node "%s" not found in the previous response.', $node),
                );
            }

            $value = $value[$segment];
        }

        return $value;
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
     * Captures a header off the LAST response and sends it back on the NEXT request, which is the whole
     * conditional-GET loop in one step.
     *
     * It exists rather than a literal because the value it carries — an entity tag, a `Location`, a
     * `Last-Modified` — is a property of the fixture and not of the contract, and writing it out would pin
     * the first into the second. And it does not reuse the equality step's comparison: that one lower-cases
     * both sides, which cannot tell `W/"abc"` from `w/"ABC"`, while a validator has to travel byte for byte.
     */
    #[Then('I add :name header equal to the response header :header')]
    public function iAddHeaderEqualToTheResponseHeader(string $name, string $header): void
    {
        $value = $this->getLastResponse()->headers->get($header);

        self::assertNotNull($value, \sprintf('The last response carries no "%s" header to send back.', $header));

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

    // DEBUG SCENARIOS
    /**
     * Opt the next request into profiler collection so {@see HttpResponseContext::printTheWebProfilerLink}
     * has a token to resolve. The test kernel does not collect profiles by default (web_profiler.yaml
     * sets `collect: false`); place this step before the request you want to inspect.
     */
    #[Given('I enable the web profiler')]
    public function iEnableTheWebProfiler(): void
    {
        $this->getClient()->enableProfiler();
    }

    /**
     * print last request curl command.
     */
    #[Then('print the corresponding curl command')]
    public function printTheCorrespondingCurlCommand(): void
    {
        echo $this->getLastRequestCurlCommand();
    }
}
