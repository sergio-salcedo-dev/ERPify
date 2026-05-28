<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Shared\Infrastructure\Http;

use JsonException;
use JsonSchema\Validator as JsonSchemaValidator;
use PHPUnit\Framework\Attributes\CoversNothing;
use stdClass;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouterInterface;

/**
 * `/api/*` integration sweep with RFC 9457 schema validation.
 *
 * This test discovers every route whose path starts with `/api/` via
 * {@see RouterInterface::getRouteCollection()}, triggers an error condition for each one
 * (default: a wrong HTTP method to provoke `MethodNotAllowedHttpException`, which is raised
 * by Symfony's `RouterListener` BEFORE the controller runs and so reliably exercises the
 * {@see \Erpify\Shared\Infrastructure\Http\EventListener\ExceptionResponder} listener
 * regardless of controller-local error handling), and asserts that every resulting response
 * conforms to the RFC 9457 Problem Details contract.
 *
 * Per route, the response MUST:
 *   - parse as JSON;
 *   - validate against the bundled `tests/Fixtures/Problem/rfc-9457.schema.json` schema;
 *   - carry `Content-Type: application/problem+json`;
 *   - carry `Cache-Control: no-store`;
 *   - carry the `X-Correlation-Id` response header (a valid UUIDv7);
 *   - carry a valid UUIDv7 `instance` member in the body;
 *   - return a non-2xx status (the trigger MUST surface an error path).
 *
 * Failures are aggregated across routes and reported as a single multi-line diagnostic so
 * developers can see at a glance which routes escape the contract.
 *
 * This sweep is a SAFETY NET — developers adding new endpoints are expected to write a
 * specific integration test for their own error paths. The sweep catches drift
 * the per-feature suites miss; it is not a substitute for them.
 *
 * @internal
 *
 * @SuppressWarnings("PHPMD.ExcessiveClassComplexity")
 * @SuppressWarnings("PHPMD.ExcessiveClassLength")
 */
#[CoversNothing]
final class ProblemDetailsApiSchemaSweepTest extends WebTestCase
{
    private const string UUID_V7_REGEX = '/\A[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/';

    private const string SCHEMA_FIXTURE = '/../../../../Fixtures/Problem/rfc-9457.schema.json';

    /**
     * The full universe of HTTP methods the router can advertise. The test picks an UNALLOWED
     * method per route to trigger `MethodNotAllowedHttpException`. Order is irrelevant; the
     * loop returns the first method that is NOT in the route's `methods` set.
     *
     * `OPTIONS` is intentionally excluded from the candidate list — NelmioCorsBundle handles
     * preflight `OPTIONS` requests on the request cycle and returns 204, which would NOT
     * trigger the exception listener.
     *
     * @var list<string>
     */
    private const array CANDIDATE_METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'];

    /**
     * Routes that legitimately escape the sweep with an explicit, documented reason. Keep
     * this list as close to empty as possible — every entry here is technical debt
     * expected to be retired. Each entry MUST carry a one-line rationale.
     *
     * Today the sweep's wrong-method trigger reaches every `/api/*` route through Symfony's
     * `RouterListener`, which raises `MethodNotAllowedHttpException` BEFORE controller code
     * runs and so guarantees a Problem Details body even on routes whose controllers still
     * emit legacy JSON:API bodies on their happy-error branches. No allowlist entries are
     * currently required.
     *
     * @var array<string, string>
     */
    private const array SWEEP_ALLOWLIST = [];

    /**
     * @SuppressWarnings("PHPMD.Superglobals")
     */
    public function testEveryApiRouteEmitsConformingProblemDetailsOnError(): void
    {
        if (!empty($_SERVER['SKIP_SWEEP']) || !empty($_ENV['SKIP_SWEEP'])) {
            $this->markTestSkipped('Sweep skipped via SKIP_SWEEP env var.');
        }

        $kernelBrowser = self::createClient();
        $kernelBrowser->catchExceptions(true);

        $router = self::getContainer()->get('router');
        $this->assertInstanceOf(RouterInterface::class, $router);

        $apiRoutes = $this->collectApiRoutes($router);

        $this->assertNotEmpty(
            $apiRoutes,
            'Sweep must discover at least one route under `/api/`. Empty result implies the '
            . 'router is misconfigured or the test environment did not load `routes.yaml`.',
        );

        // Build a path → set-of-allowed-methods map so the wrong-method trigger picks a
        // method not allowed by ANY route at that exact path. Without this, sibling routes
        // (e.g. `GET /banks/{id}` paired with `DELETE /banks/{id}`) would short-circuit the
        // 405 trigger by matching a different route at the same URI.
        $methodsByPath = $this->indexAllowedMethodsByPath($apiRoutes);

        $schemaRef = $this->loadSchemaRef();
        /** @var list<string> $diagnostics */
        $diagnostics = [];

        /** @var array<string, string> $allowlist */
        $allowlist = self::SWEEP_ALLOWLIST;

        foreach ($apiRoutes as $routeName => $route) {
            if (\array_key_exists($routeName, $allowlist)) {
                continue;
            }

            $diagnostic = $this->checkRoute(
                $kernelBrowser,
                $routeName,
                $route,
                $schemaRef,
                $methodsByPath,
            );

            if (null !== $diagnostic) {
                $diagnostics[] = $diagnostic;
            }
        }

        if ([] === $diagnostics) {
            return;
        }

        $this->fail(
            "<<< Routes escaping the RFC 9457 Problem Details contract >>>\n"
            . \implode("\n", $diagnostics)
            . "\n\nRemediate at the controller / listener level — do NOT add to the sweep "
            . 'allowlist without a documented per-entry rationale (and a tracking link).',
        );
    }

    public function testRfc9457SchemaFixtureIsValidJsonSchema(): void
    {
        $schemaRef = $this->loadSchemaRef();

        $sample = (object) [
            'type' => 'about:blank',
            'title' => 'Internal Server Error',
            'status' => 500,
            'instance' => '0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1a2c',
            'correlation-id' => '0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1a2c',
        ];

        $validator = new JsonSchemaValidator();
        $validator->validate($sample, $schemaRef);

        $this->assertTrue(
            $validator->isValid(),
            'Bundled RFC 9457 schema fixture must accept a representative Problem Details body. '
            . 'Errors: ' . \json_encode($validator->getErrors()),
        );

        // Negative pin — a body missing `status` (required) MUST be rejected, proving the
        // schema is actually being consulted (and not a no-op pass-through).
        $missingStatus = (object) [
            'type' => 'about:blank',
            'title' => 'Internal Server Error',
        ];
        $missingValidator = new JsonSchemaValidator();
        $missingValidator->validate($missingStatus, $schemaRef);
        $this->assertFalse(
            $missingValidator->isValid(),
            'RFC 9457 schema fixture must reject a body missing the required `status` member.',
        );
    }

    /**
     * Sanity test: the synthetic wrong-method trigger used by the sweep actually exercises
     * `ExceptionResponder` end-to-end. Pins the assumption that `RouterListener` raises 405
     * BEFORE any controller-local error handling can short-circuit the listener — if Symfony
     * ever changes that ordering, the sweep would silently degrade.
     */
    public function testWrongMethodTriggerHitsExceptionResponderForKnownTestRoute(): void
    {
        $kernelBrowser = self::createClient();
        $kernelBrowser->catchExceptions(true);

        // `/api/test/_throw-not-found` is GET-only; POST must yield a 405 Problem Details
        // response built by the ExceptionResponder listener.
        $kernelBrowser->request(\Symfony\Component\HttpFoundation\Request::METHOD_POST, '/api/test/_throw-not-found');

        $response = $kernelBrowser->getResponse();
        $this->assertSame(
            Response::HTTP_METHOD_NOT_ALLOWED,
            $response->getStatusCode(),
            (string) $response->getContent(),
        );
        $this->assertSame('application/problem+json', $response->headers->get('Content-Type'));
    }

    /**
     * Aggregate the set of allowed HTTP methods across every route that shares a given
     * path. Multi-method controllers and method-split sibling controllers (e.g.
     * `GET /banks/{id}` vs `DELETE /banks/{id}`) both contribute. The wrong-method trigger
     * then picks a method not in the union, guaranteeing a `MethodNotAllowedHttpException`
     * regardless of which route in the path's set was originally targeted.
     *
     * @param array<string, Route> $apiRoutes
     *
     * @return array<string, list<string>>
     */
    private function indexAllowedMethodsByPath(array $apiRoutes): array
    {
        /** @var array<string, array<string, true>> $byPath */
        $byPath = [];

        foreach ($apiRoutes as $apiRoute) {
            $path = $apiRoute->getPath();

            foreach ($apiRoute->getMethods() as $method) {
                $byPath[$path][\strtoupper($method)] = true;
            }
        }

        $result = [];

        foreach ($byPath as $path => $methodSet) {
            $result[$path] = \array_keys($methodSet);
        }

        return $result;
    }

    /**
     * Discover all routes whose path starts with `/api/`. Sorted by name for stable
     * diagnostic output across runs.
     *
     * @return array<string, Route>
     */
    private function collectApiRoutes(RouterInterface $router): array
    {
        $apiRoutes = [];

        foreach ($router->getRouteCollection()->all() as $name => $route) {
            $path = $route->getPath();

            if (!\str_starts_with($path, '/api/')) {
                continue;
            }

            $apiRoutes[$name] = $route;
        }

        \ksort($apiRoutes);

        return $apiRoutes;
    }

    /**
     * Run the per-route sweep against the kernel browser. Returns `null` on success or a
     * formatted multi-line diagnostic string listing every contract violation observed.
     *
     * @param array<string, list<string>> $methodsByPath
     *
     * @SuppressWarnings("PHPMD.CyclomaticComplexity")
     * @SuppressWarnings("PHPMD.NPathComplexity")
     * @SuppressWarnings("PHPMD.ExcessiveMethodLength")
     */
    private function checkRoute(
        KernelBrowser $kernelBrowser,
        string $routeName,
        Route $route,
        stdClass $schemaRef,
        array $methodsByPath,
    ): ?string {
        $path = $route->getPath();
        $methodsForPath = $methodsByPath[$path] ?? \array_values($route->getMethods());
        $method = $this->pickUnallowedMethod($methodsForPath);
        $uri = $this->buildSweepUri($route);

        $kernelBrowser->request($method, $uri);
        $response = $kernelBrowser->getResponse();

        /** @var list<string> $failures */
        $failures = [];

        $status = $response->getStatusCode();

        if ($status < 400 || $status >= 600) {
            $failures[] = \sprintf(
                'response status: %d (expected non-2xx error response)',
                $status,
            );
        }

        $contentType = $response->headers->get('Content-Type');

        if ('application/problem+json' !== $contentType) {
            $failures[] = \sprintf(
                'Content-Type: %s (expected: application/problem+json)',
                $this->renderHeader($contentType),
            );
        }

        $cacheControl = $response->headers->get('Cache-Control');

        if (null === $cacheControl || !\str_contains($cacheControl, 'no-store')) {
            $failures[] = \sprintf(
                'Cache-Control: %s (expected to contain: no-store)',
                $this->renderHeader($cacheControl),
            );
        }

        $correlationId = $response->headers->get('X-Correlation-Id');

        if (!\is_string($correlationId) || 1 !== \preg_match(self::UUID_V7_REGEX, $correlationId)) {
            $failures[] = \sprintf(
                'X-Correlation-Id: %s (expected: valid UUIDv7)',
                $this->renderHeader($correlationId),
            );
        }

        $decoded = $this->decodeProblemBody($response->getContent(), $failures);

        if (!$decoded instanceof stdClass) {
            return $this->formatDiagnostic($routeName, $method, $uri, $failures);
        }

        $instance = \property_exists($decoded, 'instance') ? $decoded->instance : null;

        if (!\is_string($instance) || 1 !== \preg_match(self::UUID_V7_REGEX, $instance)) {
            $failures[] = \sprintf(
                'body instance: %s (expected: valid UUIDv7)',
                $this->renderBodyValue($instance),
            );
        }

        $validator = new JsonSchemaValidator();
        $validator->validate($decoded, $schemaRef);

        if (!$validator->isValid()) {
            $failures[] = 'body fails RFC 9457 schema: ' . \implode('; ', $this->collectSchemaErrors($validator));
        }

        if ([] === $failures) {
            return null;
        }

        return $this->formatDiagnostic($routeName, $method, $uri, $failures);
    }

    /**
     * @param list<string> $failures
     */
    private function decodeProblemBody(string|false $rawBody, array &$failures): ?stdClass
    {
        if (!\is_string($rawBody) || '' === $rawBody) {
            $failures[] = 'response body was empty (expected JSON Problem Details body)';

            return null;
        }

        try {
            $decoded = \json_decode($rawBody, false, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $jsonException) {
            $failures[] = \sprintf('body is not valid JSON: %s', $jsonException->getMessage());

            return null;
        }

        if (!$decoded instanceof stdClass) {
            $failures[] = \sprintf(
                'body root is %s (expected: JSON object)',
                \get_debug_type($decoded),
            );

            $decoded = null;
        }

        return $decoded;
    }

    /**
     * @return list<string>
     */
    private function collectSchemaErrors(JsonSchemaValidator $validator): array
    {
        $errorBlobs = [];

        foreach ($validator->getErrors() as $error) {
            $property = '';
            $message = '';

            if (\is_array($error)) {
                $rawProperty = $error['property'] ?? '';
                $rawMessage = $error['message'] ?? '';
                $property = \is_string($rawProperty) ? $rawProperty : '';
                $message = \is_string($rawMessage) ? $rawMessage : '';
            }

            $errorBlobs[] = \sprintf('[%s] %s', $property, $message);
        }

        return $errorBlobs;
    }

    /**
     * Pick a method NOT in the route's allowlist so we trigger
     * `MethodNotAllowedHttpException`. If a route accepts every candidate method, we fall
     * back to a default GET; in practice this branch is unreachable for the project's
     * current routes (none accept all five methods).
     *
     * @param list<string> $allowedMethods
     */
    private function pickUnallowedMethod(array $allowedMethods): string
    {
        $allowedUpper = \array_map(strtoupper(...), $allowedMethods);

        foreach (self::CANDIDATE_METHODS as $candidate) {
            if (!\in_array($candidate, $allowedUpper, true)) {
                return $candidate;
            }
        }

        // Defensive: if a route truly accepted every candidate method, return GET — the
        // bare request is then expected to either 200 (caught by the status check above) or
        // fail one of the other contract assertions, surfacing a clear diagnostic.
        return 'GET';
    }

    /**
     * Build a concrete URI for the route by substituting any path parameters with values
     * that satisfy their `requirements` regex (when one is declared) or a deterministic
     * placeholder otherwise. The substitutions never need to resolve to existing records —
     * the wrong-method trigger short-circuits the controller.
     */
    private function buildSweepUri(Route $route): string
    {
        $path = $route->getPath();

        $requirements = $route->getRequirements();

        $callback = static function (array $matches) use ($requirements): string {
            $name = isset($matches[1]) && \is_string($matches[1]) ? $matches[1] : '';

            if ('' === $name) {
                return 'sweep-placeholder';
            }

            $requirement = $requirements[$name] ?? null;

            // Hash-shaped param (e.g. `[a-f0-9]{64}`) → 64 zero bytes.
            if (\is_string($requirement) && \str_contains($requirement, '[a-f0-9]')) {
                return \str_repeat('0', 64);
            }

            // Numeric requirement → 0.
            if (\is_string($requirement) && \str_contains($requirement, '\d')) {
                return '0';
            }

            // Default: a deterministic placeholder. We do not need it to validate as a UUID —
            // a wrong-method trigger short-circuits before any controller-side validation.
            return 'sweep-placeholder';
        };

        $rewritten = \preg_replace_callback('/\{(\w+)\}/', $callback, $path);

        return \is_string($rewritten) ? $rewritten : $path;
    }

    /**
     * @param list<string> $failures
     */
    private function formatDiagnostic(
        string $routeName,
        string $method,
        string $uri,
        array $failures,
    ): string {
        $header = \sprintf('- %s [%s %s]', $routeName, $method, $uri);
        $bullets = [];

        foreach ($failures as $failure) {
            $bullets[] = '    ' . $failure;
        }

        return $header . "\n" . \implode("\n", $bullets);
    }

    private function renderHeader(?string $value): string
    {
        if (null === $value) {
            return '<absent>';
        }

        return "'" . $value . "'";
    }

    private function renderBodyValue(mixed $value): string
    {
        if (null === $value) {
            return '<absent>';
        }

        if (\is_string($value)) {
            return "'" . $value . "'";
        }

        return '<' . \get_debug_type($value) . '>';
    }

    private function loadSchemaRef(): stdClass
    {
        $schemaPath = __DIR__ . self::SCHEMA_FIXTURE;
        $this->assertFileExists(
            $schemaPath,
            'RFC 9457 schema fixture must be bundled at tests/Fixtures/Problem/rfc-9457.schema.json.',
        );

        $resolvedPath = \realpath($schemaPath);
        $this->assertNotFalse($resolvedPath);

        return (object) ['$ref' => 'file://' . $resolvedPath];
    }
}
