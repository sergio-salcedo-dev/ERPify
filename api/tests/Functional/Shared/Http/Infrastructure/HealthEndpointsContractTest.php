<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Shared\Http\Infrastructure;

use Erpify\Tests\Functional\AuthenticatesFunctionalRequests;
use JsonSchema\Validator as JsonSchemaValidator;
use PHPUnit\Framework\Attributes\CoversNothing;
use stdClass;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Proves the RFC 9457 Problem Details contract applies retroactively to the
 * two `/health` endpoints whose authors predate the contract. NO production
 * source was modified; the failure path is forced by a test-only event
 * subscriber wired in `api/config/services_test.yaml`
 * (see {@see EventListener\Fixtures\ForceHealthFailureSubscriber}).
 *
 * The two endpoints under test are wired by attribute routing in
 * `api/config/routes.yaml`:
 *   - Backoffice  → `GET /api/v1/backoffice/health`
 *   - Frontoffice → `GET /api/v1/health`
 *
 * What the suite pins:
 *   1. Failure path — adding `?_force_failure=1` causes the test subscriber to throw a
 *      plain `RuntimeException` BEFORE the controller runs. The listener pipeline maps
 *      it to the same default-deny `unhandled-exception` 500 already pinned for any
 *      unrecognised throwable. Body validates against the RFC 9457 schema
 *      (`tests/Fixtures/Problem/rfc-9457.schema.json`) and carries the
 *      `instance` + `correlation-id` UUIDv7 invariants.
 *   2. Happy path — without the query param, the existing 200 contract is unchanged
 *      (regression pin: same status, same shape, same `status`/`service` keys).
 *
 * If a future change made the health controllers catch their own probe failures and
 * emit a `JsonResponse` 500 instead of letting the listener handle it, the failure-path
 * tests here would fail (contract drift) and the `php.lint.error-contract`
 * gate would block the diff.
 *
 * The two paths differ in who may reach them, and that asymmetry is deliberate rather than
 * incidental: only the frontoffice route is exempt from the firewall, so the backoffice one is
 * driven with a seated session. Dropping that session would not weaken this suite quietly — it
 * would turn every backoffice assertion here into a 401 — which is what makes {@see clientFor}
 * the single place the exemption boundary is stated.
 *
 * @internal
 */
#[CoversNothing]
final class HealthEndpointsContractTest extends WebTestCase
{
    use AuthenticatesFunctionalRequests;

    private const string UUID_V7_REGEX = '/\A[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/';

    private const string SCHEMA_FIXTURE = '/../../../../Fixtures/Problem/rfc-9457.schema.json';

    private const string BACKOFFICE_HEALTH_PATH = '/api/v1/backoffice/health';

    private const string FRONTOFFICE_HEALTH_PATH = '/api/v1/health';

    public function testBackofficeHealthFailureProducesConformingProblemDetails(): void
    {
        $this->assertHealthFailurePathIsConformingProblemDetails(
            self::BACKOFFICE_HEALTH_PATH,
        );
    }

    public function testFrontofficeHealthFailureProducesConformingProblemDetails(): void
    {
        $this->assertHealthFailurePathIsConformingProblemDetails(
            self::FRONTOFFICE_HEALTH_PATH,
        );
    }

    public function testBackofficeHealthHappyPathIsUnchanged(): void
    {
        $this->assertHealthHappyPathIsUnchanged(
            self::BACKOFFICE_HEALTH_PATH,
            'Back office',
        );
    }

    public function testFrontofficeHealthHappyPathIsUnchanged(): void
    {
        $this->assertHealthHappyPathIsUnchanged(
            self::FRONTOFFICE_HEALTH_PATH,
            'Front office',
        );
    }

    /**
     * The frontoffice liveness route is the one `/api` path outside the firewall; the backoffice one
     * requires a session like the rest, so it is driven with one seated.
     */
    private function clientFor(string $path): KernelBrowser
    {
        $kernelBrowser = self::createClient();

        if (self::BACKOFFICE_HEALTH_PATH === $path) {
            $this->authenticateClient($kernelBrowser);
        }

        return $kernelBrowser;
    }

    private function assertHealthFailurePathIsConformingProblemDetails(string $path): void
    {
        $kernelBrowser = $this->clientFor($path);
        $kernelBrowser->catchExceptions(true);
        $kernelBrowser->request(Request::METHOD_GET, $path . '?_force_failure=1');

        $response = $kernelBrowser->getResponse();

        $this->assertSame(
            Response::HTTP_INTERNAL_SERVER_ERROR,
            $response->getStatusCode(),
            (string) $response->getContent(),
        );
        $this->assertSame('application/problem+json', $response->headers->get('Content-Type'));

        $cacheControl = $response->headers->get('Cache-Control');
        $this->assertNotNull($cacheControl);
        $this->assertStringContainsString('no-store', $cacheControl);

        $headerCorrelationId = $response->headers->get('X-Correlation-Id');
        $this->assertIsString($headerCorrelationId);
        $this->assertMatchesRegularExpression(self::UUID_V7_REGEX, $headerCorrelationId);

        $rawBody = (string) $response->getContent();
        $decodedAssoc = \json_decode($rawBody, true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($decodedAssoc);

        /** @var array<string, mixed> $body */
        $body = $decodedAssoc;

        // Schema validation against the bundled RFC 9457 fixture.
        $schemaRef = $this->loadSchemaRef();
        $decodedObject = \json_decode($rawBody, false, flags: JSON_THROW_ON_ERROR);
        $this->assertInstanceOf(stdClass::class, $decodedObject);

        $validator = new JsonSchemaValidator();
        $validator->validate($decodedObject, $schemaRef);
        $this->assertTrue(
            $validator->isValid(),
            \sprintf(
                'Body for %s must conform to RFC 9457 schema. Errors: %s',
                $path,
                (string) \json_encode($validator->getErrors()),
            ),
        );

        // Default-deny on unrecognised throwables — the test-only subscriber
        // throws a plain RuntimeException, which lands on the terminal `unhandled-exception`
        // / 500 branch.
        $this->assertSame('unhandled-exception', $body['type'] ?? null);
        $this->assertSame(500, $body['status'] ?? null);

        // `instance` and `correlation-id` carried as valid UUIDv7s; the body's
        // correlation-id matches the response header.
        $this->assertArrayHasKey('instance', $body);
        $this->assertIsString($body['instance']);
        $this->assertMatchesRegularExpression(self::UUID_V7_REGEX, $body['instance']);

        $this->assertArrayHasKey('correlation-id', $body);
        $this->assertIsString($body['correlation-id']);
        $this->assertMatchesRegularExpression(self::UUID_V7_REGEX, $body['correlation-id']);
        $this->assertSame($headerCorrelationId, $body['correlation-id']);
    }

    private function assertHealthHappyPathIsUnchanged(string $path, string $expectedService): void
    {
        $kernelBrowser = $this->clientFor($path);
        $kernelBrowser->catchExceptions(true);
        $kernelBrowser->request(Request::METHOD_GET, $path);

        $response = $kernelBrowser->getResponse();

        $this->assertSame(
            Response::HTTP_OK,
            $response->getStatusCode(),
            (string) $response->getContent(),
        );

        $rawBody = (string) $response->getContent();
        $decoded = \json_decode($rawBody, true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);

        // Regression pin: the pre-contract happy-path body shape MUST be unchanged after
        // wiring the test-only failure subscriber. The `JsonResponder` wraps the
        // controller's `Result::ok([...])` payload under a top-level `data` envelope —
        // exactly what callers depend on today. `datetime` is the only volatile field;
        // pin its shape as an ISO 8601 ATOM string (e.g. `2026-05-08T12:34:56+00:00`).
        /** @var array<string, mixed> $body */
        $body = $decoded;
        $this->assertArrayHasKey('data', $body);
        $data = $body['data'];
        $this->assertIsArray($data);

        /** @var array<string, mixed> $data */
        $this->assertSame(
            ['status', 'service', 'datetime'],
            \array_keys($data),
            'Health envelope `data` keys must be unchanged.',
        );
        $this->assertSame('ok', $data['status'] ?? null);
        $this->assertSame($expectedService, $data['service'] ?? null);
        $this->assertArrayHasKey('datetime', $data);
        $datetime = $data['datetime'];
        $this->assertIsString($datetime);
        $this->assertMatchesRegularExpression(
            '/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}\z/',
            $datetime,
        );
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
