<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Infrastructure\Http;

use Erpify\Shared\Application\Problem\ProblemDetails;
use Erpify\Shared\Infrastructure\Http\ProblemDetailsResponder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(ProblemDetailsResponder::class)]
final class ProblemDetailsResponderTest extends TestCase
{
    private const string INSTANCE = '0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1a2c';

    private const string CORRELATION_ID = '0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1a2c';

    public function testStatusCodeMatchesProblemDetailsStatus(): void
    {
        $problemDetails = $this->makeProblemDetails(status: 404, type: 'not-found', title: 'Bank not found');

        $response = (new ProblemDetailsResponder())->respond($problemDetails);

        $this->assertSame(
            \Symfony\Component\HttpFoundation\Response::HTTP_NOT_FOUND,
            $response->getStatusCode(),
            (string) $response->getContent(),
        );
    }

    public function testContentTypeHeaderIsExactProblemJsonWithNoCharsetSuffix(): void
    {
        $problemDetails = $this->makeProblemDetails(status: 500);

        $response = (new ProblemDetailsResponder())->respond($problemDetails);

        $this->assertSame('application/problem+json', $response->headers->get('Content-Type'));
    }

    public function testCacheControlHeaderCarriesNoStoreDirective(): void
    {
        $problemDetails = $this->makeProblemDetails(status: 500);

        $response = (new ProblemDetailsResponder())->respond($problemDetails);

        // Symfony's ResponseHeaderBag::computeCacheControlValue() appends `, private` when neither
        // `public` nor `s-maxage` is explicitly set. The response must carry `no-store`;
        // RFC 7234 § 5.2.2.5 makes `no-store` strictly subsume `private`, so the extra directive is
        // semantically redundant. Assert containment of the load-bearing directive.
        $cacheControl = $response->headers->get('Cache-Control');
        $this->assertNotNull($cacheControl);
        $this->assertStringContainsString('no-store', $cacheControl);
    }

    public function testBodyIsJsonEncodedToArrayOutput(): void
    {
        $problemDetails = $this->makeProblemDetails(status: 422, type: 'validation-failed', title: 'Validation failed');

        $response = (new ProblemDetailsResponder())->respond($problemDetails);

        $decoded = \json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame($problemDetails->toArray(), $decoded);
    }

    public function testBodyEncodesUtf8LiterallyWithJsonUnescapedUnicode(): void
    {
        $problemDetails = new ProblemDetails(
            type: 'not-found',
            title: 'Não encontrado — 資源未找到',
            status: 404,
            detail: null,
            instance: self::INSTANCE,
            correlationId: self::CORRELATION_ID,
        );

        $response = (new ProblemDetailsResponder())->respond($problemDetails);

        $body = (string) $response->getContent();

        $this->assertStringContainsString('Não encontrado — 資源未找到', $body);
        $this->assertStringNotContainsString(
            '\u',
            $body,
            'JSON_UNESCAPED_UNICODE must emit literal UTF-8 (no \uXXXX escapes).',
        );
    }

    public function testBodyIsByteForByteStableAcrossRepeatedCalls(): void
    {
        $problemDetails = $this->makeProblemDetails(status: 409, type: 'conflict', title: 'Already exists');

        $problemDetailsResponder = new ProblemDetailsResponder();
        $first = (string) $problemDetailsResponder->respond($problemDetails)->getContent();
        $second = (string) $problemDetailsResponder->respond($problemDetails)->getContent();

        $this->assertSame($first, $second);
    }

    public function testReturnsIndependentResponseInstances(): void
    {
        $problemDetails = $this->makeProblemDetails(status: 404, type: 'not-found', title: 'a');
        $second = $this->makeProblemDetails(status: 409, type: 'conflict', title: 'b');

        $problemDetailsResponder = new ProblemDetailsResponder();

        $firstResponse = $problemDetailsResponder->respond($problemDetails);
        $secondResponse = $problemDetailsResponder->respond($second);

        $this->assertNotSame($firstResponse, $secondResponse, 'The responder must build a fresh Response per call.');
        $this->assertSame(
            \Symfony\Component\HttpFoundation\Response::HTTP_NOT_FOUND,
            $firstResponse->getStatusCode(),
            (string) $firstResponse->getContent(),
        );
        $this->assertSame(
            \Symfony\Component\HttpFoundation\Response::HTTP_CONFLICT,
            $secondResponse->getStatusCode(),
            (string) $secondResponse->getContent(),
        );
    }

    public function testExtensionsFlowThroughInDeterministicOrder(): void
    {
        $problemDetails = new ProblemDetails(
            type: 'validation-failed',
            title: 'Validation failed',
            status: 422,
            detail: null,
            instance: self::INSTANCE,
            correlationId: self::CORRELATION_ID,
            extensions: [
                'violations' => [
                    ['field' => 'name', 'message' => 'NotBlank', 'code' => 'IS_BLANK'],
                ],
                'truncated' => false,
            ],
        );

        $response = (new ProblemDetailsResponder())->respond($problemDetails);

        $decoded = \json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);

        $this->assertSame(
            ['type', 'title', 'status', 'instance', 'correlation-id', 'violations', 'truncated'],
            \array_keys($decoded),
        );
    }

    public function testSourceFileContainsNoBannedImports(): void
    {
        $sourcePath = \dirname(__DIR__, 5) . '/src/Shared/Infrastructure/Http/ProblemDetailsResponder.php';
        $this->assertFileExists($sourcePath);

        $contents = \file_get_contents($sourcePath);
        $this->assertNotFalse($contents);

        $banned = [
            'use Erpify\Shared\Kernel\Application\\',
            'use Erpify\Shared\Infrastructure\Http\Responder\ResponderInterface',
            'use Symfony\Component\HttpFoundation\JsonResponse',
            'use Doctrine\\',
            'use Symfony\Component\Messenger\\',
        ];

        foreach ($banned as $needle) {
            $this->assertStringNotContainsString(
                $needle,
                $contents,
                \sprintf('ProblemDetailsResponder.php must not contain "%s".', $needle),
            );
        }
    }

    private function makeProblemDetails(
        int $status,
        string $type = 'about:blank',
        string $title = 'Generic title',
    ): ProblemDetails {
        return new ProblemDetails(
            type: $type,
            title: $title,
            status: $status,
            detail: null,
            instance: self::INSTANCE,
            correlationId: self::CORRELATION_ID,
        );
    }
}
