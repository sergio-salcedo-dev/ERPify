<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Infrastructure\Http\Responder;

use Erpify\Shared\Application\UseCase\Result;
use Erpify\Shared\Infrastructure\Http\Responder\JsonResponder;
use JsonException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * @internal
 */
#[CoversClass(JsonResponder::class)]
final class JsonResponderTest extends TestCase
{
    /** @throws JsonException */
    public function testOkResultBecomesJsonResponseWithDataKey(): void
    {
        $response = (new JsonResponder())->respond(Result::ok(['status' => 'ok']));

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());
        $this->assertSame(
            ['data' => ['status' => 'ok']],
            \json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR),
        );
    }

    public function testNoContentProducesEmpty204Body(): void
    {
        $response = (new JsonResponder())->respond(Result::noContent());

        $this->assertSame(Response::HTTP_NO_CONTENT, $response->getStatusCode(), (string) $response->getContent());
        $this->assertSame('', (string) $response->getContent());
    }

    /** @throws JsonException */
    public function testCreatedResultPropagates201(): void
    {
        $response = (new JsonResponder())->respond(Result::created(['id' => 'abc']));

        $this->assertSame(Response::HTTP_CREATED, $response->getStatusCode(), (string) $response->getContent());
        $this->assertSame(
            ['data' => ['id' => 'abc']],
            \json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR),
        );
    }
}
